<?php
/**
 * Hybrid product search.
 *
 * Combines WooCommerce keyword search with semantic vector search
 * (when embeddings are available). Merges, deduplicates, and ranks
 * results. Falls back to keyword-only if embeddings are not configured.
 *
 * This is the main entry point for product search, used by the chat handler
 * via the `kivor_chat_agent_product_search` filter.
 *
 * @package KivorAgent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class Kivor_Hybrid_Search {

	/**
	 * Product search instance.
	 *
	 * @var Kivor_Product_Search
	 */
	private Kivor_Product_Search $product_search;

	/**
	 * Product formatter instance.
	 *
	 * @var Kivor_Product_Formatter
	 */
	private Kivor_Product_Formatter $formatter;

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
		$this->settings       = $settings;
		$this->product_search = new Kivor_Product_Search();
		$this->formatter      = new Kivor_Product_Formatter();
	}

	/**
	 * Register the search filter hook.
	 *
	 * Called during plugin init to wire up the `kivor_chat_agent_product_search` filter
	 * that the chat handler uses.
	 */
	public function init(): void {
		add_filter( 'kivor_chat_agent_product_search', array( $this, 'handle_search' ), 10, 2 );
	}

	/**
	 * Handle a product search request.
	 *
	 * This is the filter callback for `kivor_chat_agent_product_search`.
	 *
	 * @param array $default_result Default result (empty products).
	 * @param array $params         Search parameters from AI function call.
	 * @return array {
	 *     @type array  $products     Product card data for frontend.
	 *     @type string $context_text Products formatted as text for AI context.
	 * }
	 */
	public function handle_search( array $default_result, array $params ): array {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return $default_result;
		}

		$limit = intval( $params['limit'] ?? 6 );
		$limit = max( 1, min( $limit, 12 ) );

		// 1. Run keyword search.
		$keyword_products = $this->product_search->search( $params );

		// 2. Run semantic search (if available).
		$semantic_products = $this->run_semantic_search( $params, $limit );

		// 3. Merge and deduplicate results.
		$merged = $this->merge_results( $keyword_products, $semantic_products, $limit );

		if ( empty( $merged ) ) {
			return array(
				'products'     => array(),
				'context_text' => 'No products found matching the search criteria.',
			);
		}

		return array(
			'products'     => $this->formatter->format_for_frontend( $merged ),
			'context_text' => $this->formatter->format_for_ai_context( $merged ),
		);
	}

	/**
	 * Run semantic vector search if embeddings are configured.
	 *
	 * Delegates to Kivor_Semantic_Search via the 'kivor_chat_agent_semantic_search' filter,
	 * which generates an embedding for the query and searches the configured
	 * vector store (local DB, Pinecone, or Qdrant).
	 *
	 * @param array $params Search parameters.
	 * @param int   $limit  Max results.
	 * @return \WC_Product[] Array of WC_Product objects.
	 */
	private function run_semantic_search( array $params, int $limit ): array {
		$embedding_settings = $this->settings->get( 'embedding' );
		$providers          = $embedding_settings['providers'] ?? array();
		$active_provider    = $embedding_settings['active_provider'] ?? 'openai';
		$active_config      = $providers[ $active_provider ] ?? array();

		// Check if embeddings are configured.
		if ( empty( $active_config['api_key'] ) ) {
			return array();
		}

		// Build search text from params.
		$search_text = $this->build_search_text( $params );
		if ( empty( $search_text ) ) {
			return array();
		}

		/**
		 * Filter to execute semantic search.
		 *
		 * Kivor_Semantic_Search hooks into this to provide vector search results
		 * from the configured store (local DB, Pinecone, or Qdrant).
		 *
		 * @since 1.0.0
		 * @param array  $results     Default empty results.
		 * @param string $search_text Text to search for.
		 * @param int    $limit       Max results.
		 * @param array  $params      Original search params.
		 */
		$product_ids = apply_filters( 'kivor_chat_agent_semantic_search', array(), $search_text, $limit, $params );

		if ( empty( $product_ids ) ) {
			return array();
		}

		// Convert IDs to WC_Product objects.
		$products = array();
		foreach ( $product_ids as $id ) {
			$product = wc_get_product( $id );
			if ( $product && 'publish' === $product->get_status() ) {
				$products[] = $product;
			}
		}

		return $products;
	}

	/**
	 * Merge keyword and semantic search results.
	 *
	 * Deduplicates by product ID. Semantic results that also appear
	 * in keyword results get a ranking boost (appear first).
	 *
	 * @param \WC_Product[] $keyword_products  Results from keyword search.
	 * @param \WC_Product[] $semantic_products Results from semantic search.
	 * @param int           $limit             Max total results.
	 * @return \WC_Product[] Merged and ranked products.
	 */
	private function merge_results( array $keyword_products, array $semantic_products, int $limit ): array {
		if ( empty( $semantic_products ) ) {
			return array_slice( $keyword_products, 0, $limit );
		}

		if ( empty( $keyword_products ) ) {
			return array_slice( $semantic_products, 0, $limit );
		}

		// Track seen IDs for deduplication.
		$seen    = array();
		$merged  = array();
		$boosted = array();

		// Build lookup of keyword product IDs.
		$keyword_ids = array();
		foreach ( $keyword_products as $product ) {
			$keyword_ids[ $product->get_id() ] = true;
		}

		// First pass: find products that appear in both (boosted).
		foreach ( $semantic_products as $product ) {
			$id = $product->get_id();
			if ( isset( $keyword_ids[ $id ] ) && ! isset( $seen[ $id ] ) ) {
				$boosted[] = $product;
				$seen[ $id ] = true;
			}
		}

		// Second pass: add remaining semantic results.
		foreach ( $semantic_products as $product ) {
			$id = $product->get_id();
			if ( ! isset( $seen[ $id ] ) ) {
				$merged[] = $product;
				$seen[ $id ] = true;
			}
		}

		// Third pass: add remaining keyword results.
		foreach ( $keyword_products as $product ) {
			$id = $product->get_id();
			if ( ! isset( $seen[ $id ] ) ) {
				$merged[] = $product;
				$seen[ $id ] = true;
			}
		}

		// Combine: boosted first, then the rest.
		$final = array_merge( $boosted, $merged );

		return array_slice( $final, 0, $limit );
	}

	/**
	 * Build a natural language search text from parameters.
	 *
	 * Combines keyword, category, and tag into a single text string
	 * suitable for embedding-based similarity search.
	 *
	 * @param array $params Search parameters.
	 * @return string Combined search text.
	 */
	private function build_search_text( array $params ): string {
		$parts = array();

		if ( ! empty( $params['keyword'] ) ) {
			$parts[] = $params['keyword'];
		}

		if ( ! empty( $params['category'] ) ) {
			$parts[] = $params['category'];
		}

		if ( ! empty( $params['tag'] ) ) {
			$parts[] = $params['tag'];
		}

		return implode( ' ', $parts );
	}
}
