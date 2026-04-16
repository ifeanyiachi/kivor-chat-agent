<?php
/**
 * Knowledge import manager.
 *
 * Handles source scans, background imports, status polling,
 * and retry/manual review queue tracking.
 *
 * @package KivorAgent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class Kivor_Knowledge_Import_Manager {

	/**
	 * Settings.
	 *
	 * @var Kivor_Settings
	 */
	private Kivor_Settings $settings;

	/**
	 * KB service.
	 *
	 * @var Kivor_Knowledge_Base
	 */
	private Kivor_Knowledge_Base $kb;

	/**
	 * Constructor.
	 *
	 * @param Kivor_Settings       $settings Settings.
	 * @param Kivor_Knowledge_Base $kb       KB service.
	 */
	public function __construct( Kivor_Settings $settings, Kivor_Knowledge_Base $kb ) {
		$this->settings = $settings;
		$this->kb       = $kb;
	}

	/**
	 * Scan a source and return normalized selectable items.
	 *
	 * @param string $source_type Source type.
	 * @param array  $params      Source params.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function scan_source( string $source_type, array $params = array() ) {
		$source_type = sanitize_key( $source_type );

		$articles = $this->fetch_source_articles( $source_type, $params );
		if ( is_wp_error( $articles ) ) {
			return $articles;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'kivor_knowledge_base';

		$items = array();
		foreach ( $articles as $article ) {
			if ( ! is_array( $article ) ) {
				continue;
			}

			$normalized = $this->sanitize_external_article( $article );
			if ( empty( $normalized['source_type'] ) || empty( $normalized['source_id'] ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
			$existing = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, title, content, source_url FROM {$table} WHERE source_type = %s AND source_id = %s LIMIT 1",
					$normalized['source_type'],
					$normalized['source_id']
				),
				ARRAY_A
			);

			$imported = ! empty( $existing );
			$new_data = false;
			if ( $imported ) {
				$new_data = (string) ( $existing['title'] ?? '' ) !== (string) $normalized['title']
					|| (string) ( $existing['content'] ?? '' ) !== (string) $normalized['content']
					|| (string) ( $existing['source_url'] ?? '' ) !== (string) $normalized['source_url'];
			}

			$items[] = array(
				'source_type' => $normalized['source_type'],
				'source_id'   => $normalized['source_id'],
				'title'       => $normalized['title'],
				'content'     => $normalized['content'],
				'source_url'  => $normalized['source_url'],
				'imported'    => $imported,
				'new_data'    => $new_data,
			);
		}

		return $items;
	}

	/**
	 * Start background import job.
	 *
	 * @param string $source_type   Source type.
	 * @param array  $articles      Normalized items.
	 * @param string $import_method  Import mode.
	 * @param string $sync_interval  Sync interval.
	 * @param bool   $allow_overwrite Allow overwriting changed imported rows.
	 * @return array<string, mixed>|WP_Error
	 */
	public function start_import_job( string $source_type, array $articles, string $import_method = 'individual', string $sync_interval = 'manual', bool $allow_overwrite = false ) {
		$source_type   = sanitize_key( $source_type );
		$import_method = in_array( $import_method, array( 'individual', 'bulk', 'manual' ), true ) ? $import_method : 'individual';
		$sync_interval = sanitize_key( $sync_interval );

		$queue = array();
		$needs_confirmation = 0;
		foreach ( $articles as $article ) {
			if ( ! is_array( $article ) ) {
				continue;
			}

			$normalized = $this->sanitize_external_article( $article );
			if ( empty( $normalized['source_id'] ) || empty( $normalized['title'] ) || empty( trim( wp_strip_all_tags( (string) $normalized['content'] ) ) ) ) {
				continue;
			}

			if ( '' === (string) $normalized['source_type'] ) {
				$normalized['source_type'] = $source_type;
			}

			if ( $this->requires_overwrite_confirmation( $normalized ) && ! $allow_overwrite ) {
				$needs_confirmation++;
				continue;
			}

			$queue[]                   = $normalized;
		}

		if ( $needs_confirmation > 0 && ! $allow_overwrite ) {
			return new \WP_Error(
				'kivor_chat_agent_kb_overwrite_confirmation_required',
				__( 'One or more selected items have new remote changes. Confirm overwrite before importing.', 'kivor-chat-agent' )
			);
		}

		if ( empty( $queue ) ) {

			return new \WP_Error(
				'kivor_chat_agent_no_import_items',
				__( 'No valid knowledge items selected for import.', 'kivor-chat-agent' )
			);
		}

		$job_id = 'kb_' . wp_generate_uuid4();
		$job    = array(
			'id'            => $job_id,
			'source_type'   => $source_type,
			'status'        => 'queued',
			'total'         => count( $queue ),
			'processed'     => 0,
			'success'       => 0,
			'failed'        => 0,
			'max_retries'   => 3,
			'import_method' => $import_method,
			'sync_interval' => $sync_interval,
			'items'         => $queue,
			'errors'        => array(),
			'created_at'    => gmdate( 'c' ),
			'updated_at'    => gmdate( 'c' ),
		);

		set_transient( $this->get_job_transient_key( $job_id ), $job, 6 * HOUR_IN_SECONDS );

		if ( false === wp_next_scheduled( 'kivor_chat_agent_process_kb_import_job', array( $job_id ) ) ) {
			wp_schedule_single_event( time() + 2, 'kivor_chat_agent_process_kb_import_job', array( $job_id ) );
		}

		return array(
			'job_id'  => $job_id,
			'total'   => $job['total'],
			'status'  => $job['status'],
			'skipped_needs_confirmation' => $needs_confirmation,
			'message' => __( 'Knowledge import started in background.', 'kivor-chat-agent' ),
		);
	}

	/**
	 * Determine if an item overwrites changed imported content.
	 *
	 * @param array $article Normalized article.
	 * @return bool
	 */
	private function requires_overwrite_confirmation( array $article ): bool {
		$source_type = sanitize_key( (string) ( $article['source_type'] ?? '' ) );
		$source_id   = sanitize_text_field( (string) ( $article['source_id'] ?? '' ) );

		if ( '' === $source_type || '' === $source_id ) {
			return false;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'kivor_knowledge_base';

		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, title, content, source_url FROM {$table} WHERE source_type = %s AND source_id = %s LIMIT 1",
				$source_type,
				$source_id
			),
			ARRAY_A
		);

		if ( empty( $existing ) ) {
			return false;
		}

		return (string) ( $existing['title'] ?? '' ) !== (string) ( $article['title'] ?? '' )
			|| (string) ( $existing['content'] ?? '' ) !== (string) ( $article['content'] ?? '' )
			|| (string) ( $existing['source_url'] ?? '' ) !== (string) ( $article['source_url'] ?? '' );
	}

	/**
	 * Process an import job.
	 *
	 * @param string $job_id Job id.
	 * @return void
	 */
	public function process_import_job( string $job_id ): void {
		$job = $this->get_import_job( $job_id, true );
		if ( ! is_array( $job ) || empty( $job['items'] ) ) {
			return;
		}

		if ( in_array( $job['status'], array( 'completed', 'failed' ), true ) ) {
			return;
		}

		$job['status']     = 'processing';
		$job['updated_at'] = gmdate( 'c' );
		set_transient( $this->get_job_transient_key( $job_id ), $job, 6 * HOUR_IN_SECONDS );

		$max_retries = max( 1, (int) ( $job['max_retries'] ?? 3 ) );
		$meta        = array(
			'import_method' => (string) ( $job['import_method'] ?? 'individual' ),
			'sync_interval' => (string) ( $job['sync_interval'] ?? 'manual' ),
		);

		foreach ( $job['items'] as $item ) {
			$ok = false;
			for ( $attempt = 1; $attempt <= $max_retries; $attempt++ ) {
				$result = $this->kb->upsert_external_articles( array( $item ), true, $meta );
				if ( is_wp_error( $result ) ) {
					continue;
				}

				if ( (int) ( $result['errors'] ?? 0 ) === 0 ) {
					$ok = true;
					break;
				}
			}

			$job['processed'] = (int) $job['processed'] + 1;
			if ( $ok ) {
				$job['success'] = (int) $job['success'] + 1;
				continue;
			}

			$job['failed'] = (int) $job['failed'] + 1;
			$job['errors'][] = array(
				'source_type' => (string) ( $item['source_type'] ?? '' ),
				'source_id'   => (string) ( $item['source_id'] ?? '' ),
				'title'       => (string) ( $item['title'] ?? '' ),
				'retries'     => $max_retries,
				'queued_at'   => gmdate( 'c' ),
			);

			$this->push_manual_review_item( $item, $max_retries );
			$this->mark_article_failed( (string) ( $item['source_type'] ?? '' ), (string) ( $item['source_id'] ?? '' ), $max_retries );
		}

		$job['status']     = ( (int) $job['failed'] > 0 ) ? 'completed_with_errors' : 'completed';
		$job['updated_at'] = gmdate( 'c' );
		set_transient( $this->get_job_transient_key( $job_id ), $job, 6 * HOUR_IN_SECONDS );
	}

	/**
	 * Get import job status.
	 *
	 * @param string $job_id         Job id.
	 * @param bool   $include_items  Include queued items.
	 * @return array<string, mixed>|null
	 */
	public function get_import_job( string $job_id, bool $include_items = false ): ?array {
		$job_id = sanitize_text_field( $job_id );
		if ( '' === $job_id ) {
			return null;
		}

		$job = get_transient( $this->get_job_transient_key( $job_id ) );
		if ( ! is_array( $job ) ) {
			return null;
		}

		if ( ! $include_items ) {
			unset( $job['items'] );
		}

		return $job;
	}

	/**
	 * Get manual review queue.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_manual_review_queue(): array {
		$queue = get_option( 'kivor_chat_agent_kb_manual_review_queue', array() );
		return is_array( $queue ) ? array_values( $queue ) : array();
	}

	/**
	 * Fetch source articles.
	 *
	 * @param string $source_type Source type.
	 * @param array  $params      Params.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	private function fetch_source_articles( string $source_type, array $params ) {
		switch ( $source_type ) {
			case 'wordpress_posts':
				return $this->fetch_wordpress_articles( 'post', 'wp_post' );

			case 'wordpress_pages':
				return $this->fetch_wordpress_articles( 'page', 'wp_page' );

			case 'zendesk':
				$saved = $this->settings->get( 'external_platforms.zendesk', array() );
				if ( ! is_array( $saved ) ) {
					$saved = array();
				}
				$override_subdomain = sanitize_text_field( (string) ( $params['subdomain'] ?? '' ) );
				$override_email     = sanitize_email( (string) ( $params['email'] ?? '' ) );
				$override_token     = sanitize_text_field( (string) ( $params['api_token'] ?? '' ) );

				$config = array_merge( $saved, array(
					'subdomain' => '' !== $override_subdomain ? $override_subdomain : (string) ( $saved['subdomain'] ?? '' ),
					'email'     => '' !== $override_email ? $override_email : (string) ( $saved['email'] ?? '' ),
					'api_token' => '' !== $override_token ? $override_token : (string) ( $saved['api_token'] ?? '' ),
				) );
				$source = new Kivor_Zendesk_Source( $config );
				return $source->fetch_articles();

			case 'notion':
				$saved = $this->settings->get( 'external_platforms.notion', array() );
				if ( ! is_array( $saved ) ) {
					$saved = array();
				}
				$override_api_key     = sanitize_text_field( (string) ( $params['api_key'] ?? '' ) );
				$override_database_id = sanitize_text_field( (string) ( $params['database_id'] ?? '' ) );

				$config = array_merge( $saved, array(
					'api_key'     => '' !== $override_api_key ? $override_api_key : (string) ( $saved['api_key'] ?? '' ),
					'database_id' => '' !== $override_database_id ? $override_database_id : (string) ( $saved['database_id'] ?? '' ),
				) );
				$source = new Kivor_Notion_Source( $config );
				return $source->fetch_articles();

		}

		return new \WP_Error(
			'kivor_chat_agent_unsupported_source',
			__( 'Unsupported knowledge source.', 'kivor-chat-agent' )
		);
	}

	/**
	 * Fetch WordPress posts/pages as normalized rows.
	 *
	 * @param string $post_type    Post type.
	 * @param string $source_type  Source type for KB.
	 * @return array<int, array<string, mixed>>
	 */
	private function fetch_wordpress_articles( string $post_type, string $source_type ): array {
		$query = new \WP_Query(
			array(
				'post_type'              => $post_type,
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
			$post = get_post( (int) $post_id );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$title = get_the_title( (int) $post_id );
			if ( '' === trim( (string) $title ) ) {
				$title = __( 'Untitled', 'kivor-chat-agent' );
			}

			$content = html_entity_decode( (string) $post->post_content, ENT_QUOTES, 'UTF-8' );
			$content = wp_strip_all_tags( $content );
			$content = trim( preg_replace( '/\s+/', ' ', $content ) );

			$items[] = array(
				'source_type' => $source_type,
				'source_id'   => (string) $post_id,
				'title'       => sanitize_text_field( (string) $title ),
				'content'     => $content,
				'source_url'  => esc_url_raw( get_permalink( (int) $post_id ) ),
			);
		}

		return $items;
	}

	/**
	 * Sanitize external article payload.
	 *
	 * @param array $article Input article.
	 * @return array<string, mixed>
	 */
	private function sanitize_external_article( array $article ): array {
		return array(
			'source_type' => sanitize_key( (string) ( $article['source_type'] ?? '' ) ),
			'source_id'   => sanitize_text_field( (string) ( $article['source_id'] ?? '' ) ),
			'title'       => sanitize_text_field( (string) ( $article['title'] ?? '' ) ),
			'content'     => Kivor_Sanitizer::sanitize_kb_content( (string) ( $article['content'] ?? '' ), 5000 ),
			'source_url'  => esc_url_raw( (string) ( $article['source_url'] ?? '' ) ),
		);
	}

	/**
	 * Add manual review queue item.
	 *
	 * @param array $item        Item payload.
	 * @param int   $retry_count Retries.
	 * @return void
	 */
	private function push_manual_review_item( array $item, int $retry_count ): void {
		$queue = $this->get_manual_review_queue();

		$queue[] = array(
			'source_type' => sanitize_key( (string) ( $item['source_type'] ?? '' ) ),
			'source_id'   => sanitize_text_field( (string) ( $item['source_id'] ?? '' ) ),
			'title'       => sanitize_text_field( (string) ( $item['title'] ?? '' ) ),
			'retry_count' => max( 0, $retry_count ),
			'queued_at'   => gmdate( 'c' ),
		);

		update_option( 'kivor_chat_agent_kb_manual_review_queue', $queue, false );
	}

	/**
	 * Mark existing article as failed.
	 *
	 * @param string $source_type Source type.
	 * @param string $source_id   Source id.
	 * @param int    $retry_count Retry count.
	 * @return void
	 */
	private function mark_article_failed( string $source_type, string $source_id, int $retry_count ): void {
		$source_type = sanitize_key( $source_type );
		$source_id   = sanitize_text_field( $source_id );

		if ( '' === $source_type || '' === $source_id ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'kivor_knowledge_base';

		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$wpdb->update(
			$table,
			array(
				'sync_status' => 'failed',
				'retry_count' => max( 0, $retry_count ),
				'updated_at'  => current_time( 'mysql', true ),
			),
			array(
				'source_type' => $source_type,
				'source_id'   => $source_id,
			),
			array( '%s', '%d', '%s' ),
			array( '%s', '%s' )
		);
	}

	/**
	 * Build import job transient key.
	 *
	 * @param string $job_id Job id.
	 * @return string
	 */
	private function get_job_transient_key( string $job_id ): string {
		return 'kivor_chat_agent_kb_import_job_' . sanitize_key( str_replace( '-', '_', $job_id ) );
	}
}
