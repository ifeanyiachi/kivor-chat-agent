<?php
/**
 * WordPress content sync helper.
 *
 * @package KivorAgent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class Kivor_WP_Content_Sync {

	/**
	 * Settings instance.
	 *
	 * @var Kivor_Settings
	 */
	private Kivor_Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Kivor_Settings $settings Plugin settings.
	 */
	public function __construct( Kivor_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Fetch all enabled WordPress content as normalized KB rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function fetch_all_articles(): array {
		$post_types = $this->get_enabled_post_types();

		if ( empty( $post_types ) ) {
			return array();
		}

		$query = new \WP_Query(
			array(
				'post_type'              => $post_types,
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$items = array();
		foreach ( $query->posts as $post_id ) {
			$article = $this->normalize_post( (int) $post_id );
			if ( ! empty( $article ) ) {
				$items[] = $article;
			}
		}

		return $items;
	}

	/**
	 * Fetch one post/page as a normalized KB row.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>|null
	 */
	public function fetch_single_article( int $post_id ): ?array {
		if ( $post_id <= 0 ) {
			return null;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		if ( 'publish' !== $post->post_status ) {
			return null;
		}

		if ( ! in_array( $post->post_type, $this->get_enabled_post_types(), true ) ) {
			return null;
		}

		return $this->normalize_post( $post_id );
	}

	/**
	 * Get enabled WP content post types.
	 *
	 * @return array<int, string>
	 */
	public function get_enabled_post_types(): array {
		$cfg = $this->settings->get( 'external_platforms.wordpress', array() );

		if ( empty( $cfg['enabled'] ) ) {
			return array();
		}

		$types = array();
		if ( ! empty( $cfg['posts_enabled'] ) ) {
			$types[] = 'post';
		}
		if ( ! empty( $cfg['pages_enabled'] ) ) {
			$types[] = 'page';
		}

		return $types;
	}

	/**
	 * Normalize a WP post/page.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>|null
	 */
	private function normalize_post( int $post_id ): ?array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		$title = get_the_title( $post_id );
		if ( '' === trim( (string) $title ) ) {
			$title = __( 'Untitled', 'kivor-chat-agent' );
		}

		$content = (string) $post->post_content;
		$content = html_entity_decode( $content, ENT_QUOTES, 'UTF-8' );
		$content = wp_strip_all_tags( $content );
		$content = trim( preg_replace( '/\s+/', ' ', $content ) );

		return array(
			'source_type' => 'page' === $post->post_type ? 'wp_page' : 'wp_post',
			'source_id'   => (string) $post_id,
			'title'       => sanitize_text_field( (string) $title ),
			'content'     => $content,
			'source_url'  => esc_url_raw( get_permalink( $post_id ) ),
		);
	}
}
