<?php
/**
 * Semantic search handler.
 *
 * @package KivorAgent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class Kivor_Semantic_Search {

	/**
	 * Primary provider.
	 *
	 * @var Kivor_Embedding_Provider
	 */
	private Kivor_Embedding_Provider $embedding_provider;

	/**
	 * Fallback provider.
	 *
	 * @var Kivor_Embedding_Provider|null
	 */
	private ?Kivor_Embedding_Provider $fallback_provider;

	/**
	 * Vector store.
	 *
	 * @var Kivor_Vector_Store
	 */
	private Kivor_Vector_Store $vector_store;

	/**
	 * Minimum score threshold.
	 *
	 * @var float
	 */
	private float $min_score;

	/**
	 * Constructor.
	 *
	 * @param Kivor_Embedding_Provider      $embedding_provider Primary provider.
	 * @param Kivor_Vector_Store            $vector_store       Vector store.
	 * @param Kivor_Embedding_Provider|null $fallback_provider  Optional fallback provider.
	 * @param float                         $min_score          Minimum score.
	 */
	public function __construct(
		Kivor_Embedding_Provider $embedding_provider,
		Kivor_Vector_Store $vector_store,
		?Kivor_Embedding_Provider $fallback_provider = null,
		float $min_score = 0.3
	) {
		$this->embedding_provider = $embedding_provider;
		$this->vector_store       = $vector_store;
		$this->fallback_provider  = $fallback_provider;
		$this->min_score          = $min_score;
	}

	/**
	 * Register search hook.
	 *
	 * @return void
	 */
	public function init(): void {
		add_filter( 'kivor_chat_agent_semantic_search', array( $this, 'search' ), 10, 4 );
	}

	/**
	 * Perform semantic search.
	 *
	 * @param array  $results     Results.
	 * @param string $search_text Query text.
	 * @param int    $limit       Limit.
	 * @param array  $params      Params.
	 * @return array
	 */
	public function search( array $results, string $search_text, int $limit, array $params ): array {
		unset( $params );

		if ( '' === trim( $search_text ) ) {
			return $results;
		}

		$search_results = $this->search_with_provider( $this->embedding_provider, $search_text, $limit );

		if ( is_wp_error( $search_results ) && $this->fallback_provider ) {
			$search_results = $this->search_with_provider( $this->fallback_provider, $search_text, $limit );
		}

		if ( is_wp_error( $search_results ) || empty( $search_results ) ) {
			if ( is_wp_error( $search_results ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging.
				error_log( 'Kivor semantic search error: ' . $search_results->get_error_message() );
			}
			return $results;
		}

		$product_ids = array();
		foreach ( $search_results as $result ) {
			$score = (float) ( $result['score'] ?? 0 );
			if ( $score < $this->min_score ) {
				continue;
			}

			$object_id = (int) ( $result['object_id'] ?? 0 );
			if ( $object_id > 0 ) {
				$product_ids[] = $object_id;
			}

			if ( count( $product_ids ) >= $limit ) {
				break;
			}
		}

		return $product_ids;
	}

	/**
	 * Run search with a specific provider.
	 *
	 * @param Kivor_Embedding_Provider $provider Provider.
	 * @param string                   $text     Query text.
	 * @param int                      $limit    Limit.
	 * @return array|WP_Error
	 */
	private function search_with_provider( Kivor_Embedding_Provider $provider, string $text, int $limit ) {
		$query_embedding = $provider->generate_embedding( $text );
		if ( is_wp_error( $query_embedding ) ) {
			return $query_embedding;
		}

		return $this->vector_store->search( $query_embedding, $limit * 2, 'product' );
	}

	/**
	 * Create semantic search from settings.
	 *
	 * @param Kivor_Settings $settings Settings.
	 * @return Kivor_Semantic_Search|null
	 */
	public static function from_settings( Kivor_Settings $settings ): ?self {
		$embedding_settings = $settings->get( 'embedding' );

		$active_provider = sanitize_key( (string) ( $embedding_settings['active_provider'] ?? 'openai' ) );
		$provider        = Kivor_Embedding_Factory::create( $embedding_settings, $active_provider );
		if ( is_wp_error( $provider ) ) {
			return null;
		}

		$fallback_name = sanitize_key( (string) ( $embedding_settings['fallback_provider'] ?? 'local' ) );
		$fallback      = null;
		if ( 'none' !== $fallback_name && 'local' !== $fallback_name && $fallback_name !== $active_provider ) {
			$created_fallback = Kivor_Embedding_Factory::create( $embedding_settings, $fallback_name );
			if ( ! is_wp_error( $created_fallback ) ) {
				$fallback = $created_fallback;
			}
		}

		$store_type   = (string) ( $embedding_settings['vector_store'] ?? 'local' );
		$vector_store = Kivor_Sync_Manager::create_vector_store( $store_type, $embedding_settings );
		if ( is_wp_error( $vector_store ) ) {
			return null;
		}

		return new self( $provider, $vector_store, $fallback );
	}
}
