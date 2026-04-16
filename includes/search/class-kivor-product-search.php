<?php
/**
 * WooCommerce product search.
 *
 * Builds and executes WooCommerce product queries based on parameters
 * from the AI's function calling. Supports keyword, category, tag,
 * price range, stock status, and sort filters.
 *
 * Discovery: wc_get_products() doesn't natively support keyword search (`s`).
 * We hook into `woocommerce_product_data_store_cpt_get_products_query` to
 * inject `$wp_query_args['s']` and other custom query args.
 *
 * @package KivorAgent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class Kivor_Product_Search {

	/**
	 * Maximum allowed limit.
	 *
	 * @var int
	 */
	const MAX_LIMIT = 12;

	/**
	 * Default result limit.
	 *
	 * @var int
	 */
	const DEFAULT_LIMIT = 6;

	/**
	 * Pending custom query args to be injected via the WC filter.
	 *
	 * @var array
	 */
	private array $pending_query_args = array();

	/**
	 * Search for products.
	 *
	 * @param array $params {
	 *     Search parameters (all optional).
	 *
	 *     @type string  $keyword       Search keyword/phrase.
	 *     @type string  $category      Category name or slug.
	 *     @type string  $tag           Tag name or slug.
	 *     @type float   $min_price     Minimum price.
	 *     @type float   $max_price     Maximum price.
	 *     @type bool    $in_stock_only Only in-stock products.
	 *     @type string  $sort_by       Sort: relevance|price_low|price_high|newest|popularity.
	 *     @type int     $limit         Max results (1-12).
	 * }
	 * @return \WC_Product[] Array of WC_Product objects.
	 */
	public function search( array $params ): array {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return array();
		}

		$limit = $this->sanitize_limit( $params['limit'] ?? self::DEFAULT_LIMIT );

		// Build wc_get_products() args.
		$args = array(
			'status' => 'publish',
			'limit'  => $limit,
			'return' => 'objects',
		);

		// Category filter.
		$category_ids = $this->resolve_category( $params['category'] ?? '' );
		if ( ! empty( $category_ids ) ) {
			$args['category'] = $category_ids;
		}

		// Tag filter.
		$tag_ids = $this->resolve_tag( $params['tag'] ?? '' );
		if ( ! empty( $tag_ids ) ) {
			$args['tag'] = $tag_ids;
		}

		// Stock status filter.
		if ( ! empty( $params['in_stock_only'] ) ) {
			$args['stock_status'] = 'instock';
		}

		// Sorting.
		$sort = $this->resolve_sort( $params['sort_by'] ?? 'relevance' );
		$args['orderby'] = $sort['orderby'];
		$args['order']   = $sort['order'];

		// Prepare custom query args for the WC filter.
		$this->pending_query_args = array();

		// Keyword search (injected via filter).
		$keyword = sanitize_text_field( $params['keyword'] ?? '' );
		if ( ! empty( $keyword ) ) {
			$this->pending_query_args['s'] = $keyword;
		}

		// Price range (injected via filter as meta_query).
		$meta_query = $this->build_price_meta_query(
			$params['min_price'] ?? null,
			$params['max_price'] ?? null
		);
		if ( ! empty( $meta_query ) ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			$this->pending_query_args['meta_query'] = $meta_query;
		}

		// Hook into WC query to inject custom args.
		add_filter(
			'woocommerce_product_data_store_cpt_get_products_query',
			array( $this, 'inject_custom_query_args' ),
			10,
			2
		);

		$products = wc_get_products( $args );

		// Remove the filter to avoid side effects.
		remove_filter(
			'woocommerce_product_data_store_cpt_get_products_query',
			array( $this, 'inject_custom_query_args' ),
			10
		);

		$this->pending_query_args = array();

		return is_array( $products ) ? $products : array();
	}

	/**
	 * WooCommerce filter callback to inject custom query args.
	 *
	 * wc_get_products() doesn't natively support keyword search, price meta
	 * queries, or tag_ids directly. This filter lets us inject those into
	 * the underlying WP_Query.
	 *
	 * @param array $wp_query_args Existing WP_Query args.
	 * @param array $query_vars    WC query vars (unused here).
	 * @return array Modified WP_Query args.
	 */
	public function inject_custom_query_args( array $wp_query_args, array $query_vars ): array {
		// Keyword search.
		if ( ! empty( $this->pending_query_args['s'] ) ) {
			$wp_query_args['s'] = $this->pending_query_args['s'];
		}

		// Price meta query.
		if ( ! empty( $this->pending_query_args['meta_query'] ) ) {
			if ( ! isset( $wp_query_args['meta_query'] ) ) {
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				$wp_query_args['meta_query'] = array();
			}
			$wp_query_args['meta_query'][] = $this->pending_query_args['meta_query'];
		}

		return $wp_query_args;
	}

	/**
	 * Resolve a category name/slug to category IDs.
	 *
	 * @param string $category Category name or slug.
	 * @return array Array of term slugs (WC 'category' arg accepts slugs).
	 */
	private function resolve_category( string $category ): array {
		if ( empty( $category ) ) {
			return array();
		}

		// Try by slug first.
		$term = get_term_by( 'slug', sanitize_title( $category ), 'product_cat' );
		if ( $term ) {
			return array( $term->slug );
		}

		// Try by name.
		$term = get_term_by( 'name', $category, 'product_cat' );
		if ( $term ) {
			return array( $term->slug );
		}

		// Fuzzy match: search terms containing the keyword.
		$terms = get_terms( array(
			'taxonomy'   => 'product_cat',
			'search'     => $category,
			'hide_empty' => true,
			'number'     => 3,
		) );

		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			return array_map( function ( $t ) {
				return $t->slug;
			}, $terms );
		}

		return array();
	}

	/**
	 * Resolve a tag name/slug to tag slugs.
	 *
	 * @param string $tag Tag name or slug.
	 * @return array Array of tag slugs.
	 */
	private function resolve_tag( string $tag ): array {
		if ( empty( $tag ) ) {
			return array();
		}

		$term = get_term_by( 'slug', sanitize_title( $tag ), 'product_tag' );
		if ( $term ) {
			return array( $term->slug );
		}

		$term = get_term_by( 'name', $tag, 'product_tag' );
		if ( $term ) {
			return array( $term->slug );
		}

		$terms = get_terms( array(
			'taxonomy'   => 'product_tag',
			'search'     => $tag,
			'hide_empty' => true,
			'number'     => 3,
		) );

		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			return array_map( function ( $t ) {
				return $t->slug;
			}, $terms );
		}

		return array();
	}

	/**
	 * Build a meta_query for price filtering.
	 *
	 * Uses _price meta key which WooCommerce indexes for all product types.
	 *
	 * @param mixed $min_price Minimum price (null if not set).
	 * @param mixed $max_price Maximum price (null if not set).
	 * @return array Meta query array or empty array.
	 */
	private function build_price_meta_query( $min_price, $max_price ): array {
		$min = is_numeric( $min_price ) ? floatval( $min_price ) : null;
		$max = is_numeric( $max_price ) ? floatval( $max_price ) : null;

		if ( null === $min && null === $max ) {
			return array();
		}

		if ( null !== $min && null !== $max ) {
			return array(
				'key'     => '_price',
				'value'   => array( $min, $max ),
				'compare' => 'BETWEEN',
				'type'    => 'DECIMAL(10,2)',
			);
		}

		if ( null !== $min ) {
			return array(
				'key'     => '_price',
				'value'   => $min,
				'compare' => '>=',
				'type'    => 'DECIMAL(10,2)',
			);
		}

		return array(
			'key'     => '_price',
			'value'   => $max,
			'compare' => '<=',
			'type'    => 'DECIMAL(10,2)',
		);
	}

	/**
	 * Resolve sort parameter to WC orderby/order values.
	 *
	 * @param string $sort_by Sort option.
	 * @return array { orderby: string, order: string }
	 */
	private function resolve_sort( string $sort_by ): array {
		switch ( $sort_by ) {
			case 'price_low':
				return array( 'orderby' => 'meta_value_num', 'order' => 'ASC' );
			case 'price_high':
				return array( 'orderby' => 'meta_value_num', 'order' => 'DESC' );
			case 'newest':
				return array( 'orderby' => 'date', 'order' => 'DESC' );
			case 'popularity':
				return array( 'orderby' => 'popularity', 'order' => 'DESC' );
			case 'relevance':
			default:
				return array( 'orderby' => 'relevance', 'order' => 'DESC' );
		}
	}

	/**
	 * Sanitize and clamp the result limit.
	 *
	 * @param mixed $limit Raw limit value.
	 * @return int Clamped limit.
	 */
	private function sanitize_limit( $limit ): int {
		$limit = absint( $limit );
		if ( $limit < 1 ) {
			return self::DEFAULT_LIMIT;
		}
		return min( $limit, self::MAX_LIMIT );
	}
}
