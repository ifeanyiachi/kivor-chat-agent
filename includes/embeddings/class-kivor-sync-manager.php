<?php
/**
 * Embeddings sync manager.
 *
 * @package KivorAgent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class Kivor_Sync_Manager {

	/**
	 * Primary embedding provider.
	 *
	 * @var Kivor_Embedding_Provider
	 */
	private Kivor_Embedding_Provider $primary_provider;

	/**
	 * Optional fallback provider.
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
	 * All enabled providers keyed by provider id.
	 *
	 * @var array<string, Kivor_Embedding_Provider>
	 */
	private array $all_enabled_providers;

	/**
	 * Active provider key.
	 *
	 * @var string
	 */
	private string $active_provider;

	/**
	 * Vector store type.
	 *
	 * @var string
	 */
	private string $vector_store_type;

	/**
	 * Constructor.
	 *
	 * @param Kivor_Embedding_Provider      $primary_provider      Primary provider.
	 * @param Kivor_Vector_Store            $vector_store          Vector store.
	 * @param Kivor_Embedding_Provider|null $fallback_provider     Fallback provider.
	 * @param array<string, Kivor_Embedding_Provider> $all_enabled_providers Enabled providers.
	 * @param string                        $active_provider       Active provider key.
	 * @param string                        $vector_store_type     Vector store type.
	 */
	public function __construct(
		Kivor_Embedding_Provider $primary_provider,
		Kivor_Vector_Store $vector_store,
		?Kivor_Embedding_Provider $fallback_provider = null,
		array $all_enabled_providers = array(),
		string $active_provider = 'openai',
		string $vector_store_type = 'local'
	) {
		$this->primary_provider       = $primary_provider;
		$this->vector_store           = $vector_store;
		$this->fallback_provider      = $fallback_provider;
		$this->all_enabled_providers  = $all_enabled_providers;
		$this->active_provider        = sanitize_key( $active_provider );
		$this->vector_store_type      = sanitize_key( $vector_store_type );
	}

	/**
	 * Create a sync manager from plugin settings.
	 *
	 * @param Kivor_Settings $settings Settings.
	 * @return Kivor_Sync_Manager|WP_Error
	 */
	public static function from_settings( Kivor_Settings $settings ) {
		$embedding_settings = $settings->get( 'embedding', array() );
		$active_provider    = sanitize_key( (string) ( $embedding_settings['active_provider'] ?? 'openai' ) );

		$primary = Kivor_Embedding_Factory::create( $embedding_settings, $active_provider );
		if ( is_wp_error( $primary ) ) {
			return $primary;
		}

		$vector_store_type = (string) ( $embedding_settings['vector_store'] ?? 'local' );
		$vector_store      = self::create_vector_store( $vector_store_type, $embedding_settings );
		if ( is_wp_error( $vector_store ) ) {
			return $vector_store;
		}

		$fallback_provider_name = sanitize_key( (string) ( $embedding_settings['fallback_provider'] ?? 'local' ) );
		$fallback_provider      = null;

		if ( 'none' !== $fallback_provider_name && 'local' !== $fallback_provider_name && $fallback_provider_name !== $active_provider ) {
			$maybe_fallback = Kivor_Embedding_Factory::create( $embedding_settings, $fallback_provider_name );
			if ( ! is_wp_error( $maybe_fallback ) ) {
				$fallback_provider = $maybe_fallback;
			}
		}

		$enabled_providers = array(
			$active_provider => $primary,
		);

		$provider_configs = is_array( $embedding_settings['providers'] ?? null )
			? $embedding_settings['providers']
			: array();

		foreach ( array_keys( Kivor_Embedding_Factory::get_available_providers() ) as $provider_key ) {
			if ( $provider_key === $active_provider ) {
				continue;
			}

			$config = is_array( $provider_configs[ $provider_key ] ?? null )
				? $provider_configs[ $provider_key ]
				: array();

			if ( empty( $config['enabled'] ) || empty( $config['api_key'] ) ) {
				continue;
			}

			if ( $provider_key === $fallback_provider_name && $fallback_provider ) {
				$enabled_providers[ $provider_key ] = $fallback_provider;
				continue;
			}

			$created = Kivor_Embedding_Factory::create( $embedding_settings, $provider_key );
			if ( ! is_wp_error( $created ) ) {
				$enabled_providers[ $provider_key ] = $created;
			}
		}

		return new self(
			$primary,
			$vector_store,
			$fallback_provider,
			$enabled_providers,
			$active_provider,
			$vector_store_type
		);
	}

	/**
	 * Create an embedding provider from settings.
	 *
	 * Backward-compatible helper for legacy call sites.
	 *
	 * @param array       $embedding_settings Embedding settings.
	 * @param string|null $provider           Provider key override.
	 * @return Kivor_Embedding_Provider|WP_Error
	 */
	public static function create_embedding_provider( array $embedding_settings, ?string $provider = null ) {
		return Kivor_Embedding_Factory::create( $embedding_settings, $provider );
	}

	/**
	 * Create a vector store from settings.
	 *
	 * @param string $store_type         Store type.
	 * @param array  $embedding_settings Embedding settings.
	 * @return Kivor_Vector_Store|WP_Error
	 */
	public static function create_vector_store( string $store_type, array $embedding_settings ) {
		$store_type = sanitize_key( $store_type );

		switch ( $store_type ) {
			case 'local':
				return new Kivor_Local_Store();

			case 'pinecone':
				$pinecone = is_array( $embedding_settings['pinecone'] ?? null ) ? $embedding_settings['pinecone'] : array();
				$api_key  = sanitize_text_field( (string) ( $pinecone['api_key'] ?? '' ) );
				$index    = sanitize_text_field( (string) ( $pinecone['index_name'] ?? '' ) );
				$env      = sanitize_text_field( (string) ( $pinecone['environment'] ?? '' ) );

				if ( '' === $api_key || '' === $index || '' === $env ) {
					return new \WP_Error(
						'kivor_chat_agent_embedding_invalid_vector_store',
						__( 'Pinecone requires API key, index name, and environment.', 'kivor-chat-agent' )
					);
				}

				return new Kivor_Pinecone_Store( $api_key, $index, $env );

			case 'qdrant':
				$qdrant      = is_array( $embedding_settings['qdrant'] ?? null ) ? $embedding_settings['qdrant'] : array();
				$endpoint    = esc_url_raw( (string) ( $qdrant['endpoint_url'] ?? '' ) );
				$api_key     = sanitize_text_field( (string) ( $qdrant['api_key'] ?? '' ) );
				$collection  = sanitize_text_field( (string) ( $qdrant['collection_name'] ?? 'kivor_chat_agent_products' ) );

				if ( '' === $endpoint ) {
					return new \WP_Error(
						'kivor_chat_agent_embedding_invalid_vector_store',
						__( 'Qdrant requires an endpoint URL.', 'kivor-chat-agent' )
					);
				}

				return new Kivor_Qdrant_Store( $endpoint, $api_key, $collection );

			default:
				return new \WP_Error(
					'kivor_chat_agent_embedding_unknown_vector_store',
					__( 'Unknown vector store selected.', 'kivor-chat-agent' )
				);
		}
	}

	/**
	 * Sync all published products.
	 *
	 * @return array{synced:int,skipped:int,errors:int,deleted:int}
	 */
	public function sync_all(): array {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return array(
				'synced'  => 0,
				'skipped' => 0,
				'errors'  => 0,
				'deleted' => 0,
			);
		}

		$product_ids = wc_get_products(
			array(
				'status' => 'publish',
				'limit'  => -1,
				'return' => 'ids',
			)
		);

		if ( ! is_array( $product_ids ) ) {
			$product_ids = array();
		}

		$total = count( $product_ids );

		set_transient(
			'kivor_chat_agent_sync_status',
			array(
				'syncing'  => true,
				'type'     => 'products',
				'progress' => 0,
				'total'    => $total,
				'message'  => __( 'Preparing product sync...', 'kivor-chat-agent' ),
			),
			HOUR_IN_SECONDS
		);

		$dimension_check = $this->validate_provider_dimensions_for_store();
		if ( is_wp_error( $dimension_check ) ) {
			set_transient(
				'kivor_chat_agent_sync_status',
				array(
					'syncing'  => false,
					'type'     => 'products',
					'progress' => 0,
					'total'    => $total,
					'message'  => $dimension_check->get_error_message(),
				),
				HOUR_IN_SECONDS
			);

			return array(
				'synced'  => 0,
				'skipped' => 0,
				'errors'  => $total,
				'deleted' => 0,
			);
		}

		$synced  = 0;
		$skipped = 0;
		$errors  = 0;

		foreach ( array_values( array_map( 'absint', $product_ids ) ) as $index => $product_id ) {
			$result = $this->sync_product( $product_id );

			if ( is_wp_error( $result ) ) {
				$errors++;
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging.
					error_log( 'Kivor product embedding sync failed for #' . $product_id . ': ' . $result->get_error_message() );
				}
			} elseif ( 'skipped' === $result ) {
				$skipped++;
			} else {
				$synced++;
			}

			set_transient(
				'kivor_chat_agent_sync_status',
				array(
					'syncing'  => true,
					'type'     => 'products',
					'progress' => $index + 1,
					'total'    => $total,
					'message'  => sprintf(
						/* translators: 1: current number, 2: total number */
						__( 'Syncing product embeddings (%1$d/%2$d)...', 'kivor-chat-agent' ),
						$index + 1,
						$total
					),
				),
				HOUR_IN_SECONDS
			);
		}

		$deleted = $this->delete_missing_products( array_map( 'absint', $product_ids ) );
		if ( is_wp_error( $deleted ) ) {
			$deleted = 0;
		}

		set_transient(
			'kivor_chat_agent_sync_status',
			array(
				'syncing'  => false,
				'type'     => 'products',
				'progress' => $total,
				'total'    => $total,
				'message'  => __( 'Embedding sync complete.', 'kivor-chat-agent' ),
			),
			HOUR_IN_SECONDS
		);

		return array(
			'synced'  => $synced,
			'skipped' => $skipped,
			'errors'  => $errors,
			'deleted' => (int) $deleted,
		);
	}

	/**
	 * Sync one product embedding.
	 *
	 * @param int $product_id Product ID.
	 * @return true|WP_Error|string True for synced, WP_Error on failure, 'skipped' when unchanged.
	 */
	public function sync_product( int $product_id ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 ) {
			return new \WP_Error(
				'kivor_chat_agent_embedding_invalid_product',
				__( 'Invalid product ID.', 'kivor-chat-agent' )
			);
		}

		if ( ! function_exists( 'wc_get_product' ) ) {
			return new \WP_Error(
				'kivor_chat_agent_embedding_no_woocommerce',
				__( 'WooCommerce is not available.', 'kivor-chat-agent' )
			);
		}

		$product = wc_get_product( $product_id );
		if ( ! $product instanceof \WC_Product ) {
			return new \WP_Error(
				'kivor_chat_agent_embedding_product_not_found',
				__( 'Product not found.', 'kivor-chat-agent' )
			);
		}

		if ( 'publish' !== $product->get_status() ) {
			return new \WP_Error(
				'kivor_chat_agent_embedding_product_unpublished',
				__( 'Only published products can be synced.', 'kivor-chat-agent' )
			);
		}

		$dimension_check = $this->validate_provider_dimensions_for_store();
		if ( is_wp_error( $dimension_check ) ) {
			return $dimension_check;
		}

		$text = $this->build_product_embedding_text( $product );
		if ( '' === trim( $text ) ) {
			return new \WP_Error(
				'kivor_chat_agent_embedding_empty_product_text',
				__( 'Could not build product text for embedding.', 'kivor-chat-agent' )
			);
		}

		$content_hash = hash( 'sha256', $text . '|' . $this->get_sync_provider_signature() );
		$stored_hash  = $this->vector_store->get_content_hash( 'product', $product_id );

		if ( $stored_hash === $content_hash ) {
			return 'skipped';
		}

		$providers = $this->get_sync_providers();
		if ( empty( $providers ) ) {
			return new \WP_Error(
				'kivor_chat_agent_embedding_no_provider',
				__( 'No embedding provider available for sync.', 'kivor-chat-agent' )
			);
		}

		$last_error = null;
		$successes  = 0;

		foreach ( $providers as $provider_key => $provider ) {
			$result = $this->sync_product_with_provider( $product, $text, $content_hash, $provider_key, $provider );

			if ( is_wp_error( $result ) ) {
				$last_error = $result;
				continue;
			}

			$successes++;
		}

		if ( 0 === $successes && $this->fallback_provider ) {
			$fallback_key = $this->find_provider_key_by_instance( $this->fallback_provider );
			if ( '' === $fallback_key ) {
				$fallback_key = 'fallback';
			}

			$fallback_result = $this->sync_product_with_provider( $product, $text, $content_hash, $fallback_key, $this->fallback_provider );
			if ( ! is_wp_error( $fallback_result ) ) {
				$successes++;
			} else {
				$last_error = $fallback_result;
			}
		}

		if ( 0 === $successes ) {
			return $last_error instanceof \WP_Error
				? $last_error
				: new \WP_Error(
					'kivor_chat_agent_embedding_sync_failed',
					__( 'Failed to sync embedding.', 'kivor-chat-agent' )
				);
		}

		return true;
	}

	/**
	 * Delete one product embedding.
	 *
	 * @param int $product_id Product ID.
	 * @return true|WP_Error
	 */
	public function delete_product_embedding( int $product_id ) {
		$product_id = absint( $product_id );

		if ( $product_id <= 0 ) {
			return new \WP_Error(
				'kivor_chat_agent_embedding_invalid_product',
				__( 'Invalid product ID.', 'kivor-chat-agent' )
			);
		}

		return $this->vector_store->delete( 'product', $product_id );
	}

	/**
	 * Sync one product with one provider.
	 *
	 * @param WC_Product               $product      Product.
	 * @param string                   $text         Embedding text.
	 * @param string                   $content_hash Content hash.
	 * @param string                   $provider_key Provider key.
	 * @param Kivor_Embedding_Provider $provider     Provider instance.
	 * @return true|WP_Error
	 */
	private function sync_product_with_provider( \WC_Product $product, string $text, string $content_hash, string $provider_key, Kivor_Embedding_Provider $provider ) {
		$ensure = $this->ensure_store_for_provider( $provider );
		if ( is_wp_error( $ensure ) ) {
			return $ensure;
		}

		$embedding = $provider->generate_embedding( $text );
		if ( is_wp_error( $embedding ) ) {
			return $embedding;
		}

		$metadata = $this->build_product_metadata( $product, $provider_key );

		return $this->vector_store->upsert(
			'product',
			$product->get_id(),
			$embedding,
			$metadata,
			$content_hash
		);
	}

	/**
	 * Ensure vector store is ready for provider dimensions.
	 *
	 * @param Kivor_Embedding_Provider $provider Provider.
	 * @return true|WP_Error
	 */
	private function ensure_store_for_provider( Kivor_Embedding_Provider $provider ) {
		if ( $this->vector_store instanceof Kivor_Qdrant_Store ) {
			return $this->vector_store->ensure_collection( $provider->get_dimensions() );
		}

		return true;
	}

	/**
	 * Validate provider dimensions for current vector store.
	 *
	 * Non-local stores typically require one fixed dimension.
	 *
	 * @return true|WP_Error
	 */
	private function validate_provider_dimensions_for_store() {
		if ( 'local' === $this->vector_store_type ) {
			return true;
		}

		$providers = $this->get_sync_providers();
		if ( count( $providers ) <= 1 ) {
			return true;
		}

		$dimensions = array();
		foreach ( $providers as $provider ) {
			$dimensions[] = $provider->get_dimensions();
		}

		$dimensions = array_values( array_unique( array_map( 'absint', $dimensions ) ) );
		if ( count( $dimensions ) > 1 ) {
			return new \WP_Error(
				'kivor_chat_agent_embedding_dimension_mismatch',
				__( 'Enabled embedding providers use different dimensions. Use one provider (or matching-dimension models) with this vector store.', 'kivor-chat-agent' )
			);
		}

		return true;
	}

	/**
	 * Get providers to use for sync.
	 *
	 * @return array<string, Kivor_Embedding_Provider>
	 */
	private function get_sync_providers(): array {
		return array(
			$this->active_provider => $this->primary_provider,
		);
	}

	/**
	 * Build product metadata for vector storage.
	 *
	 * @param WC_Product $product      Product.
	 * @param string     $provider_key Provider key.
	 * @return array
	 */
	private function build_product_metadata( \WC_Product $product, string $provider_key ): array {
		$metadata = array(
			'_object_type' => 'product',
			'_object_id'   => $product->get_id(),
			'title'        => (string) $product->get_name(),
			'sku'          => (string) $product->get_sku(),
			'provider'     => sanitize_key( $provider_key ),
			'synced_at'    => gmdate( 'Y-m-d H:i:s' ),
		);

		$price = $product->get_price();
		if ( '' !== (string) $price ) {
			$metadata['price'] = (string) $price;
		}

		$categories = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) );
		if ( is_array( $categories ) && ! empty( $categories ) ) {
			$metadata['categories'] = implode( ', ', array_map( 'sanitize_text_field', $categories ) );
		}

		$tags = wp_get_post_terms( $product->get_id(), 'product_tag', array( 'fields' => 'names' ) );
		if ( is_array( $tags ) && ! empty( $tags ) ) {
			$metadata['tags'] = implode( ', ', array_map( 'sanitize_text_field', $tags ) );
		}

		return $metadata;
	}

	/**
	 * Build normalized product text for embedding.
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	private function build_product_embedding_text( \WC_Product $product ): string {
		$parts = array();

		$name = trim( (string) $product->get_name() );
		if ( '' !== $name ) {
			$parts[] = 'Product: ' . $name;
		}

		$sku = trim( (string) $product->get_sku() );
		if ( '' !== $sku ) {
			$parts[] = 'SKU: ' . $sku;
		}

		$short_description = trim( wp_strip_all_tags( (string) $product->get_short_description() ) );
		if ( '' !== $short_description ) {
			$parts[] = 'Short Description: ' . $short_description;
		}

		$description = trim( wp_strip_all_tags( (string) $product->get_description() ) );
		if ( '' !== $description ) {
			$parts[] = 'Description: ' . $description;
		}

		$price = $product->get_price();
		if ( '' !== (string) $price ) {
			$parts[] = 'Price: ' . $price;
		}

		$categories = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) );
		if ( is_array( $categories ) && ! empty( $categories ) ) {
			$parts[] = 'Categories: ' . implode( ', ', array_map( 'sanitize_text_field', $categories ) );
		}

		$tags = wp_get_post_terms( $product->get_id(), 'product_tag', array( 'fields' => 'names' ) );
		if ( is_array( $tags ) && ! empty( $tags ) ) {
			$parts[] = 'Tags: ' . implode( ', ', array_map( 'sanitize_text_field', $tags ) );
		}

		$text = implode( "\n\n", $parts );
		if ( mb_strlen( $text, 'UTF-8' ) > 32000 ) {
			$text = mb_substr( $text, 0, 32000, 'UTF-8' );
		}

		return $text;
	}

	/**
	 * Delete vector records for products no longer in the catalog.
	 *
	 * @param array $current_product_ids Current published product IDs.
	 * @return int|WP_Error
	 */
	private function delete_missing_products( array $current_product_ids ) {
		$stored_ids = $this->vector_store->get_stored_ids( 'product' );
		if ( empty( $stored_ids ) ) {
			return 0;
		}

		$current_lookup = array_fill_keys( array_map( 'absint', $current_product_ids ), true );
		$deleted        = 0;

		foreach ( array_map( 'absint', $stored_ids ) as $stored_id ) {
			if ( isset( $current_lookup[ $stored_id ] ) ) {
				continue;
			}

			$result = $this->vector_store->delete( 'product', $stored_id );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$deleted++;
		}

		return $deleted;
	}

	/**
	 * Build signature used in content hash for provider-aware resync.
	 *
	 * @return string
	 */
	private function get_sync_provider_signature(): string {
		$provider_keys = array_keys( $this->get_sync_providers() );
		sort( $provider_keys );

		return implode( ',', array_map( 'sanitize_key', $provider_keys ) );
	}

	/**
	 * Find key for a provider instance in enabled provider map.
	 *
	 * @param Kivor_Embedding_Provider $provider Provider instance.
	 * @return string
	 */
	private function find_provider_key_by_instance( Kivor_Embedding_Provider $provider ): string {
		foreach ( $this->all_enabled_providers as $key => $instance ) {
			if ( $instance === $provider ) {
				return (string) $key;
			}
		}

		return '';
	}
}
