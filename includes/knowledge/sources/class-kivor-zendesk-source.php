<?php
/**
 * Zendesk Help Center source.
 *
 * @package KivorAgent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class Kivor_Zendesk_Source implements Kivor_Knowledge_Source_Interface {

	/**
	 * Zendesk subdomain (or full host).
	 *
	 * @var string
	 */
	private string $subdomain;

	/**
	 * Account email for token auth.
	 *
	 * @var string
	 */
	private string $email;

	/**
	 * API token.
	 *
	 * @var string
	 */
	private string $api_token;

	/**
	 * Constructor.
	 *
	 * @param array $config Source config.
	 */
	public function __construct( array $config ) {
		$this->subdomain = sanitize_text_field( (string) ( $config['subdomain'] ?? '' ) );
		$this->email     = sanitize_email( (string) ( $config['email'] ?? '' ) );
		$this->api_token = sanitize_text_field( (string) ( $config['api_token'] ?? '' ) );
	}

	/**
	 * Test source credentials/connectivity.
	 *
	 * @return true|WP_Error
	 */
	public function test_connection() {
		$articles = $this->request_articles( 1, null );

		if ( is_wp_error( $articles ) ) {
			return $articles;
		}

		return true;
	}

	/**
	 * Fetch normalized articles from the source.
	 *
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function fetch_articles() {
		$all   = array();
		$page  = null;
		$loops = 0;

		do {
			$result = $this->request_articles( 100, $page );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			foreach ( $result['items'] as $item ) {
				$all[] = $this->normalize_article( $item );
			}

			$page = $result['next_page'];
			$loops++;
		} while ( ! empty( $page ) && $loops < 50 );

		return $all;
	}

	/**
	 * Build help center host.
	 *
	 * @return string
	 */
	private function get_host(): string {
		$raw = trim( $this->subdomain );

		if ( false !== strpos( $raw, '://' ) ) {
			$host = wp_parse_url( $raw, PHP_URL_HOST );
			return $host ? $host : '';
		}

		if ( false !== strpos( $raw, '.' ) ) {
			return $raw;
		}

		return $raw . '.zendesk.com';
	}

	/**
	 * Request articles page.
	 *
	 * @param int         $page_size Page size.
	 * @param string|null $cursor    Cursor.
	 * @return array|WP_Error
	 */
	private function request_articles( int $page_size, ?string $cursor ) {
		$host = $this->get_host();

		if ( empty( $host ) || empty( $this->email ) || empty( $this->api_token ) ) {
			return new \WP_Error(
				'kivor_chat_agent_zendesk_invalid_config',
				__( 'Zendesk configuration is incomplete.', 'kivor-chat-agent' )
			);
		}

		$url = 'https://' . $host . '/api/v2/help_center/articles.json?page[size]=' . max( 1, min( 100, $page_size ) );
		if ( ! empty( $cursor ) ) {
			$url .= '&page[after]=' . rawurlencode( $cursor );
		}

		$auth = base64_encode( $this->email . '/token:' . $this->api_token );

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Basic ' . $auth,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		$data   = json_decode( $body, true );

		if ( $status < 200 || $status >= 300 ) {
			return new \WP_Error(
				'kivor_chat_agent_zendesk_http_error',
				sprintf(
					/* translators: 1: status code */
					__( 'Zendesk API returned HTTP %1$d.', 'kivor-chat-agent' ),
					$status
				)
			);
		}

		if ( ! is_array( $data ) ) {
			return new \WP_Error(
				'kivor_chat_agent_zendesk_invalid_response',
				__( 'Zendesk API returned an invalid response.', 'kivor-chat-agent' )
			);
		}

		$items = isset( $data['articles'] ) && is_array( $data['articles'] ) ? $data['articles'] : array();

		$next_cursor = null;
		if ( ! empty( $data['meta']['has_more'] ) && ! empty( $data['meta']['after_cursor'] ) ) {
			$next_cursor = (string) $data['meta']['after_cursor'];
		}

		return array(
			'items'     => $items,
			'next_page' => $next_cursor,
		);
	}

	/**
	 * Normalize Zendesk article.
	 *
	 * @param array $item Raw article.
	 * @return array<string, mixed>
	 */
	private function normalize_article( array $item ): array {
		$source_id = (string) ( $item['id'] ?? '' );
		$title     = sanitize_text_field( (string) ( $item['title'] ?? '' ) );
		$body      = (string) ( $item['body'] ?? '' );
		$html_url  = esc_url_raw( (string) ( $item['html_url'] ?? '' ) );

		$content = wp_strip_all_tags( html_entity_decode( $body, ENT_QUOTES, 'UTF-8' ) );
		$content = trim( preg_replace( '/\s+/', ' ', $content ) );

		return array(
			'source_type' => 'zendesk',
			'source_id'   => $source_id,
			'title'       => $title,
			'content'     => $content,
			'source_url'  => $html_url,
		);
	}
}
