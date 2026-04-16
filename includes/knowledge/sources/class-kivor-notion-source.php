<?php
/**
 * Notion database source.
 *
 * @package KivorAgent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class Kivor_Notion_Source implements Kivor_Knowledge_Source_Interface {

	/**
	 * API key.
	 *
	 * @var string
	 */
	private string $api_key;

	/**
	 * Database ID.
	 *
	 * @var string
	 */
	private string $database_id;

	/**
	 * Constructor.
	 *
	 * @param array $config Source config.
	 */
	public function __construct( array $config ) {
		$this->api_key     = sanitize_text_field( (string) ( $config['api_key'] ?? '' ) );
		$this->database_id = sanitize_text_field( (string) ( $config['database_id'] ?? '' ) );
	}

	/**
	 * @return true|WP_Error
	 */
	public function test_connection() {
		$result = $this->request_query( null, 1 );
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function fetch_articles() {
		$all    = array();
		$cursor = null;
		$loops  = 0;

		do {
			$result = $this->request_query( $cursor, 100 );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			foreach ( $result['items'] as $item ) {
				$normalized = $this->normalize_row( $item );
				if ( ! empty( $normalized['title'] ) || ! empty( $normalized['content'] ) ) {
					$all[] = $normalized;
				}
			}

			$cursor = $result['next_page'];
			$loops++;
		} while ( ! empty( $cursor ) && $loops < 50 );

		return $all;
	}

	/**
	 * Query Notion database.
	 *
	 * @param string|null $start_cursor Cursor.
	 * @param int         $page_size    Page size.
	 * @return array|WP_Error
	 */
	private function request_query( ?string $start_cursor, int $page_size ) {
		if ( empty( $this->api_key ) || empty( $this->database_id ) ) {
			return new \WP_Error(
				'kivor_chat_agent_notion_invalid_config',
				__( 'Notion API key and Database ID are required.', 'kivor-chat-agent' )
			);
		}

		$body = array(
			'page_size' => max( 1, min( 100, $page_size ) ),
		);

		if ( ! empty( $start_cursor ) ) {
			$body['start_cursor'] = $start_cursor;
		}

		$url = 'https://api.notion.com/v1/databases/' . rawurlencode( $this->database_id ) . '/query';

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization'  => 'Bearer ' . $this->api_key,
					'Notion-Version' => '2022-06-28',
					'Content-Type'   => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 ) {
			return new \WP_Error(
				'kivor_chat_agent_notion_http_error',
				sprintf(
					/* translators: 1: status code */
					__( 'Notion API returned HTTP %1$d.', 'kivor-chat-agent' ),
					$status
				)
			);
		}

		if ( ! is_array( $data ) ) {
			return new \WP_Error(
				'kivor_chat_agent_notion_invalid_response',
				__( 'Notion API returned an invalid response.', 'kivor-chat-agent' )
			);
		}

		$items = isset( $data['results'] ) && is_array( $data['results'] ) ? $data['results'] : array();

		return array(
			'items'     => $items,
			'next_page' => ! empty( $data['has_more'] ) ? (string) ( $data['next_cursor'] ?? '' ) : null,
		);
	}

	/**
	 * Normalize Notion row.
	 *
	 * @param array $row Raw row.
	 * @return array<string, mixed>
	 */
	private function normalize_row( array $row ): array {
		$source_id = (string) ( $row['id'] ?? '' );
		$url       = esc_url_raw( (string) ( $row['url'] ?? '' ) );
		$fallback_name = '';

		if ( isset( $row['object'] ) && 'page' !== (string) $row['object'] ) {
			$fallback_name = sanitize_text_field( ucfirst( (string) $row['object'] ) );
		}

		$properties = isset( $row['properties'] ) && is_array( $row['properties'] ) ? $row['properties'] : array();
		$title      = '';
		$content    = array();

		foreach ( $properties as $property ) {
			if ( ! is_array( $property ) || empty( $property['type'] ) ) {
				continue;
			}

			$type = (string) $property['type'];

			if ( 'title' === $type && isset( $property['title'] ) && is_array( $property['title'] ) ) {
				$title = $this->extract_rich_text( $property['title'] );
				continue;
			}

			if ( 'rich_text' === $type && isset( $property['rich_text'] ) && is_array( $property['rich_text'] ) ) {
				$value = $this->extract_rich_text( $property['rich_text'] );
				if ( '' !== $value ) {
					$content[] = $value;
				}
				continue;
			}

			if ( 'url' === $type && ! empty( $property['url'] ) && empty( $url ) ) {
				$url = esc_url_raw( (string) $property['url'] );
				continue;
			}

			if ( 'number' === $type && isset( $property['number'] ) ) {
				$content[] = (string) $property['number'];
				continue;
			}

			if ( 'checkbox' === $type && isset( $property['checkbox'] ) ) {
				$content[] = ! empty( $property['checkbox'] ) ? 'true' : 'false';
				continue;
			}

			if ( 'select' === $type && ! empty( $property['select']['name'] ) ) {
				$content[] = sanitize_text_field( (string) $property['select']['name'] );
				continue;
			}

			if ( 'multi_select' === $type && ! empty( $property['multi_select'] ) && is_array( $property['multi_select'] ) ) {
				$labels = array();
				foreach ( $property['multi_select'] as $entry ) {
					if ( is_array( $entry ) && ! empty( $entry['name'] ) ) {
						$labels[] = sanitize_text_field( (string) $entry['name'] );
					}
				}
				if ( ! empty( $labels ) ) {
					$content[] = implode( ', ', $labels );
				}
				continue;
			}

			if ( 'status' === $type && ! empty( $property['status']['name'] ) ) {
				$content[] = sanitize_text_field( (string) $property['status']['name'] );
				continue;
			}

			if ( 'date' === $type && ! empty( $property['date']['start'] ) ) {
				$content[] = sanitize_text_field( (string) $property['date']['start'] );
			}
		}

		if ( '' === $title ) {
			$prefix = '' !== $fallback_name ? $fallback_name : __( 'Notion Article', 'kivor-chat-agent' );
			$title = $prefix . ' ' . mb_substr( $source_id, 0, 8, 'UTF-8' );
		}

		return array(
			'source_type' => 'notion',
			'source_id'   => $source_id,
			'title'       => sanitize_text_field( $title ),
			'content'     => sanitize_textarea_field( implode( "\n\n", $content ) ),
			'source_url'  => $url,
		);
	}

	/**
	 * Extract text from Notion rich text array.
	 *
	 * @param array $parts Rich text parts.
	 * @return string
	 */
	private function extract_rich_text( array $parts ): string {
		$chunks = array();

		foreach ( $parts as $part ) {
			if ( is_array( $part ) && isset( $part['plain_text'] ) ) {
				$chunks[] = (string) $part['plain_text'];
			}
		}

		return trim( implode( '', $chunks ) );
	}
}
