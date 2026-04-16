<?php
/**
 * Core plugin class.
 *
 * Singleton that boots all plugin components.
 *
 * @package KivorAgent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class Kivor_Agent {

    /**
     * Singleton instance.
     *
     * @var Kivor_Agent|null
     */
    private static ?Kivor_Agent $instance = null;

    /**
     * Whether WooCommerce is active.
     *
     * @var bool
     */
    private bool $woocommerce_active = false;

    /**
     * Settings instance.
     *
     * @var Kivor_Settings
     */
    private Kivor_Settings $settings;

    /**
     * Get the singleton instance.
     *
     * @return Kivor_Agent
     */
    public static function instance(): Kivor_Agent {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor. Private to enforce singleton.
     */
	private function __construct() {
		$this->woocommerce_active = class_exists( 'WooCommerce' );
		$this->settings           = new Kivor_Settings();
		Kivor_Form_Manager::instance( $this->settings );

		$this->init_hooks();
	}

    /**
     * Register all hooks.
     */
    private function init_hooks(): void {
        // Initialize REST API endpoints.
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

        // Admin hooks.
        if ( is_admin() ) {
            $this->init_admin();
        }

        // Frontend hooks.
        if ( ! is_admin() || wp_doing_ajax() ) {
            $this->init_frontend();
        }

        // GDPR hooks (always register for privacy tools).
        $this->init_gdpr();

		// Knowledge Base hooks (works with or without WooCommerce).
		$this->init_knowledge_base();
		$this->init_external_platforms();

        // WooCommerce-specific hooks.
        if ( $this->woocommerce_active ) {
            $this->init_woocommerce_hooks();
        }

		// Cron hooks.
		add_action( 'kivor_chat_agent_daily_cleanup', array( $this, 'run_daily_cleanup' ) );
	}

    /**
     * Initialize admin components.
     */
    private function init_admin(): void {
        $admin = new Kivor_Admin( $this->settings );
        $admin->init();
    }

    /**
     * Initialize frontend components.
     */
    private function init_frontend(): void {
        $frontend = new Kivor_Frontend( $this->settings );
        $frontend->init();
    }

    /**
     * Initialize GDPR components.
     *
     * Privacy exporters and erasers are ALWAYS registered (required by WordPress
     * for Tools > Export/Erase Personal Data to work). The GDPR "enabled" setting
     * only controls frontend consent requirements and privacy policy text.
     */
    private function init_gdpr(): void {
        $gdpr = new Kivor_GDPR( $this->settings );
        $gdpr->init();
    }

    /**
     * Initialize Knowledge Base.
     *
     * Hooks the `kivor_chat_agent_kb_context` filter so the chat handler can retrieve
     * relevant KB articles when building the AI prompt. Works independently
     * of WooCommerce.
     */
	private function init_knowledge_base(): void {
		$kb = new Kivor_Knowledge_Base( $this->settings );
		$kb->init();
	}

	/**
	 * Initialize external platform sync.
	 *
	 * @return void
	 */
	private function init_external_platforms(): void {
		$kb   = new Kivor_Knowledge_Base( $this->settings );
		$sync = new Kivor_External_Platform_Sync( $this->settings, $kb );
		$sync->init();

		add_action( 'kivor_chat_agent_process_kb_import_job', array( $this, 'handle_process_kb_import_job' ) );

		add_action( 'save_post_post', array( $this, 'on_wordpress_content_saved' ), 10, 3 );
		add_action( 'save_post_page', array( $this, 'on_wordpress_content_saved' ), 10, 3 );
		add_action( 'before_delete_post', array( $this, 'on_wordpress_content_deleted' ) );
	}

	/**
	 * Handle WP post/page save for on-save sync trigger.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @param bool     $update  Whether updating.
	 * @return void
	 */
	public function on_wordpress_content_saved( int $post_id, $post, bool $update ): void {
		unset( $update );

		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return;
		}

		$wp_cfg = $this->settings->get( 'external_platforms.wordpress', array() );
		if ( empty( $wp_cfg['enabled'] ) || 'on_save' !== ( $wp_cfg['trigger'] ?? '' ) ) {
			return;
		}

		$wp_sync = new Kivor_WP_Content_Sync( $this->settings );
		$article = $wp_sync->fetch_single_article( $post_id );

		if ( empty( $article ) ) {
			return;
		}

		$kb = new Kivor_Knowledge_Base( $this->settings );
		$kb->upsert_external_articles( array( $article ), true );
	}

	/**
	 * Handle WP post/page delete for on-save sync trigger.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function on_wordpress_content_deleted( int $post_id ): void {
		$post_type = get_post_type( $post_id );
		if ( ! in_array( $post_type, array( 'post', 'page' ), true ) ) {
			return;
		}

		$wp_cfg = $this->settings->get( 'external_platforms.wordpress', array() );
		if ( empty( $wp_cfg['enabled'] ) || 'on_save' !== ( $wp_cfg['trigger'] ?? '' ) ) {
			return;
		}

		$source_type = 'page' === $post_type ? 'wp_page' : 'wp_post';
		$kb          = new Kivor_Knowledge_Base( $this->settings );
		$kb->delete_external_article_by_source( $source_type, (string) $post_id );
	}

	/**
	 * Process async knowledge import job.
	 *
	 * @param string $job_id Job ID.
	 * @return void
	 */
	public function handle_process_kb_import_job( string $job_id ): void {
		$job_id = sanitize_text_field( $job_id );
		if ( '' === $job_id ) {
			return;
		}

		$kb      = new Kivor_Knowledge_Base( $this->settings );
		$manager = new Kivor_Knowledge_Import_Manager( $this->settings, $kb );
		$manager->process_import_job( $job_id );
	}

    /**
     * Register WooCommerce-specific hooks.
     *
     * Initializes hybrid product search and handles automatic embedding
     * sync when products are created, updated, or deleted.
     */
	private function init_woocommerce_hooks(): void {
        // Initialize hybrid search (keyword + semantic).
        // This wires up the `kivor_chat_agent_product_search` filter used by the chat handler.
        $hybrid_search = new Kivor_Hybrid_Search( $this->settings );
        $hybrid_search->init();

		$embedding_settings = $this->settings->get( 'embedding' );

        // Initialize semantic search if an embedding provider is configured.
        // This hooks the `kivor_chat_agent_semantic_search` filter that Kivor_Hybrid_Search calls.
        if ( ! empty( $embedding_settings['active_provider'] ) ) {
            $semantic_search = Kivor_Semantic_Search::from_settings( $this->settings );
            if ( $semantic_search ) {
                $semantic_search->init();
            }
        }

        if ( ! empty( $embedding_settings['sync_on_product_save'] ) ) {
            add_action( 'woocommerce_update_product', array( $this, 'on_product_updated' ), 10, 2 );
            add_action( 'woocommerce_new_product', array( $this, 'on_product_updated' ), 10, 2 );
            add_action( 'before_delete_post', array( $this, 'on_product_deleted' ) );
            add_action( 'woocommerce_trash_product', array( $this, 'on_product_deleted' ) );
        }

        // Register cron action handlers for async embedding sync/delete.
        // The events are scheduled by on_product_updated() and on_product_deleted(),
        // but we always register handlers so pending events execute even if settings change.
		add_action( 'kivor_chat_agent_sync_product', array( $this, 'handle_sync_product' ) );
		add_action( 'kivor_chat_agent_delete_embedding', array( $this, 'handle_delete_embedding' ) );

		add_action( 'woocommerce_checkout_create_order', array( $this, 'attach_session_id_to_order' ), 10, 2 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'handle_order_completed' ) );
	}

	/**
	 * Attach tracked chat session ID to order meta at checkout.
	 *
	 * @param WC_Order $order Order object.
	 * @param array    $data  Checkout data.
	 * @return void
	 */
	public function attach_session_id_to_order( $order, array $data ): void {
		unset( $data );

		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		$wc = WC();
		if ( ! $wc || ! isset( $wc->session ) || ! $wc->session ) {
			return;
		}

		$session_id = (string) $wc->session->get( 'kivor_chat_agent_session_id', '' );
		if ( '' === trim( $session_id ) ) {
			return;
		}

		if ( $order instanceof \WC_Order ) {
			$order->update_meta_data( '_kivor_chat_agent_session_id', Kivor_Sanitizer::sanitize_session_id( $session_id ) );
		}
	}

	/**
	 * Track attributed purchases when an order is completed.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function handle_order_completed( int $order_id ): void {
		if ( ! class_exists( 'WC_Order' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$session_id = (string) $order->get_meta( '_kivor_chat_agent_session_id', true );
		if ( '' === trim( $session_id ) ) {
			return;
		}

		$tracker = new Kivor_Conversion_Tracker( $this->settings );
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$product_id = absint( $item->get_product_id() );
			$line_total = (float) $item->get_total();
			$tracker->track_purchase_if_attributed( $session_id, $product_id, $line_total );
		}
	}

    /**
     * Register REST API routes.
     */
	public function register_rest_routes(): void {
		// Chat endpoints.
		$chat_controller = new Kivor_Rest_Chat( $this->settings );
		$chat_controller->register_routes();

        // Admin settings endpoints.
        if ( current_user_can( 'manage_options' ) ) {
            $admin_api = new Kivor_Rest_Admin( $this->settings );
            $admin_api->register_routes();
        }
    }

    /**
     * Handle product update/create for embedding sync.
     *
     * @param int         $product_id Product ID.
     * @param \WC_Product $product    Product object.
     */
    public function on_product_updated( int $product_id, $product = null ): void {
        if ( 'publish' !== get_post_status( $product_id ) ) {
            return;
        }

        // Schedule async sync to avoid slowing down the save.
        if ( false === wp_next_scheduled( 'kivor_chat_agent_sync_product', array( $product_id ) ) ) {
            wp_schedule_single_event( time() + 10, 'kivor_chat_agent_sync_product', array( $product_id ) );
        }
    }

    /**
     * Handle product deletion for embedding cleanup.
     *
     * @param int $post_id Post ID.
     */
    public function on_product_deleted( int $post_id ): void {
        if ( 'product' !== get_post_type( $post_id ) ) {
            return;
        }

        // Schedule async deletion from vector store.
        if ( false === wp_next_scheduled( 'kivor_chat_agent_delete_embedding', array( $post_id ) ) ) {
            wp_schedule_single_event( time() + 5, 'kivor_chat_agent_delete_embedding', array( $post_id ) );
        }
    }

    /**
     * Cron handler: sync a single product embedding.
     *
     * Called asynchronously after a product is created or updated.
     *
     * @param int $product_id Product ID to sync.
     */
	public function handle_sync_product( int $product_id ): void {
		try {
			$sync_manager = Kivor_Sync_Manager::from_settings( $this->settings );

			if ( is_wp_error( $sync_manager ) ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging.
					error_log( 'Kivor Chat Agent: Embedding sync manager setup failed: ' . $sync_manager->get_error_message() );
				}
				return;
			}

			if ( ! $sync_manager ) {
				return; // Embeddings not configured.
			}

            $sync_manager->sync_product( $product_id );
        } catch ( \Exception $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging.
				error_log( 'Kivor Chat Agent: Failed to sync product embedding #' . $product_id . ': ' . $e->getMessage() );
			}
        }
    }

    /**
     * Cron handler: delete a single product embedding.
     *
     * Called asynchronously after a product is deleted or trashed.
     *
     * @param int $product_id Product ID whose embedding should be removed.
     */
	public function handle_delete_embedding( int $product_id ): void {
		try {
			$sync_manager = Kivor_Sync_Manager::from_settings( $this->settings );

			if ( is_wp_error( $sync_manager ) ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging.
					error_log( 'Kivor Chat Agent: Embedding delete manager setup failed: ' . $sync_manager->get_error_message() );
				}
				return;
			}

			if ( ! $sync_manager ) {
				return; // Embeddings not configured.
			}

            $sync_manager->delete_product_embedding( $product_id );
        } catch ( \Exception $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging.
				error_log( 'Kivor Chat Agent: Failed to delete product embedding #' . $product_id . ': ' . $e->getMessage() );
			}
        }
    }

    /**
     * Run daily cleanup tasks (chat logs, rate limits, consent records).
     */
    public function run_daily_cleanup(): void {
        global $wpdb;

        $chat_log_settings = $this->settings->get( 'chat_logs' );
        $gdpr_settings     = $this->settings->get( 'gdpr' );

        // Clean up old chat logs.
        $cleanup_days = absint( $chat_log_settings['auto_cleanup_days'] ?? 0 );
		if ( $cleanup_days > 0 ) {
			// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
			$logger = new Kivor_Logger( $this->settings );
			$logger->cleanup_older_than( $cleanup_days );

			$conversion_table = $wpdb->prefix . 'kivor_conversion_events';
			// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$conversion_table} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
					$cleanup_days
				)
			);
		}

// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
        // Clean up old consent records based on GDPR retention.
        $retention_days = absint( $gdpr_settings['data_retention_days'] ?? 0 );
        if ( $retention_days > 0 ) {
            $consent_table = $wpdb->prefix . 'kivor_consent_log';
// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$consent_table} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                    $retention_days
                )
            );
        }
    }

    /**
     * Check if WooCommerce is active.
     *
     * @return bool
     */
    public function is_woocommerce_active(): bool {
        return $this->woocommerce_active;
    }

    /**
     * Get the settings instance.
     *
     * @return Kivor_Settings
     */
    public function get_settings(): Kivor_Settings {
        return $this->settings;
    }

    /**
     * Prevent cloning.
     */
    private function __clone() {}

    /**
     * Prevent unserialization.
     */
    public function __wakeup() {
        throw new \Exception( 'Cannot unserialize singleton' );
    }
}
