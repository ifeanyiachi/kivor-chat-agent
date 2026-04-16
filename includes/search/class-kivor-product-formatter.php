<?php
/**
 * Product data formatter.
 *
 * Converts WC_Product objects into two formats:
 * 1. AI context — text summary for the system prompt.
 * 2. Frontend display — JSON-serializable array for product cards.
 *
 * @package KivorAgent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class Kivor_Product_Formatter {

	/**
	 * Format products for frontend product cards.
	 *
	 * Returns an array of associative arrays matching the data shape
	 * expected by kivor-chat-agent-product-card.js.
	 *
	 * @param \WC_Product[] $products Array of WC_Product objects.
	 * @return array Formatted product data.
	 */
	public function format_for_frontend( array $products ): array {
		$formatted = array();
		$allowed_price_html = array(
			'span'   => array( 'class' => true ),
			'del'    => array(),
			'ins'    => array(),
			'bdi'    => array(),
			'strong' => array(),
		);

		foreach ( $products as $product ) {
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			$data = array(
				'id'                => $product->get_id(),
				'title'             => $product->get_name(),
				'url'               => $product->get_permalink(),
				'price'             => $this->get_display_price( $product ),
				'price_html'        => wp_kses( $product->get_price_html(), $allowed_price_html ),
				'image'             => $this->get_image_url( $product ),
				'short_description' => $this->get_short_description( $product ),
				'in_stock'          => $product->is_in_stock(),
				'stock_status'      => $product->get_stock_status(),
			);

			/**
			 * Filter a single product's frontend data.
			 *
			 * @param array       $data    Formatted product data.
			 * @param \WC_Product $product Original WC_Product object.
			 */
			$formatted[] = apply_filters( 'kivor_chat_agent_product_card_data', $data, $product );
		}

		return $formatted;
	}

	/**
	 * Format products as text for the AI system prompt context.
	 *
	 * Creates a concise text summary that the AI can use to generate
	 * natural language responses about the products.
	 *
	 * @param \WC_Product[] $products Array of WC_Product objects.
	 * @return string Text summary.
	 */
	public function format_for_ai_context( array $products ): string {
		if ( empty( $products ) ) {
			return 'No products found matching the search criteria.';
		}

		$count = count( $products );
		$lines = array();
		$lines[] = "Found {$count} product(s):";
		$lines[] = '';

		foreach ( $products as $i => $product ) {
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			$num = $i + 1;
			$lines[] = "{$num}. {$product->get_name()}";
			$lines[] = "   Price: {$this->get_display_price( $product )}";

			$stock = $product->is_in_stock() ? 'In stock' : 'Out of stock';
			$lines[] = "   Stock: {$stock}";

			// Categories.
			$cats = $this->get_category_names( $product );
			if ( ! empty( $cats ) ) {
				$lines[] = '   Categories: ' . implode( ', ', $cats );
			}

			// Short description (truncated for context).
			$desc = $this->get_short_description( $product );
			if ( ! empty( $desc ) ) {
				$desc = mb_strimwidth( $desc, 0, 150, '...' );
				$lines[] = "   Description: {$desc}";
			}

			$lines[] = "   URL: {$product->get_permalink()}";
			$lines[] = '';
		}

		return implode( "\n", $lines );
	}

	/**
	 * Format a single product for embedding text.
	 *
	 * Creates a searchable text representation for vector embedding.
	 *
	 * @param \WC_Product $product Product object.
	 * @return string Text for embedding.
	 */
	public function format_for_embedding( \WC_Product $product ): string {
		$parts = array();

		$parts[] = $product->get_name();

		$desc = $product->get_short_description();
		if ( empty( $desc ) ) {
			$desc = $product->get_description();
		}
		if ( ! empty( $desc ) ) {
			$parts[] = wp_strip_all_tags( $desc );
		}

		$cats = $this->get_category_names( $product );
		if ( ! empty( $cats ) ) {
			$parts[] = 'Categories: ' . implode( ', ', $cats );
		}

		$tags = $this->get_tag_names( $product );
		if ( ! empty( $tags ) ) {
			$parts[] = 'Tags: ' . implode( ', ', $tags );
		}

		$parts[] = 'Price: ' . $this->get_display_price( $product );

		$sku = $product->get_sku();
		if ( ! empty( $sku ) ) {
			$parts[] = "SKU: {$sku}";
		}

		return implode( '. ', $parts );
	}

	/**
	 * Build product metadata for embedding storage.
	 *
	 * @param \WC_Product $product Product object.
	 * @return array Metadata associative array.
	 */
	public function build_embedding_metadata( \WC_Product $product ): array {
		return array(
			'title'             => $product->get_name(),
			'price'             => $product->get_price(),
			'regular_price'     => $product->get_regular_price(),
			'sale_price'        => $product->get_sale_price(),
			'stock_status'      => $product->get_stock_status(),
			'categories'        => $this->get_category_names( $product ),
			'tags'              => $this->get_tag_names( $product ),
			'sku'               => $product->get_sku(),
			'url'               => $product->get_permalink(),
			'image'             => $this->get_image_url( $product ),
			'short_description' => $this->get_short_description( $product ),
		);
	}

	/**
	 * Get display price string.
	 *
	 * @param \WC_Product $product Product.
	 * @return string Formatted price.
	 */
	private function get_display_price( \WC_Product $product ): string {
		$price = $product->get_price();
		if ( '' === $price ) {
			return 'Price not set';
		}

		$formatted = wc_price( $price );

		// Strip HTML for a plain text version.
		$plain = wp_strip_all_tags( $formatted );

		// If on sale, include both prices.
		if ( $product->is_on_sale() ) {
			$regular = wp_strip_all_tags( wc_price( $product->get_regular_price() ) );
			return "{$plain} (was {$regular})";
		}

		return $plain;
	}

	/**
	 * Get product image URL.
	 *
	 * @param \WC_Product $product Product.
	 * @param string      $size    Image size. Default 'woocommerce_thumbnail'.
	 * @return string Image URL or empty string.
	 */
	private function get_image_url( \WC_Product $product, string $size = 'woocommerce_thumbnail' ): string {
		$image_id = $product->get_image_id();
		if ( $image_id ) {
			$url = wp_get_attachment_image_url( $image_id, $size );
			if ( $url ) {
				return $url;
			}
		}

		// Fallback to WC placeholder.
		return wc_placeholder_img_src( $size );
	}

	/**
	 * Get a clean short description.
	 *
	 * Falls back to a truncated full description if short description is empty.
	 *
	 * @param \WC_Product $product Product.
	 * @return string Plain-text description.
	 */
	private function get_short_description( \WC_Product $product ): string {
		$desc = $product->get_short_description();
		if ( empty( $desc ) ) {
			$desc = $product->get_description();
		}

		if ( empty( $desc ) ) {
			return '';
		}

		$desc = wp_strip_all_tags( $desc );
		$desc = preg_replace( '/\s+/', ' ', $desc );

		return mb_strimwidth( trim( $desc ), 0, 200, '...' );
	}

	/**
	 * Get category names for a product.
	 *
	 * @param \WC_Product $product Product.
	 * @return string[] Array of category names.
	 */
	private function get_category_names( \WC_Product $product ): array {
		$term_ids = $product->get_category_ids();
		if ( empty( $term_ids ) ) {
			return array();
		}

		$names = array();
		foreach ( $term_ids as $id ) {
			$term = get_term( $id, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$names[] = $term->name;
			}
		}

		return $names;
	}

	/**
	 * Get tag names for a product.
	 *
	 * @param \WC_Product $product Product.
	 * @return string[] Array of tag names.
	 */
	private function get_tag_names( \WC_Product $product ): array {
		$term_ids = $product->get_tag_ids();
		if ( empty( $term_ids ) ) {
			return array();
		}

		$names = array();
		foreach ( $term_ids as $id ) {
			$term = get_term( $id, 'product_tag' );
			if ( $term && ! is_wp_error( $term ) ) {
				$names[] = $term->name;
			}
		}

		return $names;
	}
}
