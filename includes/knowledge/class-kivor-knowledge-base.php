<?php
/**
 * Knowledge Base manager.
 *
 * Handles knowledge base context retrieval for chat, embedding sync for KB articles,
 * and integration with the vector store for semantic search over KB content.
 *
 * @package KivorAgent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class Kivor_Knowledge_Base {

	/**
	 * Settings instance.
	 *
	 * @var Kivor_Settings
	 */
	private Kivor_Settings $settings;

	/**
	 * Maximum number of KB articles to include in chat context.
	 *
	 * @var int
	 */
	private const MAX_CONTEXT_ARTICLES = 3;

	/**
	 * Maximum total characters of KB context to inject into the prompt.
	 *
	 * @var int
	 */
	private const MAX_CONTEXT_CHARS = 4000;

	/**
	 * Minimum semantic similarity score for KB article relevance.
	 *
	 * @var float
	 */
	private const MIN_RELEVANCE_SCORE = 0.35;

	/**
	 * Constructor.
	 *
	 * @param Kivor_Settings $settings Plugin settings.
	 */
	public function __construct( Kivor_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register hooks.
	 *
	 * Hooks the `kivor_chat_agent_kb_context` filter so the chat handler can retrieve
	 * relevant KB articles when building the AI prompt.
	 */
	public function init(): void {
		add_filter( 'kivor_chat_agent_kb_context', array( $this, 'get_context_for_message' ), 10, 2 );
	}

	/**
	 * Filter callback: get relevant KB context for a user message.
	 *
	 * Called via `apply_filters( 'kivor_chat_agent_kb_context', '', $message )` in the chat handler.
	 * Uses semantic search if embeddings are configured, falls back to keyword search.
	 *
	 * @param string $context Empty string (default filter value).
	 * @param string $message The user's message.
	 * @return string Formatted KB context text, or empty string.
	 */
	public function get_context_for_message( string $context, string $message ): string {
		if ( empty( $message ) ) {
			return $context;
		}

		// Try semantic search first.
		$articles = $this->search_semantic( $message );

		// Fall back to keyword search if no semantic results or embeddings not configured.
		if ( empty( $articles ) ) {
			$articles = $this->search_keyword( $message );
		}

		if ( empty( $articles ) ) {
			return $context;
		}

		return $this->format_context( $articles );
	}

	// =========================================================================
	// Search methods
	// =========================================================================

	/**
	 * Search KB articles using semantic similarity.
	 *
	 * Generates an embedding for the user query and searches the vector store
	 * for KB articles with similar embeddings.
	 *
	 * @param string $message User message.
	 * @return array Array of KB article rows (with 'score' key added), or empty array.
	 */
	private function search_semantic( string $message ): array {
		$embedding_settings = $this->settings->get( 'embedding' );
		$active_provider    = sanitize_key( (string) ( $embedding_settings['active_provider'] ?? 'openai' ) );

		// Check if embeddings are configured.
		if ( '' === $active_provider ) {
			return array();
		}

		try {
			// Create embedding provider.
			$provider = Kivor_Embedding_Factory::create( $embedding_settings, $active_provider );

			if ( is_wp_error( $provider ) ) {
				return array();
			}

			// Create vector store.
			$store = Kivor_Sync_Manager::create_vector_store(
				$embedding_settings['vector_store'] ?? 'local',
				$embedding_settings
			);

			if ( is_wp_error( $store ) ) {
				return array();
			}

			// Generate query embedding.
			$query_embedding = $provider->generate_embedding( $message );
			if ( is_wp_error( $query_embedding ) ) {
				return array();
			}

			// Search vector store for KB articles.
			$results = $store->search(
				$query_embedding,
				self::MAX_CONTEXT_ARTICLES,
				'kb_article'
			);

			if ( is_wp_error( $results ) || empty( $results ) ) {
				return array();
			}

			// Filter by minimum relevance score and fetch full articles.
			$articles = array();
			foreach ( $results as $result ) {
				$score = $result['score'] ?? 0;
				if ( $score < self::MIN_RELEVANCE_SCORE ) {
					continue;
				}

				$article = $this->get_article( $result['object_id'] ?? 0 );
				if ( $article ) {
					$article['score'] = $score;
					$articles[]       = $article;
				}
			}

			return $articles;

		} catch ( \Exception $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging.
				error_log( 'Kivor KB semantic search failed: ' . $e->getMessage() );
			}
			return array();
		}
	}

	/**
	 * Search KB articles using simple keyword matching.
	 *
	 * Falls back to this when embeddings are not configured.
	 * Uses SQL LIKE queries against title and content.
	 *
	 * @param string $message User message.
	 * @return array Array of KB article rows, or empty array.
	 */
	private function search_keyword( string $message ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'kivor_knowledge_base';

		// Extract meaningful keywords (strip common words, min 3 chars).
		$keywords = $this->extract_keywords( $message );

		if ( empty( $keywords ) ) {
			return array();
		}

		// Build WHERE clauses for each keyword matching title or content.
		$where_parts  = array();
		$query_params = array();

		foreach ( $keywords as $keyword ) {
			$like          = '%' . $wpdb->esc_like( $keyword ) . '%';
			$where_parts[] = '(title LIKE %s OR content LIKE %s)';
			$query_params[] = $like;
			$query_params[] = $like;
		}

		$where_sql = implode( ' OR ', $where_parts );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT *, 0.0 as score FROM {$table} WHERE {$where_sql} ORDER BY updated_at DESC LIMIT %d",
				array_merge( $query_params, array( self::MAX_CONTEXT_ARTICLES ) )
			),
			ARRAY_A
		);

		return $results ?: array();
	}

	/**
	 * Extract meaningful keywords from a message.
	 *
	 * Removes common stop words and short words to improve search quality.
	 *
	 * @param string $message User message.
	 * @return array Array of keyword strings.
	 */
	private function extract_keywords( string $message ): array {
		// Common English stop words.
		$stop_words = array(
			'a', 'an', 'the', 'is', 'it', 'to', 'in', 'of', 'and', 'or',
			'for', 'on', 'at', 'by', 'as', 'are', 'was', 'be', 'do', 'does',
			'did', 'will', 'would', 'could', 'should', 'can', 'may', 'might',
			'have', 'has', 'had', 'not', 'but', 'if', 'then', 'than', 'so',
			'no', 'yes', 'what', 'when', 'where', 'how', 'why', 'who', 'which',
			'this', 'that', 'these', 'those', 'from', 'with', 'about', 'your',
			'you', 'we', 'they', 'he', 'she', 'its', 'my', 'me', 'i', 'am',
			'been', 'being', 'just', 'also', 'very', 'too', 'any', 'some',
			'all', 'each', 'every', 'much', 'many', 'more', 'most', 'up',
			'out', 'into', 'over', 'here', 'there',
		);

		// Normalize: lowercase, strip non-alphanumeric (keep spaces).
		$text  = mb_strtolower( $message, 'UTF-8' );
		$text  = preg_replace( '/[^a-z0-9\s]/u', ' ', $text );
		$words = preg_split( '/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY );

		// Filter: remove stop words and words shorter than 3 characters.
		$keywords = array();
		foreach ( $words as $word ) {
			if ( mb_strlen( $word, 'UTF-8' ) >= 3 && ! in_array( $word, $stop_words, true ) ) {
				$keywords[] = $word;
			}
		}

		// Limit to first 5 keywords to avoid overly broad queries.
		return array_slice( array_unique( $keywords ), 0, 5 );
	}

	// =========================================================================
	// Embedding sync for KB articles
	// =========================================================================

	/**
	 * Generate and store an embedding for a KB article.
	 *
	 * Called after an article is created or updated. If embeddings are not
	 * configured, this is a no-op.
	 *
	 * @param int $article_id KB article ID.
	 * @return true|WP_Error True on success (or skipped), WP_Error on failure.
	 */
	public function sync_article_embedding( int $article_id ) {
		$embedding_settings = $this->settings->get( 'embedding' );
		$active_provider    = sanitize_key( (string) ( $embedding_settings['active_provider'] ?? 'openai' ) );

		if ( '' === $active_provider ) {
			return true; // Embeddings not configured, skip silently.
		}

		$article = $this->get_article( $article_id );
		if ( ! $article ) {
			return new \WP_Error(
				'kivor_chat_agent_kb_article_not_found',
				__( 'Knowledge base article not found.', 'kivor-chat-agent' )
			);
		}

		try {
			$provider = Kivor_Embedding_Factory::create( $embedding_settings, $active_provider );

			if ( is_wp_error( $provider ) ) {
				return $provider;
			}

			$store = Kivor_Sync_Manager::create_vector_store(
				$embedding_settings['vector_store'] ?? 'local',
				$embedding_settings
			);

			if ( is_wp_error( $store ) ) {
				return $store;
			}

			// Format article for embedding.
			$text         = $this->format_for_embedding( $article );
			$content_hash = hash( 'sha256', $text );

			// Check if content has changed.
			$stored_hash = $store->get_content_hash( 'kb_article', $article_id );
			if ( $stored_hash === $content_hash ) {
				return true; // No change.
			}

			// Generate embedding.
			$embedding = $provider->generate_embedding( $text );
			if ( is_wp_error( $embedding ) ) {
				return $embedding;
			}

			// Build metadata.
			$metadata = array(
				'_object_type' => 'kb_article',
				'_object_id'   => $article_id,
				'title'        => mb_substr( $article['title'], 0, 200, 'UTF-8' ),
			);

			if ( ! empty( $article['source_url'] ) ) {
				$metadata['source_url'] = $article['source_url'];
			}

			// Upsert to vector store.
			return $store->upsert( 'kb_article', $article_id, $embedding, $metadata, $content_hash );

		} catch ( \Exception $e ) {
			return new \WP_Error(
				'kivor_chat_agent_kb_embedding_failed',
				$e->getMessage()
			);
		}
	}

	/**
	 * Delete a KB article's embedding from the vector store.
	 *
	 * @param int $article_id KB article ID.
	 * @return true|WP_Error
	 */
	public function delete_article_embedding( int $article_id ) {
		$embedding_settings = $this->settings->get( 'embedding' );
		$active_provider    = sanitize_key( (string) ( $embedding_settings['active_provider'] ?? 'openai' ) );

		if ( '' === $active_provider ) {
			return true; // Embeddings not configured.
		}

		try {
			$store = Kivor_Sync_Manager::create_vector_store(
				$embedding_settings['vector_store'] ?? 'local',
				$embedding_settings
			);

			if ( is_wp_error( $store ) ) {
				return $store;
			}

			return $store->delete( 'kb_article', $article_id );

		} catch ( \Exception $e ) {
			return new \WP_Error(
				'kivor_chat_agent_kb_delete_embedding_failed',
				$e->getMessage()
			);
		}
	}

	/**
	 * Sync all KB articles into the vector store.
	 *
	 * Used during a full sync to embed all KB articles alongside products.
	 *
	 * @return array{synced: int, skipped: int, errors: int}
	 */
	public function sync_all_articles(): array {
		$embedding_settings = $this->settings->get( 'embedding' );
		$active_provider    = sanitize_key( (string) ( $embedding_settings['active_provider'] ?? 'openai' ) );

		if ( '' === $active_provider ) {
			return array( 'synced' => 0, 'skipped' => 0, 'errors' => 0 );
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$table    = $wpdb->prefix . 'kivor_knowledge_base';
		$articles = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A );
		$total    = is_array( $articles ) ? count( $articles ) : 0;

		if ( empty( $articles ) ) {
			set_transient(
				'kivor_chat_agent_sync_status',
				array(
					'syncing'  => false,
					'type'     => 'kb',
					'progress' => 0,
					'total'    => 0,
					'message'  => __( 'No knowledge base articles to sync.', 'kivor-chat-agent' ),
				),
				HOUR_IN_SECONDS
			);

			return array( 'synced' => 0, 'skipped' => 0, 'errors' => 0 );
		}

		set_transient(
			'kivor_chat_agent_sync_status',
			array(
				'syncing'  => true,
				'type'     => 'kb',
				'progress' => 0,
				'total'    => $total,
				'message'  => __( 'Preparing knowledge base sync...', 'kivor-chat-agent' ),
			),
			HOUR_IN_SECONDS
		);

		$synced  = 0;
		$skipped = 0;
		$errors  = 0;

		foreach ( $articles as $index => $article ) {
			$result = $this->sync_article_embedding( (int) $article['id'] );

			if ( is_wp_error( $result ) ) {
				$errors++;
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging.
					error_log( 'Kivor KB sync error for article #' . $article['id'] . ': ' . $result->get_error_message() );
				}
			} elseif ( true === $result ) {
				// True could mean synced or skipped (unchanged). We count both as synced
				// since we can't distinguish without additional return info.
				$synced++;
			}

			set_transient(
				'kivor_chat_agent_sync_status',
				array(
					'syncing'  => true,
					'type'     => 'kb',
					'progress' => $index + 1,
					'total'    => $total,
					'message'  => sprintf(
						/* translators: 1: current number, 2: total number */
						__( 'Syncing knowledge base embeddings (%1$d/%2$d)...', 'kivor-chat-agent' ),
						$index + 1,
						$total
					),
				),
				HOUR_IN_SECONDS
			);
		}

		set_transient(
			'kivor_chat_agent_sync_status',
			array(
				'syncing'  => false,
				'type'     => 'kb',
				'progress' => $total,
				'total'    => $total,
				'message'  => __( 'Knowledge base sync complete.', 'kivor-chat-agent' ),
			),
			HOUR_IN_SECONDS
		);

		return array(
			'synced'  => $synced,
			'skipped' => $skipped,
			'errors'  => $errors,
		);
	}

	/**
	 * Upsert external platform articles into KB.
	 *
	 * @param array $articles           Normalized articles.
	 * @param bool  $enable_incremental Whether to update existing rows.
	 * @param array $meta               Import metadata.
	 * @return array{created:int,updated:int,skipped:int,errors:int}|WP_Error
	 */
	public function upsert_external_articles( array $articles, bool $enable_incremental = true, array $meta = array() ) {
		global $wpdb;

		$table = $wpdb->prefix . 'kivor_knowledge_base';
		$now   = current_time( 'mysql', true );
		$import_method = sanitize_key( (string) ( $meta['import_method'] ?? 'individual' ) );
		$sync_interval = sanitize_key( (string) ( $meta['sync_interval'] ?? 'manual' ) );

		if ( ! in_array( $import_method, array( 'individual', 'bulk', 'manual' ), true ) ) {
			$import_method = 'individual';
		}

		if ( ! in_array( $sync_interval, array( 'manual', 'hourly', 'daily', 'weekly' ), true ) ) {
			$sync_interval = 'manual';
		}

		$created = 0;
		$updated = 0;
		$skipped = 0;
		$errors  = 0;

		foreach ( $articles as $article ) {
			$source_type = sanitize_key( (string) ( $article['source_type'] ?? '' ) );
			$source_id   = sanitize_text_field( (string) ( $article['source_id'] ?? '' ) );
			$title       = sanitize_text_field( (string) ( $article['title'] ?? '' ) );
			$content     = Kivor_Sanitizer::sanitize_kb_content( (string) ( $article['content'] ?? '' ), 5000 );
			$source_url  = esc_url_raw( (string) ( $article['source_url'] ?? '' ) );

			if ( '' === $source_type || '' === $source_id || '' === $title || '' === trim( wp_strip_all_tags( $content ) ) ) {
				$skipped++;
				continue;
			// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
			}

			// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
			$existing = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, title, content, source_url FROM {$table} WHERE source_type = %s AND source_id = %s LIMIT 1",
					$source_type,
					$source_id
				),
				ARRAY_A
			);

			if ( $existing ) {
				if ( ! $enable_incremental ) {
					$skipped++;
					continue;
				}

				$changed = $existing['title'] !== $title
					|| $existing['content'] !== $content
					|| (string) $existing['source_url'] !== (string) $source_url;

				if ( ! $changed ) {
					$skipped++;
					continue;
				// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
				}

				// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
				$ok = $wpdb->update(
					$table,
					array(
						'title'       => $title,
						'content'     => $content,
						'source_url'  => $source_url,
						'last_synced_at' => $now,
						'sync_status' => 'synced',
						'import_method' => $import_method,
						'sync_interval' => $sync_interval,
						'retry_count' => 0,
						'updated_at'  => $now,
					),
					array( 'id' => (int) $existing['id'] ),
					array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ),
					array( '%d' )
				);

				if ( false === $ok ) {
					$errors++;
					continue;
				}

				$updated++;
				$this->sync_article_embedding( (int) $existing['id'] );
				continue;
			// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
			}

			// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
			$ok = $wpdb->insert(
				$table,
				array(
					'title'       => $title,
					'content'     => $content,
					'source_type' => $source_type,
					'source_id'   => $source_id,
					'source_url'  => $source_url,
					'imported_at' => $now,
					'last_synced_at' => $now,
					'sync_status' => 'synced',
					'import_method' => $import_method,
					'sync_interval' => $sync_interval,
					'retry_count' => 0,
					'created_at'  => $now,
					'updated_at'  => $now,
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
			);

			if ( false === $ok ) {
				$errors++;
				continue;
			}

			// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
			$article_id = (int) $wpdb->insert_id;
			$created++;
			$this->sync_article_embedding( $article_id );
		}

		return array(
			'created' => $created,
			'updated' => $updated,
			'skipped' => $skipped,
			'errors'  => $errors,
		);
	}

	/**
	 * Delete KB rows belonging to source types.
	 *
	 * @param array<int, string> $source_types Source types.
	 * @return int
	 */
	public function delete_articles_by_source_types( array $source_types ): int {
		global $wpdb;

		$source_types = array_values(
			array_filter(
				array_map( 'sanitize_key', $source_types ),
				static function ( string $v ) {
					return '' !== $v;
				}
			)
		);

		if ( empty( $source_types ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$table = $wpdb->prefix . 'kivor_knowledge_base';
		$in    = implode( ',', array_fill( 0, count( $source_types ), '%s' ) );
		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$sql   = $wpdb->prepare( "DELETE FROM {$table} WHERE source_type IN ({$in})", $source_types );

		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$deleted = $wpdb->query( $sql );

		return max( 0, (int) $deleted );
	}

	/**
	 * Delete rows missing in latest external sync.
	 *
	 * @param array<int, string> $source_types Source types.
	 * @param array              $articles     Latest normalized articles.
	 * @return int
	 */
	public function delete_missing_external_articles( array $source_types, array $articles ): int {
		global $wpdb;

		$source_types = array_values(
			array_filter(
				array_map( 'sanitize_key', $source_types ),
				static function ( string $v ) {
					return '' !== $v;
				}
			)
		);

		if ( empty( $source_types ) ) {
			return 0;
		}

		$keep_pairs = array();
		foreach ( $articles as $article ) {
			$type = sanitize_key( (string) ( $article['source_type'] ?? '' ) );
			$id   = sanitize_text_field( (string) ( $article['source_id'] ?? '' ) );

			if ( '' !== $type && '' !== $id ) {
				$keep_pairs[ $type . '::' . $id ] = true;
			}
		}

		$table = $wpdb->prefix . 'kivor_knowledge_base';
		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$in    = implode( ',', array_fill( 0, count( $source_types ), '%s' ) );

		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, source_type, source_id FROM {$table} WHERE source_type IN ({$in})", $source_types ),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return 0;
		}

		$delete_ids = array();
		foreach ( $rows as $row ) {
			$key = sanitize_key( (string) $row['source_type'] ) . '::' . sanitize_text_field( (string) $row['source_id'] );
			if ( ! isset( $keep_pairs[ $key ] ) ) {
				$delete_ids[] = (int) $row['id'];
			}
		}

		if ( empty( $delete_ids ) ) {
			return 0;
		}

		$deleted = 0;
		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		foreach ( $delete_ids as $id ) {
			$this->delete_article_embedding( $id );
			// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
			$ok = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
			if ( false !== $ok ) {
				$deleted += (int) $ok;
			}
		}

		return $deleted;
	}

	/**
	 * Delete one external article by source type + source id.
	 *
	 * @param string $source_type Source type.
	 * @param string $source_id   Source id.
	 * @return bool
	 */
	public function delete_external_article_by_source( string $source_type, string $source_id ): bool {
		$source_type = sanitize_key( $source_type );
		$source_id   = sanitize_text_field( $source_id );

		if ( '' === $source_type || '' === $source_id ) {
			return false;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$table = $wpdb->prefix . 'kivor_knowledge_base';

		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE source_type = %s AND source_id = %s LIMIT 1",
				$source_type,
				$source_id
			),
			ARRAY_A
		);

		if ( empty( $row['id'] ) ) {
			return false;
		}
// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB

		$this->delete_article_embedding( (int) $row['id'] );
		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$deleted = $wpdb->delete( $table, array( 'id' => (int) $row['id'] ), array( '%d' ) );

		return false !== $deleted && $deleted > 0;
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Get a KB article by ID.
	 *
	 * @param int $article_id Article ID.
	 * @return array|null Article row as associative array, or null if not found.
	 */
	private function get_article( int $article_id ): ?array {
		if ( $article_id <= 0 ) {
			return null;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$table = $wpdb->prefix . 'kivor_knowledge_base';

		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $article_id ),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Format a KB article into embedding text.
	 *
	 * Combines title and content into a single string optimized for embedding.
	 *
	 * @param array $article KB article row.
	 * @return string Formatted text for embedding.
	 */
	private function format_for_embedding( array $article ): string {
		$parts = array();

		if ( ! empty( $article['title'] ) ) {
			$parts[] = 'Title: ' . $article['title'];
		}

		if ( ! empty( $article['content'] ) ) {
			$parts[] = $article['content'];
		}

		return implode( "\n\n", $parts );
	}

	/**
	 * Format articles into context text for the AI prompt.
	 *
	 * Formats multiple articles into a structured text block that gets
	 * injected into the system prompt's Knowledge Base section.
	 *
	 * @param array $articles Array of KB article rows.
	 * @return string Formatted context text.
	 */
	private function format_context( array $articles ): string {
		$context_parts = array();
		$total_chars   = 0;

		foreach ( $articles as $article ) {
			$title   = $article['title'] ?? 'Untitled';
			$content = $article['content'] ?? '';

			// Truncate individual article content if needed.
			$remaining = self::MAX_CONTEXT_CHARS - $total_chars;
			if ( $remaining <= 0 ) {
				break;
			}

			if ( mb_strlen( $content, 'UTF-8' ) > $remaining ) {
				$content = mb_substr( $content, 0, $remaining, 'UTF-8' ) . '...';
			}

			$entry = "--- {$title} ---\n{$content}";
			$context_parts[] = $entry;
			$total_chars    += mb_strlen( $entry, 'UTF-8' );
		}

		return implode( "\n\n", $context_parts );
	}
}
