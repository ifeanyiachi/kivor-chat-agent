<?php
/**
 * REST API controller for admin endpoints.
 *
 * Provides:
 * - GET    /kivor-chat-agent/v1/admin/settings           — Retrieve all settings.
 * - POST   /kivor-chat-agent/v1/admin/settings           — Update a settings group.
 * - POST   /kivor-chat-agent/v1/admin/test-connection     — Test AI provider connection.
 * - POST   /kivor-chat-agent/v1/admin/sync-embeddings     — Trigger full embedding sync.
 * - GET    /kivor-chat-agent/v1/admin/sync-status          — Check sync progress.
 * - GET    /kivor-chat-agent/v1/admin/logs                 — Paginated chat logs.
 * - POST   /kivor-chat-agent/v1/admin/logs/export          — CSV export of logs.
 * - DELETE /kivor-chat-agent/v1/admin/logs                 — Clear all logs.
 * - GET    /kivor-chat-agent/v1/admin/knowledge-base       — List KB articles.
 * - POST   /kivor-chat-agent/v1/admin/knowledge-base       — Create/update KB article.
 * - DELETE /kivor-chat-agent/v1/admin/knowledge-base/(?P<id>\d+) — Delete KB article.
 * - POST   /kivor-chat-agent/v1/admin/knowledge-base/scrape — Scrape URL into KB.
 *
 * All endpoints require `manage_options` capability.
 *
 * @package KivorAgent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class Kivor_Rest_Admin {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const NAMESPACE = 'kivor-chat-agent/v1';

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
	 * Register REST routes.
	 */
	public function register_routes(): void {

		// ----- Settings -----

		register_rest_route( self::NAMESPACE, '/admin/settings', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_settings' ),
				'permission_callback' => array( $this, 'check_admin' ),
			),
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'update_settings' ),
				'permission_callback' => array( $this, 'check_admin' ),
				'args'                => array(
					'group'  => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'values' => array(
						'required' => true,
						'type'     => 'object',
					),
				),
			),
		) );

		// ----- AI Provider -----

		register_rest_route( self::NAMESPACE, '/admin/test-connection', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'test_connection' ),
			'permission_callback' => array( $this, 'check_admin' ),
			'args'                => array(
				'provider' => array(
					'required'          => true,
					'type'              => 'string',
					'enum'              => array( 'openai', 'gemini', 'openrouter' ),
					'sanitize_callback' => 'sanitize_text_field',
				),
				'api_key'  => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'model'    => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		// ----- Embeddings (stubs for Phase 7) -----

		register_rest_route( self::NAMESPACE, '/admin/sync-embeddings', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'sync_embeddings' ),
			'permission_callback' => array( $this, 'check_admin' ),
		) );

		register_rest_route( self::NAMESPACE, '/admin/sync-status', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $this, 'get_sync_status' ),
			'permission_callback' => array( $this, 'check_admin' ),
		) );

		register_rest_route( self::NAMESPACE, '/admin/test-vector-store', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'test_vector_store_connection' ),
			'permission_callback' => array( $this, 'check_admin' ),
			'args'                => array(
				'store_type' => array(
					'required'          => true,
					'type'              => 'string',
					'enum'              => array( 'pinecone', 'qdrant' ),
					'sanitize_callback' => 'sanitize_key',
				),
				'config'     => array(
					'required' => false,
					'type'     => 'object',
				),
			),
		) );

		register_rest_route( self::NAMESPACE, '/admin/test-embedding-provider', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'test_embedding_provider_connection' ),
			'permission_callback' => array( $this, 'check_admin' ),
			'args'                => array(
				'provider' => array(
					'required'          => true,
					'type'              => 'string',
					'enum'              => array( 'openai', 'gemini', 'openrouter', 'cohere' ),
					'sanitize_callback' => 'sanitize_key',
				),
				'api_key'  => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'model'    => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'endpoint' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
				),
				'deployment' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'api_version' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		register_rest_route( self::NAMESPACE, '/admin/embeddings', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $this, 'get_embeddings' ),
			'permission_callback' => array( $this, 'check_admin' ),
			'args'                => array(
				'page'     => array(
					'default'           => 1,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'per_page' => array(
					'default'           => 20,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			),
		) );

		register_rest_route( self::NAMESPACE, '/admin/embeddings/sync', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'sync_single_embedding' ),
			'permission_callback' => array( $this, 'check_admin' ),
		) );

		register_rest_route( self::NAMESPACE, '/admin/embeddings/sync-bulk', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'sync_bulk_embeddings' ),
			'permission_callback' => array( $this, 'check_admin' ),
		) );

		register_rest_route( self::NAMESPACE, '/admin/embeddings/sync-products', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'sync_all_product_embeddings' ),
			'permission_callback' => array( $this, 'check_admin' ),
		) );

		register_rest_route( self::NAMESPACE, '/admin/embeddings/sync-kb', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'sync_all_kb_embeddings' ),
			'permission_callback' => array( $this, 'check_admin' ),
		) );

		register_rest_route( self::NAMESPACE, '/admin/embeddings/delete', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'delete_single_embedding' ),
			'permission_callback' => array( $this, 'check_admin' ),
		) );

		register_rest_route( self::NAMESPACE, '/admin/embeddings/delete-bulk', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'delete_bulk_embeddings' ),
			'permission_callback' => array( $this, 'check_admin' ),
		) );

		// ----- Chat Logs -----

		register_rest_route( self::NAMESPACE, '/admin/logs', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_logs' ),
				'permission_callback' => array( $this, 'check_admin' ),
				'args'                => array(
					'page'       => array(
						'default'           => 1,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'per_page'   => array(
						'default'           => 50,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'session_id' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'date_from'  => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'date_to'    => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			),
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'clear_logs' ),
				'permission_callback' => array( $this, 'check_admin' ),
				'args'                => array(
					'confirm' => array(
						'required'          => true,
						'type'              => 'boolean',
						'description'       => __( 'Confirmation that you want to delete all logs.', 'kivor-chat-agent' ),
						'validate_callback' => function ( $value ) {
							return true === filter_var( $value, FILTER_VALIDATE_BOOLEAN );
						},
					),
				),
			),
		) );

		register_rest_route( self::NAMESPACE, '/admin/logs/export', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'export_logs' ),
			'permission_callback' => array( $this, 'check_admin' ),
		) );

		// ----- Knowledge Base -----

		register_rest_route( self::NAMESPACE, '/admin/knowledge-base', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_kb_articles' ),
				'permission_callback' => array( $this, 'check_admin' ),
				'args'                => array(
					'page'     => array(
						'default'           => 1,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'per_page' => array(
						'default'           => 20,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			),
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'save_kb_article' ),
				'permission_callback' => array( $this, 'check_admin' ),
				'args'                => array(
					'id'      => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'title'   => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'content' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'wp_kses_post',
					),
					'source_type' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'source_id' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'source_url' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'esc_url_raw',
					),
					'import_method' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'sync_interval' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			),
		) );

		register_rest_route( self::NAMESPACE, '/admin/knowledge-base/(?P<id>\d+)', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_kb_article' ),
				'permission_callback' => array( $this, 'check_admin' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			),
			array(
			'methods'             => \WP_REST_Server::DELETABLE,
			'callback'            => array( $this, 'delete_kb_article' ),
			'permission_callback' => array( $this, 'check_admin' ),
			'args'                => array(
				'id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			),
			),
		) );

		register_rest_route( self::NAMESPACE, '/admin/knowledge-base/scrape', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'scrape_url' ),
			'permission_callback' => array( $this, 'check_admin' ),
			'args'                => array(
				'url' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
					'validate_callback' => function ( $value ) {
						if ( ! filter_var( $value, FILTER_VALIDATE_URL ) ) {
							return new \WP_Error( 'kivor_chat_agent_invalid_url', __( 'Please enter a valid URL.', 'kivor-chat-agent' ) );
						}
						return true;
					},
				),
			),
		) );

		register_rest_route( self::NAMESPACE, '/admin/knowledge-base/scan-source', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'scan_kb_source' ),
			'permission_callback' => array( $this, 'check_admin' ),
			'args'                => array(
				'source_type' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				),
			),
		) );

		register_rest_route( self::NAMESPACE, '/admin/knowledge-base/import-source', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'import_kb_source' ),
			'permission_callback' => array( $this, 'check_admin' ),
			'args'                => array(
				'source_type'   => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				),
				'items'         => array(
					'required' => true,
					'type'     => 'array',
				),
				'import_method' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				),
				'sync_interval' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				),
				'confirm_overwrite' => array(
					'required'          => false,
					'type'              => 'boolean',
				),
			),
		) );

		register_rest_route( self::NAMESPACE, '/admin/knowledge-base/import-status', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $this, 'get_kb_import_status' ),
			'permission_callback' => array( $this, 'check_admin' ),
			'args'                => array(
				'job_id' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		register_rest_route( self::NAMESPACE, '/admin/knowledge-base/manual-review', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $this, 'get_kb_manual_review_queue' ),
			'permission_callback' => array( $this, 'check_admin' ),
		) );

		// ----- External Platforms -----

		register_rest_route( self::NAMESPACE, '/admin/external-platforms/test-connection', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'test_external_platform_connection' ),
			'permission_callback' => array( $this, 'check_admin' ),
			'args'                => array(
				'platform' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				),
				'config'   => array(
					'required' => true,
					'type'     => 'object',
				),
			),
		) );

		register_rest_route( self::NAMESPACE, '/admin/external-platforms/sync', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'sync_external_platform' ),
			'permission_callback' => array( $this, 'check_admin' ),
			'args'                => array(
				'platform' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				),
				'mode'     => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				),
			),
		) );


		// ----- Forms -----

		register_rest_route( self::NAMESPACE, '/admin/forms', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_forms' ),
				'permission_callback' => array( $this, 'check_admin' ),
			),
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_form' ),
				'permission_callback' => array( $this, 'check_admin' ),
			),
		) );

		register_rest_route( self::NAMESPACE, '/admin/forms/(?P<id>\d+)', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_form' ),
				'permission_callback' => array( $this, 'check_admin' ),
			),
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_form' ),
				'permission_callback' => array( $this, 'check_admin' ),
			),
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_form' ),
				'permission_callback' => array( $this, 'check_admin' ),
			),
		) );

		register_rest_route( self::NAMESPACE, '/admin/forms/submissions', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $this, 'get_form_submissions' ),
			'permission_callback' => array( $this, 'check_admin' ),
		) );

		register_rest_route( self::NAMESPACE, '/admin/forms/submissions/export', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $this, 'export_form_submissions' ),
			'permission_callback' => array( $this, 'check_admin' ),
		) );
	}

	// =========================================================================
	// Permission callback
	// =========================================================================

	/**
	 * Check that the current user is an admin, verify nonce, and check rate limits.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return true|WP_Error
	 */
	public function check_admin( \WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'kivor_chat_agent_forbidden',
				__( 'You do not have permission to access this resource.', 'kivor-chat-agent' ),
				array( 'status' => 403 )
			);
		}
		
		// Verify nonce for CSRF protection
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_Error(
				'kivor_chat_agent_invalid_nonce',
				__( 'Invalid security token. Please refresh the page and try again.', 'kivor-chat-agent' ),
				array( 'status' => 403 )
			);
		}
		
		return true;
	}

	// =========================================================================
	// Settings endpoints
	// =========================================================================

	/**
	 * GET /admin/settings — return all settings (mask API keys).
	 *
	 * @return \WP_REST_Response
	 */
	public function get_settings(): \WP_REST_Response {
		$all = $this->settings->get_all();

		// Mask API keys for safe transport — only show last 4 chars.
		$masked = $this->mask_sensitive_fields( $all );

		return new \WP_REST_Response( $masked, 200 );
	}

	/**
	 * POST /admin/settings — update a specific settings group.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function update_settings( \WP_REST_Request $request ) {
		$group  = $request->get_param( 'group' );
		$values = $request->get_param( 'values' );

		if ( ! is_array( $values ) ) {
			return new \WP_Error(
				'kivor_chat_agent_invalid_values',
				__( 'Values must be an object.', 'kivor-chat-agent' ),
				array( 'status' => 400 )
			);
		}

		// Validate group name against known groups.
		$valid_groups = array_keys( $this->settings->get_defaults() );
		if ( ! in_array( $group, $valid_groups, true ) ) {
			return new \WP_Error(
				'kivor_chat_agent_invalid_group',
				sprintf(
					/* translators: %s: Group name */
					__( 'Unknown settings group: %s', 'kivor-chat-agent' ),
					$group
				),
				array( 'status' => 400 )
			);
		}

		if ( Kivor_Feature_Gates::is_group_locked( $group ) ) {
			return $this->pro_required_response( __( 'This settings section is available in Pro.', 'kivor-chat-agent' ) );
		}

		// Handle partial API key updates: if a masked key is sent back,
		// preserve the existing value from the database.
		$values = $this->restore_masked_keys( $group, $values );

		$values = Kivor_Feature_Gates::enforce_group_restrictions( $group, $values, $this->settings );

		$updated = $this->settings->update_group( $group, $values );

		if ( ! $updated ) {
			return new \WP_Error(
				'kivor_chat_agent_update_failed',
				__( 'Failed to update settings.', 'kivor-chat-agent' ),
				array( 'status' => 500 )
			);
		}

		// Clear cache and return fresh settings.
		$this->settings->clear_cache();

		return new \WP_REST_Response( array(
			'success'  => true,
			'settings' => $this->mask_sensitive_fields( $this->settings->get_all() ),
		), 200 );
	}

	// =========================================================================
	// AI Provider endpoints
	// =========================================================================

	/**
	 * POST /admin/test-connection — test an AI provider's API key + model.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function test_connection( \WP_REST_Request $request ) {
		$provider = $request->get_param( 'provider' );
		$api_key  = $request->get_param( 'api_key' );
		$model    = $request->get_param( 'model' );

		// If the API key looks masked, try to use the stored one.
		if ( $this->is_masked_value( $api_key ) ) {
			$stored_key = $this->settings->get( "ai_provider.providers.{$provider}.api_key" );
			if ( ! empty( $stored_key ) ) {
				$api_key = $stored_key;
			}
		}

		if ( empty( $api_key ) ) {
			return new \WP_Error(
				'kivor_chat_agent_no_api_key',
				__( 'API key is required.', 'kivor-chat-agent' ),
				array( 'status' => 400 )
			);
		}

		$instance = Kivor_AI_Factory::create_for_test( $provider, $api_key, $model );

		if ( is_wp_error( $instance ) ) {
			return $instance;
		}

		$result = $instance->test_connection();

		if ( is_wp_error( $result ) ) {
			$error_data = $result->get_error_data();
			$details    = is_array( $error_data ) ? $error_data : array();

			return new \WP_REST_Response( array(
				'success' => false,
				'message' => $result->get_error_message(),
				'details' => $details,
			), 200 );
		}

		return new \WP_REST_Response( array(
			'success' => true,
			'message' => sprintf(
				/* translators: %s: Provider name */
				__( 'Successfully connected to %s!', 'kivor-chat-agent' ),
				ucfirst( $provider )
			),
			'details' => $result,
		), 200 );
	}

	/**
	 * POST /admin/test-vector-store — test vector store connection.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function test_vector_store_connection( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! Kivor_Feature_Gates::can_use_pro() ) {
			return $this->pro_required_response( __( 'Vector store connection tests are available in Pro.', 'kivor-chat-agent' ) );
		}

		$store_type = sanitize_key( (string) $request->get_param( 'store_type' ) );
		$config     = $request->get_param( 'config' );

		if ( is_object( $config ) ) {
			$config = (array) $config;
		}

		if ( ! is_array( $config ) ) {
			$config = array();
		}

		$embedding_settings = $this->settings->get( 'embedding', array() );

		if ( 'pinecone' === $store_type ) {
			$existing = is_array( $embedding_settings['pinecone'] ?? null ) ? $embedding_settings['pinecone'] : array();
			$submitted_key = isset( $config['api_key'] ) ? (string) $config['api_key'] : '';
			if ( '' === $submitted_key || $this->is_masked_value( $submitted_key ) ) {
				$submitted_key = (string) ( $existing['api_key'] ?? '' );
			}

			$embedding_settings['pinecone'] = array(
				'api_key'     => sanitize_text_field( $submitted_key ),
				'index_name'  => sanitize_text_field( (string) ( $config['index_name'] ?? ( $existing['index_name'] ?? '' ) ) ),
				'environment' => sanitize_text_field( (string) ( $config['environment'] ?? ( $existing['environment'] ?? '' ) ) ),
			);
		} elseif ( 'qdrant' === $store_type ) {
			$existing = is_array( $embedding_settings['qdrant'] ?? null ) ? $embedding_settings['qdrant'] : array();
			$submitted_key = isset( $config['api_key'] ) ? (string) $config['api_key'] : '';
			if ( '' === $submitted_key || $this->is_masked_value( $submitted_key ) ) {
				$submitted_key = (string) ( $existing['api_key'] ?? '' );
			}

			$embedding_settings['qdrant'] = array(
				'endpoint_url'    => esc_url_raw( (string) ( $config['endpoint_url'] ?? ( $existing['endpoint_url'] ?? '' ) ) ),
				'api_key'         => sanitize_text_field( $submitted_key ),
				'collection_name' => sanitize_text_field( (string) ( $config['collection_name'] ?? ( $existing['collection_name'] ?? 'kivor_chat_agent_products' ) ) ),
			);
		} else {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => __( 'Invalid vector store type.', 'kivor-chat-agent' ),
			), 200 );
		}

		$store = Kivor_Sync_Manager::create_vector_store( $store_type, $embedding_settings );
		if ( is_wp_error( $store ) ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => $store->get_error_message(),
			), 200 );
		}

		$result = $store->test_connection();
		if ( is_wp_error( $result ) ) {
			$raw_message   = $result->get_error_message();
			$friendly      = $raw_message;
			$raw_lowercase = strtolower( $raw_message );

			if ( 'pinecone' === $store_type ) {
				if ( false !== strpos( $raw_lowercase, 'connection reset by peer' ) || false !== strpos( $raw_lowercase, 'ssl' ) || false !== strpos( $raw_lowercase, 'curl error 35' ) ) {
					$friendly = __( 'We could reach Pinecone, but SSL/network handshake failed when contacting the index host. This usually means the host value is incorrect or your server cannot reach that host over HTTPS.', 'kivor-chat-agent' );
				} elseif ( false !== strpos( $raw_lowercase, '401' ) || false !== strpos( $raw_lowercase, 'unauthorized' ) || false !== strpos( $raw_lowercase, 'forbidden' ) ) {
					$friendly = __( 'Pinecone authentication failed. Please verify your API key has access to this index.', 'kivor-chat-agent' );
				} elseif ( false !== strpos( $raw_lowercase, '404' ) || false !== strpos( $raw_lowercase, 'not found' ) ) {
					$friendly = __( 'Pinecone index/host was not found. Check the index name and use the host shown in your Pinecone console.', 'kivor-chat-agent' );
				} elseif ( false !== strpos( $raw_lowercase, 'api key, index name, and environment' ) ) {
					$friendly = __( 'Pinecone needs API key, index name, and host/environment. Fill all fields and try again.', 'kivor-chat-agent' );
				}
			}

			return new \WP_REST_Response( array(
				'success' => false,
				'message' => $friendly,
				'details' => array(
					'raw_message' => $raw_message,
				),
			), 200 );
		}

		return new \WP_REST_Response( array(
			'success' => true,
			'message' => sprintf(
				/* translators: %s: vector store name */
				__( 'Successfully connected to %s.', 'kivor-chat-agent' ),
				'pinecone' === $store_type ? 'Pinecone' : 'Qdrant'
			),
		), 200 );
	}

	/**
	 * POST /admin/test-embedding-provider - test embedding provider connection.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function test_embedding_provider_connection( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! Kivor_Feature_Gates::can_use_pro() ) {
			return $this->pro_required_response( __( 'Embedding provider connection tests are available in Pro.', 'kivor-chat-agent' ) );
		}

		$provider = sanitize_key( (string) $request->get_param( 'provider' ) );

		if ( '' === $provider ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => __( 'Invalid embedding provider.', 'kivor-chat-agent' ),
			), 200 );
		}

		$embedding_settings = $this->settings->get( 'embedding', array() );
		$stored_config      = is_array( $embedding_settings['providers'][ $provider ] ?? null )
			? $embedding_settings['providers'][ $provider ]
			: array();

		$submitted_api_key = (string) $request->get_param( 'api_key' );
		if ( '' === $submitted_api_key || $this->is_masked_value( $submitted_api_key ) ) {
			$submitted_api_key = (string) ( $stored_config['api_key'] ?? '' );
		}

		$submitted_model = sanitize_text_field( (string) $request->get_param( 'model' ) );
		if ( '' === $submitted_model ) {
			$submitted_model = (string) ( $stored_config['model'] ?? '' );
		}

		$config = array(
			'api_key' => sanitize_text_field( $submitted_api_key ),
			'model'   => sanitize_text_field( $submitted_model ),
		);

		if ( 'azure_openai' === $provider ) {
			$submitted_endpoint = (string) $request->get_param( 'endpoint' );
			$submitted_deployment = (string) $request->get_param( 'deployment' );
			$submitted_api_version = (string) $request->get_param( 'api_version' );

			$config['endpoint'] = '' !== trim( $submitted_endpoint )
				? esc_url_raw( $submitted_endpoint )
				: (string) ( $stored_config['endpoint'] ?? '' );
			$config['deployment'] = '' !== trim( $submitted_deployment )
				? sanitize_text_field( $submitted_deployment )
				: (string) ( $stored_config['deployment'] ?? '' );
			$config['api_version'] = '' !== trim( $submitted_api_version )
				? sanitize_text_field( $submitted_api_version )
				: (string) ( $stored_config['api_version'] ?? '2023-05-15' );
		}

		$instance = Kivor_Embedding_Factory::create_for_test( $provider, $config );
		if ( is_wp_error( $instance ) ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => $instance->get_error_message(),
			), 200 );
		}

		$result = $instance->test_connection();
		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => $result->get_error_message(),
			), 200 );
		}

		return new \WP_REST_Response( array(
			'success' => true,
			'message' => sprintf(
				/* translators: %s: provider name */
				__( 'Successfully connected to %s.', 'kivor-chat-agent' ),
				(string) ( $result['provider'] ?? ucfirst( $provider ) )
			),
		), 200 );
	}

	// =========================================================================
	// Embedding endpoints
	// =========================================================================

	/**
	 * POST /admin/sync-embeddings — trigger a full embedding sync.
	 *
	 * Creates a Kivor_Sync_Manager from the current settings and runs sync_all().
	 * This can be a long-running operation, so it updates a transient with progress
	 * that the frontend can poll via GET /admin/sync-status.
	 *
	 * @return \WP_REST_Response
	 */
	public function sync_embeddings(): \WP_REST_Response {
		if ( ! Kivor_Feature_Gates::can_use_pro() ) {
			return $this->pro_required_response( __( 'Embeddings sync is available in Pro.', 'kivor-chat-agent' ) );
		}

		$embedding_settings = $this->settings->get( 'embedding' );
		$active_provider    = sanitize_key( (string) ( $embedding_settings['active_provider'] ?? 'openai' ) );
		$providers          = $embedding_settings['providers'] ?? array();
		$active_config      = is_array( $providers[ $active_provider ] ?? null ) ? $providers[ $active_provider ] : array();

		if ( '' === $active_provider || empty( $active_config['api_key'] ) ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => __( 'No embedding provider configured. Please configure an embedding provider in the Embeddings tab first.', 'kivor-chat-agent' ),
			), 200 );
		}

		if ( empty( $embedding_settings['vector_store'] ) || 'none' === $embedding_settings['vector_store'] ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => __( 'No vector store configured. Please configure a vector store in the Embeddings tab first.', 'kivor-chat-agent' ),
			), 200 );
		}

		try {
			$sync_manager = Kivor_Sync_Manager::from_settings( $this->settings );

			if ( is_wp_error( $sync_manager ) ) {
				return new \WP_REST_Response( array(
					'success' => false,
					'message' => $sync_manager->get_error_message(),
				), 200 );
			}

			// Check if a sync is already running.
			$current_status = get_transient( 'kivor_chat_agent_sync_status' );
			if ( ! empty( $current_status['syncing'] ) ) {
				return new \WP_REST_Response( array(
					'success' => false,
					'message' => __( 'A sync is already in progress. Please wait for it to complete.', 'kivor-chat-agent' ),
				), 200 );
			}

			$result = $sync_manager->sync_all();

			// Also sync KB articles.
			$kb        = new Kivor_Knowledge_Base( $this->settings );
			$kb_result = $kb->sync_all_articles();

			return new \WP_REST_Response( array(
				'success' => true,
				'message' => sprintf(
					/* translators: 1: products synced, 2: products skipped, 3: products failed, 4: KB articles synced, 5: KB articles failed */
					__( 'Sync complete. Products: %1$d synced, %2$d skipped, %3$d failed. KB articles: %4$d synced, %5$d failed.', 'kivor-chat-agent' ),
					$result['synced'] ?? 0,
					$result['skipped'] ?? 0,
					$result['errors'] ?? 0,
					$kb_result['synced'] ?? 0,
					$kb_result['errors'] ?? 0
				),
				'details' => array(
					'products' => $result,
					'kb'       => $kb_result,
				),
			), 200 );

		} catch ( \Exception $e ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: error message */
					__( 'Sync failed: %s', 'kivor-chat-agent' ),
					$e->getMessage()
				),
			), 200 );
		}
	}

	/**
	 * POST /admin/embeddings/sync-products
	 *
	 * @return \WP_REST_Response
	 */
	public function sync_all_product_embeddings(): \WP_REST_Response {
		if ( ! Kivor_Feature_Gates::can_use_pro() ) {
			return $this->pro_required_response( __( 'Product embedding sync is available in Pro.', 'kivor-chat-agent' ) );
		}

		set_transient(
			'kivor_chat_agent_sync_status',
			array(
				'syncing'  => true,
				'type'     => 'products',
				'progress' => 0,
				'total'    => 0,
				'message'  => __( 'Starting product sync...', 'kivor-chat-agent' ),
			),
			HOUR_IN_SECONDS
		);

		$sync_manager = Kivor_Sync_Manager::from_settings( $this->settings );
		if ( is_wp_error( $sync_manager ) ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => $sync_manager->get_error_message(),
			), 200 );
		}

		$result = $sync_manager->sync_all();
		$result['total'] = (int) ( $result['synced'] ?? 0 ) + (int) ( $result['skipped'] ?? 0 ) + (int) ( $result['errors'] ?? 0 );
		$errors = (int) ( $result['errors'] ?? 0 );

		if ( $errors > 0 ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => sprintf(
					/* translators: 1: synced, 2: skipped, 3: errors */
					__( 'Products sync finished with issues. %1$d synced, %2$d skipped, %3$d failed. Check Embeddings settings and provider credentials.', 'kivor-chat-agent' ),
					(int) ( $result['synced'] ?? 0 ),
					(int) ( $result['skipped'] ?? 0 ),
					$errors
				),
				'details' => $result,
			), 200 );
		}

		return new \WP_REST_Response( array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: synced, 2: skipped, 3: errors */
				__( 'Products sync complete. %1$d synced, %2$d skipped, %3$d failed.', 'kivor-chat-agent' ),
				(int) ( $result['synced'] ?? 0 ),
				(int) ( $result['skipped'] ?? 0 ),
				$errors
			),
			'details' => $result,
		), 200 );
	}

	/**
	 * POST /admin/embeddings/sync-kb
	 *
	 * @return \WP_REST_Response
	 */
	public function sync_all_kb_embeddings(): \WP_REST_Response {
		if ( ! Kivor_Feature_Gates::can_use_pro() ) {
			return $this->pro_required_response( __( 'Knowledge base embedding sync is available in Pro.', 'kivor-chat-agent' ) );
		}

		set_transient(
			'kivor_chat_agent_sync_status',
			array(
				'syncing'  => true,
				'type'     => 'kb',
				'progress' => 0,
				'total'    => 0,
				'message'  => __( 'Starting knowledge base sync...', 'kivor-chat-agent' ),
			),
			HOUR_IN_SECONDS
		);

		$kb     = new Kivor_Knowledge_Base( $this->settings );
		$result = $kb->sync_all_articles();
		$result['total'] = (int) ( $result['synced'] ?? 0 ) + (int) ( $result['skipped'] ?? 0 ) + (int) ( $result['errors'] ?? 0 );

		return new \WP_REST_Response( array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: synced KB articles, 2: failed KB articles */
				__( 'Knowledge Base sync complete. %1$d synced, %2$d failed.', 'kivor-chat-agent' ),
				(int) ( $result['synced'] ?? 0 ),
				(int) ( $result['errors'] ?? 0 )
			),
			'details' => $result,
		), 200 );
	}

	/**
	 * GET /admin/sync-status — check embedding sync progress.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_sync_status(): \WP_REST_Response {
		$status = get_transient( 'kivor_chat_agent_sync_status' );

		if ( ! $status ) {
			return new \WP_REST_Response( array(
				'syncing'  => false,
				'progress' => 0,
				'total'    => 0,
				'message'  => __( 'No sync in progress.', 'kivor-chat-agent' ),
			), 200 );
		}

		return new \WP_REST_Response( $status, 200 );
	}

	/**
	 * GET /admin/embeddings
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_embeddings( \WP_REST_Request $request ): \WP_REST_Response {
		$embedding_settings = $this->settings->get( 'embedding' );
		$per_page           = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ) );
		$page               = max( 1, (int) $request->get_param( 'page' ) );
		$offset             = ( $page - 1 ) * $per_page;

		$store = Kivor_Sync_Manager::create_vector_store( (string) ( $embedding_settings['vector_store'] ?? 'local' ), $embedding_settings );
		if ( is_wp_error( $store ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => $store->get_error_message(),
				),
				200
			);
		}

		$items = $store->get_all_vectors( '', $per_page, $offset );
		if ( is_wp_error( $items ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => $items->get_error_message(),
				),
				200
			);
		}

		$total = $store->count();

		return new \WP_REST_Response(
			array(
				'success' => true,
				'items'   => $items,
				'total'   => $total,
				'page'    => $page,
			),
			200
		);
	}

	/**
	 * POST /admin/embeddings/sync
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function sync_single_embedding( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! Kivor_Feature_Gates::can_use_pro() ) {
			return $this->pro_required_response( __( 'Embedding sync actions are available in Pro.', 'kivor-chat-agent' ) );
		}

		$object_type = sanitize_key( (string) $request->get_param( 'object_type' ) );
		$object_id   = absint( (int) $request->get_param( 'object_id' ) );

		if ( 'product' !== $object_type || $object_id <= 0 ) {
			return new \WP_REST_Response( array( 'success' => false, 'message' => __( 'Only product sync is supported for this action.', 'kivor-chat-agent' ) ), 200 );
		}

		$sync_manager = Kivor_Sync_Manager::from_settings( $this->settings );
		if ( is_wp_error( $sync_manager ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'message' => $sync_manager->get_error_message() ), 200 );
		}

		$result = $sync_manager->sync_product( $object_id );
		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'message' => $result->get_error_message() ), 200 );
		}

		return new \WP_REST_Response( array( 'success' => true, 'message' => __( 'Embedding synced.', 'kivor-chat-agent' ) ), 200 );
	}

	/**
	 * POST /admin/embeddings/sync-bulk
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function sync_bulk_embeddings( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! Kivor_Feature_Gates::can_use_pro() ) {
			return $this->pro_required_response( __( 'Embedding sync actions are available in Pro.', 'kivor-chat-agent' ) );
		}

		$items = $request->get_param( 'items' );
		if ( ! is_array( $items ) ) {
			$items = array();
		}

		$sync_manager = Kivor_Sync_Manager::from_settings( $this->settings );
		if ( is_wp_error( $sync_manager ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'message' => $sync_manager->get_error_message() ), 200 );
		}

		$ok = 0;
		foreach ( $items as $item ) {
			$type = sanitize_key( (string) ( $item['object_type'] ?? '' ) );
			$id   = absint( (int) ( $item['object_id'] ?? 0 ) );
			if ( 'product' !== $type || $id <= 0 ) {
				continue;
			}
			$result = $sync_manager->sync_product( $id );
			if ( ! is_wp_error( $result ) ) {
				$ok++;
			}
		}

		// translators: %d: number of embeddings synced.
		return new \WP_REST_Response( array( 'success' => true, 'message' => sprintf( __( '%d embeddings synced.', 'kivor-chat-agent' ), $ok ) ), 200 );
	}

	/**
	 * POST /admin/embeddings/delete
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function delete_single_embedding( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! Kivor_Feature_Gates::can_use_pro() ) {
			return $this->pro_required_response( __( 'Embedding delete actions are available in Pro.', 'kivor-chat-agent' ) );
		}

		$object_type = sanitize_key( (string) $request->get_param( 'object_type' ) );
		$object_id   = absint( (int) $request->get_param( 'object_id' ) );

		if ( '' === $object_type || $object_id <= 0 ) {
			return new \WP_REST_Response( array( 'success' => false, 'message' => __( 'Invalid object type or ID.', 'kivor-chat-agent' ) ), 200 );
		}

		$store = Kivor_Sync_Manager::create_vector_store(
			(string) ( $this->settings->get( 'embedding.vector_store', 'local' ) ),
			$this->settings->get( 'embedding', array() )
		);

		if ( is_wp_error( $store ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'message' => $store->get_error_message() ), 200 );
		}

		$result = $store->delete( $object_type, $object_id );
		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'message' => $result->get_error_message() ), 200 );
		}

		return new \WP_REST_Response( array( 'success' => true, 'message' => __( 'Embedding deleted.', 'kivor-chat-agent' ) ), 200 );
	}

	/**
	 * POST /admin/embeddings/delete-bulk
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function delete_bulk_embeddings( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! Kivor_Feature_Gates::can_use_pro() ) {
			return $this->pro_required_response( __( 'Embedding delete actions are available in Pro.', 'kivor-chat-agent' ) );
		}

		$items = $request->get_param( 'items' );
		if ( ! is_array( $items ) ) {
			$items = array();
		}

		$store = Kivor_Sync_Manager::create_vector_store(
			(string) ( $this->settings->get( 'embedding.vector_store', 'local' ) ),
			$this->settings->get( 'embedding', array() )
		);

		if ( is_wp_error( $store ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'message' => $store->get_error_message() ), 200 );
		}

		$deleted = 0;
		foreach ( $items as $item ) {
			$type = sanitize_key( (string) ( $item['object_type'] ?? '' ) );
			$id   = absint( (int) ( $item['object_id'] ?? 0 ) );
			if ( '' === $type || $id <= 0 ) {
				continue;
			}
			$result = $store->delete( $type, $id );
			if ( ! is_wp_error( $result ) ) {
				$deleted++;
			}
		}

		// translators: %d: number of embeddings deleted.
		return new \WP_REST_Response( array( 'success' => true, 'message' => sprintf( __( '%d embeddings deleted.', 'kivor-chat-agent' ), $deleted ) ), 200 );
	}

	/**
	 * POST /admin/external-platforms/test-connection.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function test_external_platform_connection( \WP_REST_Request $request ): \WP_REST_Response {
		$platform = sanitize_key( (string) $request->get_param( 'platform' ) );

		if ( ! Kivor_Feature_Gates::is_knowledge_source_available( $platform ) ) {
			return $this->pro_required_response( __( 'This knowledge source is available in Pro.', 'kivor-chat-agent' ) );
		}

		$config   = $request->get_param( 'config' );


		if ( is_object( $config ) ) {
			$config = (array) $config;
		}

		if ( ! is_array( $config ) ) {
			$config = array();
		}

		$config = $this->restore_external_platform_masked_keys( $platform, $config );

		$kb   = new Kivor_Knowledge_Base( $this->settings );
		$sync = new Kivor_External_Platform_Sync( $this->settings, $kb );
		$test = $sync->test_platform_connection( $platform, $config );

		if ( is_wp_error( $test ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => $test->get_error_message(),
				),
				200
			);
		}

		return new \WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Connection successful.', 'kivor-chat-agent' ),
			),
			200
		);
	}

	/**
	 * Restore masked secrets for external platform test payloads.
	 *
	 * @param string $platform Platform key.
	 * @param array  $config   Submitted config.
	 * @return array
	 */
	private function restore_external_platform_masked_keys( string $platform, array $config ): array {
		switch ( $platform ) {
			case 'zendesk':
				if ( isset( $config['api_token'] ) && $this->is_masked_value( (string) $config['api_token'] ) ) {
					$config['api_token'] = $this->settings->get( 'external_platforms.zendesk.api_token' ) ?? '';
				}
				break;

			case 'notion':
				if ( isset( $config['api_key'] ) && $this->is_masked_value( (string) $config['api_key'] ) ) {
					$config['api_key'] = $this->settings->get( 'external_platforms.notion.api_key' ) ?? '';
				}
				break;

		}

		return $config;
	}

	/**
	 * POST /admin/external-platforms/sync.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function sync_external_platform( \WP_REST_Request $request ): \WP_REST_Response {
		$platform = sanitize_key( (string) $request->get_param( 'platform' ) );

		if ( ! Kivor_Feature_Gates::is_knowledge_source_available( $platform ) ) {
			return $this->pro_required_response( __( 'This knowledge source is available in Pro.', 'kivor-chat-agent' ) );
		}

		$mode     = sanitize_key( (string) $request->get_param( 'mode' ) );

		$kb   = new Kivor_Knowledge_Base( $this->settings );
		$sync = new Kivor_External_Platform_Sync( $this->settings, $kb );
		$res  = $sync->sync_platform( $platform, '' !== $mode ? $mode : null, true );

		if ( is_wp_error( $res ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => $res->get_error_message(),
				),
				200
			);
		}

		$stats = $res['result'] ?? array();

		return new \WP_REST_Response(
			array(
				'success' => true,
				'message' => sprintf(
					/* translators: 1: platform, 2: created, 3: updated, 4: skipped, 5: errors */
					__( '%1$s sync complete. Created: %2$d, Updated: %3$d, Skipped: %4$d, Errors: %5$d.', 'kivor-chat-agent' ),
					ucfirst( $platform ),
					(int) ( $stats['created'] ?? 0 ),
					(int) ( $stats['updated'] ?? 0 ),
					(int) ( $stats['skipped'] ?? 0 ),
					(int) ( $stats['errors'] ?? 0 )
				),
				'details' => $res,
			),
			200
		);
	}


	// =========================================================================
	// Chat Logs endpoints
	// =========================================================================

	/**
	 * GET /admin/logs — paginated chat logs.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_logs( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$table    = $wpdb->prefix . 'kivor_chat_logs';
		$page     = max( 1, $request->get_param( 'page' ) );
		$per_page = min( max( 1, $request->get_param( 'per_page' ) ), 100 );
		$offset   = ( $page - 1 ) * $per_page;

		$where  = array();
		$params = array();

		// Optional session filter.
		$session_id = $request->get_param( 'session_id' );
		if ( ! empty( $session_id ) ) {
			$where[]  = 'session_id = %s';
			$params[] = $session_id;
		}

		// Optional date range filter.
		$date_from = $request->get_param( 'date_from' );
		if ( ! empty( $date_from ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = $date_from . ' 00:00:00';
		}

		$date_to = $request->get_param( 'date_to' );
		if ( ! empty( $date_to ) ) {
			$where[]  = 'created_at <= %s';
			$params[] = $date_to . ' 23:59:59';
		}

		$where_sql = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';

		// Total count.
		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$count_sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";
		if ( ! empty( $params ) ) {
			// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
			$count_sql = $wpdb->prepare( $count_sql, ...$params );
		}
		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$total = (int) $wpdb->get_var( $count_sql );

		// Fetch rows.
		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$query = "SELECT * FROM {$table} {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$query_params = array_merge( $params, array( $per_page, $offset ) );
		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$rows  = $wpdb->get_results( $wpdb->prepare( $query, ...$query_params ), ARRAY_A );

		// Decode metadata JSON.
		foreach ( $rows as &$row ) {
			if ( ! empty( $row['metadata'] ) ) {
				$row['metadata'] = json_decode( $row['metadata'], true );
			}
		}

		return new \WP_REST_Response( array(
			'logs'       => $rows ?? array(),
			'total'      => $total,
			'page'       => $page,
			'per_page'   => $per_page,
			'total_pages' => (int) ceil( $total / $per_page ),
		), 200 );
	}

	/**
	 * DELETE /admin/logs — clear all chat logs.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function clear_logs( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! Kivor_Feature_Gates::is_feature_available( 'analytics_insights' ) ) {
			return $this->pro_required_response( __( 'Insights actions are available in Pro.', 'kivor-chat-agent' ) );
		}

		$confirm = $request->get_param( 'confirm' );
		
		if ( ! filter_var( $confirm, FILTER_VALIDATE_BOOLEAN ) ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => __( 'Confirmation required to delete all logs.', 'kivor-chat-agent' ),
			), 400 );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'kivor_chat_logs';
		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		
		// Use DELETE instead of TRUNCATE for better safety and logging
		$deleted = $wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL

		return new \WP_REST_Response( array(
			'success' => false !== $deleted,
			'message' => false !== $deleted
				? __( 'All chat logs have been cleared.', 'kivor-chat-agent' )
				: __( 'Failed to clear chat logs.', 'kivor-chat-agent' ),
		), 200 );
	}

	/**
	 * POST /admin/logs/export — export chat logs as CSV.
	 *
	 * Returns a JSON response with base64-encoded CSV data
	 * (frontend decodes and triggers download).
	 *
	 * @return \WP_REST_Response
	 */
	public function export_logs(): \WP_REST_Response {
		if ( ! Kivor_Feature_Gates::is_feature_available( 'analytics_insights' ) ) {
			return $this->pro_required_response( __( 'Insights actions are available in Pro.', 'kivor-chat-agent' ) );
		}

		global $wpdb;
// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB

		$table = $wpdb->prefix . 'kivor_chat_logs';
		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$rows  = $wpdb->get_results(
			"SELECT session_id, role, sentiment, message, created_at FROM {$table} ORDER BY created_at ASC",
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => __( 'No logs to export.', 'kivor-chat-agent' ),
			), 200 );
		}

		// Build CSV in memory.
		$handle = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- In-memory stream.
		fputcsv( $handle, array( 'Session ID', 'Role', 'Sentiment', 'Message', 'Date' ) );

		foreach ( $rows as $row ) {
			fputcsv( $handle, array(
				$this->sanitize_csv_value( (string) $row['session_id'] ),
				$this->sanitize_csv_value( (string) $row['role'] ),
				$this->sanitize_csv_value( (string) ( $row['sentiment'] ?? '' ) ),
				$this->sanitize_csv_value( (string) $row['message'] ),
				$this->sanitize_csv_value( (string) $row['created_at'] ),
			) );
		}

		rewind( $handle );
		$csv = stream_get_contents( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing in-memory stream.

		return new \WP_REST_Response( array(
			'success'  => true,
			'filename' => 'kivor-chat-agent-logs-' . gmdate( 'Y-m-d' ) . '.csv',
			'data'     => base64_encode( $csv ),
		), 200 );
	}

	// =========================================================================
	// Knowledge Base endpoints
	// =========================================================================

	/**
	 * GET /admin/knowledge-base — list KB articles.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_kb_articles( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$table    = $wpdb->prefix . 'kivor_knowledge_base';
		$page     = max( 1, $request->get_param( 'page' ) );
		$per_page = min( max( 1, $request->get_param( 'per_page' ) ), 100 );
		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$offset   = ( $page - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY updated_at DESC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		);

		return new \WP_REST_Response( array(
			'articles'    => $rows ?? array(),
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => (int) ceil( $total / $per_page ),
		), 200 );
	}

	/**
	 * GET /admin/knowledge-base/{id} — retrieve one KB article.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function get_kb_article( \WP_REST_Request $request ) {
		global $wpdb;

		$table = $wpdb->prefix . 'kivor_knowledge_base';
		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$id    = absint( (int) $request->get_param( 'id' ) );

		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$article = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
			ARRAY_A
		);

		if ( empty( $article ) ) {
			return new \WP_Error(
				'kivor_chat_agent_article_not_found',
				__( 'Article not found.', 'kivor-chat-agent' ),
				array( 'status' => 404 )
			);
		}

		return new \WP_REST_Response( array(
			'success' => true,
			'article' => $article,
		), 200 );
	}

	/**
	 * POST /admin/knowledge-base — create or update a KB article.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function save_kb_article( \WP_REST_Request $request ) {
		global $wpdb;

		$table   = $wpdb->prefix . 'kivor_knowledge_base';
		$id      = $request->get_param( 'id' );
		$title   = $request->get_param( 'title' );
		$content = $request->get_param( 'content' );
		$source_type = sanitize_key( (string) $request->get_param( 'source_type' ) );
		$source_id   = sanitize_text_field( (string) $request->get_param( 'source_id' ) );
		$source_url  = esc_url_raw( (string) $request->get_param( 'source_url' ) );
		$import_method = sanitize_key( (string) $request->get_param( 'import_method' ) );
		$sync_interval = sanitize_key( (string) $request->get_param( 'sync_interval' ) );

		if ( '' === $source_type ) {
			$source_type = 'manual';
		}

		if ( ! in_array( $source_type, array( 'manual', 'wp_post', 'wp_page', 'zendesk', 'notion', 'webpage' ), true ) ) {
			$source_type = 'manual';
		}

		if ( ! Kivor_Feature_Gates::is_knowledge_source_available( $source_type ) ) {
			return $this->pro_required_response( __( 'This knowledge source is available in Pro.', 'kivor-chat-agent' ) );
		}

		if ( ! in_array( $import_method, array( 'manual', 'individual', 'bulk' ), true ) ) {
			$import_method = 'manual';
		}

		if ( ! in_array( $sync_interval, array( 'manual', 'hourly', 'daily', 'weekly' ), true ) ) {
			$sync_interval = 'manual';
		}

		// Enforce 5,000 character limit.
		if ( mb_strlen( $content, 'UTF-8' ) > 5000 ) {
			return new \WP_Error(
				'kivor_chat_agent_content_too_long',
				__( 'Content must not exceed 5,000 characters.', 'kivor-chat-agent' ),
				array( 'status' => 400 )
			);
		}

		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		if ( $id ) {
			// Update existing.
			// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
			$updated = $wpdb->update(
				$table,
				array(
					'title'      => $title,
					'content'    => $content,
					'source_type' => $source_type,
					'source_id' => $source_id,
					'source_url' => $source_url,
					'last_synced_at' => $now,
					'sync_status' => 'synced',
					'import_method' => $import_method,
					'sync_interval' => $sync_interval,
					'retry_count' => 0,
					'updated_at' => $now,
				),
				array( 'id' => $id ),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ),
				array( '%d' )
			);

			if ( false === $updated ) {
				return new \WP_Error(
					'kivor_chat_agent_update_failed',
					__( 'Failed to update article.', 'kivor-chat-agent' ),
					array( 'status' => 500 )
				);
			}

			$article_id = $id;
		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		} else {
			// Insert new.
			// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
			$inserted = $wpdb->insert(
				$table,
				array(
					'title'      => $title,
					'content'    => $content,
					'source_type' => $source_type,
					'source_id' => $source_id,
					'source_url' => $source_url,
					'imported_at' => $now,
					'last_synced_at' => $now,
					'sync_status' => 'synced',
					'import_method' => $import_method,
					'sync_interval' => $sync_interval,
					'retry_count' => 0,
					'created_at' => $now,
					'updated_at' => $now,
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
			);

			if ( false === $inserted ) {
				return new \WP_Error(
					'kivor_chat_agent_insert_failed',
					__( 'Failed to create article.', 'kivor-chat-agent' ),
					array( 'status' => 500 )
				);
			}

			// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
			$article_id = $wpdb->insert_id;
		}
// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB

		// Fetch the saved article.
		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$article = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $article_id ),
			ARRAY_A
		);

		// Sync KB article embedding (async-friendly, non-blocking on failure).
		$kb = new Kivor_Knowledge_Base( $this->settings );
		$embed_result = $kb->sync_article_embedding( $article_id );
		$embed_error  = is_wp_error( $embed_result ) ? $embed_result->get_error_message() : null;

		return new \WP_REST_Response( array(
			'success'     => true,
			'article'     => $article,
			'embed_error' => $embed_error,
		), $id ? 200 : 201 );
	}

	/**
	 * POST /admin/knowledge-base/scan-source — scan source content.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function scan_kb_source( \WP_REST_Request $request ): \WP_REST_Response {
		$source_type = sanitize_key( (string) $request->get_param( 'source_type' ) );

		if ( ! Kivor_Feature_Gates::is_knowledge_source_available( $source_type ) ) {
			return $this->pro_required_response( __( 'This knowledge source is available in Pro.', 'kivor-chat-agent' ) );
		}

		$kb      = new Kivor_Knowledge_Base( $this->settings );
		$manager = new Kivor_Knowledge_Import_Manager( $this->settings, $kb );
		$params  = $this->get_kb_scan_params( $source_type, $request );
		$items   = $manager->scan_source( $source_type, $params );

		if ( is_wp_error( $items ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => $items->get_error_message(),
				),
				200
			);
		}

		return new \WP_REST_Response(
			array(
				'success' => true,
				'count'   => count( $items ),
				'items'   => $items,
			),
			200
		);
	}

	/**
	 * POST /admin/knowledge-base/import-source — queue source imports.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function import_kb_source( \WP_REST_Request $request ): \WP_REST_Response {
		$source_type   = sanitize_key( (string) $request->get_param( 'source_type' ) );

		if ( ! Kivor_Feature_Gates::is_knowledge_source_available( $source_type ) ) {
			return $this->pro_required_response( __( 'This knowledge source is available in Pro.', 'kivor-chat-agent' ) );
		}

		$items         = $request->get_param( 'items' );

		$import_method = sanitize_key( (string) $request->get_param( 'import_method' ) );
		$sync_interval = sanitize_key( (string) $request->get_param( 'sync_interval' ) );
		$confirm_overwrite = (bool) $request->get_param( 'confirm_overwrite' );

		if ( ! is_array( $items ) ) {
			$items = array();
		}

		$kb      = new Kivor_Knowledge_Base( $this->settings );
		$manager = new Kivor_Knowledge_Import_Manager( $this->settings, $kb );
		$job     = $manager->start_import_job( $source_type, $items, $import_method, $sync_interval, $confirm_overwrite );

		if ( is_wp_error( $job ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => $job->get_error_message(),
				),
				200
			);
		}

		return new \WP_REST_Response(
			array(
				'success' => true,
				'job'     => $job,
			),
			200
		);
	}

	/**
	 * GET /admin/knowledge-base/import-status — background import status.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_kb_import_status( \WP_REST_Request $request ): \WP_REST_Response {
		$job_id = sanitize_text_field( (string) $request->get_param( 'job_id' ) );

		$kb      = new Kivor_Knowledge_Base( $this->settings );
		$manager = new Kivor_Knowledge_Import_Manager( $this->settings, $kb );
		$status  = $manager->get_import_job( $job_id );

		if ( empty( $status ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Import job not found.', 'kivor-chat-agent' ),
				),
				200
			);
		}

		return new \WP_REST_Response(
			array(
				'success' => true,
				'status'  => $status,
			),
			200
		);
	}

	/**
	 * GET /admin/knowledge-base/manual-review — failed queue.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_kb_manual_review_queue(): \WP_REST_Response {
		$kb      = new Kivor_Knowledge_Base( $this->settings );
		$manager = new Kivor_Knowledge_Import_Manager( $this->settings, $kb );
		$queue   = $manager->get_manual_review_queue();

		return new \WP_REST_Response(
			array(
				'success' => true,
				'items'   => $queue,
			),
			200
		);
	}

	/**
	 * DELETE /admin/knowledge-base/{id} — delete a KB article.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function delete_kb_article( \WP_REST_Request $request ) {
		global $wpdb;

		$table = $wpdb->prefix . 'kivor_knowledge_base';
		$id    = $request->get_param( 'id' );

		// Delete associated embedding from the configured vector store.
		$kb = new Kivor_Knowledge_Base( $this->settings );
		$kb->delete_article_embedding( (int) $id );

		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		// Also delete from local embeddings table directly (in case vector store is external).
		$embed_table = $wpdb->prefix . 'kivor_embeddings';
		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$wpdb->delete( $embed_table, array(
			'object_type' => 'kb_article',
			'object_id'   => $id,
		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		), array( '%s', '%d' ) );

		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$deleted = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );

		if ( false === $deleted || 0 === $deleted ) {
			return new \WP_Error(
				'kivor_chat_agent_delete_failed',
				__( 'Article not found or could not be deleted.', 'kivor-chat-agent' ),
				array( 'status' => 404 )
			);
		}

		return new \WP_REST_Response( array(
			'success' => true,
			'message' => __( 'Article deleted.', 'kivor-chat-agent' ),
		), 200 );
	}

	/**
	 * POST /admin/knowledge-base/scrape — scrape a URL and return draft content.
	 *
	 * This is a basic implementation. Phase 8 will create a dedicated
	 * Kivor_Web_Scraper class with better DOM parsing.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function scrape_url( \WP_REST_Request $request ) {
		if ( ! Kivor_Feature_Gates::is_feature_available( 'knowledge_webpage_scan' ) ) {
			return $this->pro_required_response( __( 'You have reached the free lifetime webpage scan limit for this site. Upgrade to Pro for more scans.', 'kivor-chat-agent' ) );
		}

		$raw_url = (string) $request->get_param( 'url' );
		$url     = Kivor_Sanitizer::sanitize_scrape_url( $raw_url );

		if ( false === $url ) {
			return new \WP_Error(
				'kivor_chat_agent_scrape_blocked',
				__( 'This URL is not allowed (internal or private address).', 'kivor-chat-agent' ),
				array( 'status' => 403 )
			);
		}

		$request_args = array(
			'timeout'     => 15, // Reduced timeout for security
			'redirection' => 3,  // Reduced redirects for security
			'user-agent'  => 'Mozilla/5.0 (compatible; KivorAgent/1.0)',
			'headers'     => array(
				'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
			),
			'stream'      => false,
			'blocking'    => true,
		);

		$response = wp_remote_get( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();
			if ( false !== stripos( $error_message, 'Destination directory for file streaming' ) ) {
				unset( $request_args['stream'] );
				unset( $request_args['blocking'] );
				$response = wp_remote_get( $url, $request_args );
			}
		}

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'kivor_chat_agent_scrape_failed',
				sprintf(
					/* translators: %s: Error message */
					__( 'Failed to fetch URL: %s', 'kivor-chat-agent' ),
					$response->get_error_message()
				),
				array( 'status' => 422 )
			);
		}

		// Check response size limit (5MB max)
		$content_length = wp_remote_retrieve_header( $response, 'content-length' );
		if ( $content_length && (int) $content_length > 5 * 1024 * 1024 ) {
			return new \WP_Error(
				'kivor_chat_agent_scrape_too_large',
				__( 'URL content exceeds maximum size of 5MB.', 'kivor-chat-agent' ),
				array( 'status' => 422 )
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code >= 300 ) {
			return new \WP_Error(
				'kivor_chat_agent_scrape_http_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'URL returned HTTP %d.', 'kivor-chat-agent' ),
					$status_code
				),
				array( 'status' => 422 )
			);
		}

		$html = wp_remote_retrieve_body( $response );

		if ( empty( $html ) ) {
			return new \WP_Error(
				'kivor_chat_agent_scrape_empty',
				__( 'The URL returned no content.', 'kivor-chat-agent' ),
				array( 'status' => 422 )
			);
		}

		// Extract text content from HTML.
		$content = $this->extract_text_from_html( $html );

		if ( empty( trim( $content ) ) ) {
			return new \WP_Error(
				'kivor_chat_agent_scrape_no_content',
				__( 'Could not extract text content from the URL.', 'kivor-chat-agent' ),
				array( 'status' => 422 )
			);
		}

		// Truncate to 5,000 characters.
		$content = mb_substr( $content, 0, 5000, 'UTF-8' );

		// Extract title from HTML.
		$title = '';
		if ( preg_match( '/<title[^>]*>(.*?)<\/title>/si', $html, $matches ) ) {
			$title = sanitize_text_field( html_entity_decode( $matches[1], ENT_QUOTES, 'UTF-8' ) );
		}
		if ( empty( $title ) ) {
			$title = sanitize_text_field( wp_parse_url( $url, PHP_URL_HOST ) );
		}

		// Return extracted data to editor; do not auto-save.
		Kivor_Feature_Gates::increment_webpage_scan_count();

		return new \WP_REST_Response( array(
			'success' => true,
			'article' => array(
				'id'         => 0,
				'title'      => $title,
				'content'    => $content,
				'source_url' => $url,
			),
		), 200 );
	}

	// =========================================================================
	// Helper methods
	// =========================================================================

	/**
	 * Extract readable text from HTML content.
	 *
	 * Attempts to find <main>, <article>, or <body> content.
	 * Falls back to full body text extraction.
	 *
	 * @param string $html Raw HTML.
	 * @return string Extracted text.
	 */
	private function extract_text_from_html( string $html ): string {
		// Prefer DOM parsing over regex (safer for nested div-heavy builders like Elementor).
		if ( class_exists( '\DOMDocument' ) ) {
			$dom = new \DOMDocument();
			libxml_use_internal_errors( true );
			$loaded = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR );
			libxml_clear_errors();

			if ( $loaded ) {
				$xpath = new \DOMXPath( $dom );

				// Remove non-content elements.
				$remove_nodes = $xpath->query( '//script|//style|//noscript|//svg|//nav|//footer|//header' );
				if ( $remove_nodes ) {
					foreach ( $remove_nodes as $node ) {
						if ( $node->parentNode ) {
							$node->parentNode->removeChild( $node );
						}
					}
				}

				// High priority candidates.
				$candidate_queries = array(
					'//main',
					'//article',
					'//*[@role="main"]',
					'//*[contains(@class,"elementor-widget-container")]',
					'//*[contains(@class,"elementor") and contains(@class,"content")]',
					'//*[contains(@id,"content")]',
					'//*[contains(@class,"content")]',
					'//body',
				);

				foreach ( $candidate_queries as $query ) {
					$nodes = $xpath->query( $query );
					if ( ! $nodes || 0 === $nodes->length ) {
						continue;
					}

					$pieces = array();
					foreach ( $nodes as $node ) {
						$text = trim( wp_strip_all_tags( $node->textContent ?? '' ) );
						if ( '' !== $text ) {
							$pieces[] = $text;
						}
					}

					if ( ! empty( $pieces ) ) {
						$joined = implode( "\n\n", $pieces );
						$joined = $this->normalize_scraped_text( $joined );

						// Skip tiny fragments and keep looking.
						if ( strlen( $joined ) >= 80 ) {
							return $joined;
						}
					}
				}
			}
		}

		// Fallback 1: simple strip tags on full HTML.
		$text = $this->normalize_scraped_text( wp_strip_all_tags( $html ) );
		if ( strlen( $text ) >= 80 ) {
			return $text;
		}

		// Fallback 2: meta description if present.
		if ( preg_match( '/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m ) ) {
			return $this->normalize_scraped_text( html_entity_decode( (string) $m[1], ENT_QUOTES, 'UTF-8' ) );
		}

		if ( preg_match( '/<meta[^>]+property=["\']og:description["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m2 ) ) {
			return $this->normalize_scraped_text( html_entity_decode( (string) $m2[1], ENT_QUOTES, 'UTF-8' ) );
		}

		return '';
	}

	/**
	 * Normalize scraped text whitespace/newlines.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private function normalize_scraped_text( string $text ): string {
		$text = preg_replace( '/[\r\t]+/', "\n", $text );
		$text = preg_replace( '/[ ]+/', ' ', $text );
		$text = preg_replace( '/\n{3,}/', "\n\n", $text );

		return trim( (string) $text );
	}

	/**
	 * Collect source scan params from request.
	 *
	 * @param string           $source_type Source type.
	 * @param \WP_REST_Request $request    Request object.
	 * @return array<string, string>
	 */
	private function get_kb_scan_params( string $source_type, \WP_REST_Request $request ): array {
		$use_saved_credentials = filter_var( $request->get_param( 'use_saved_credentials' ), FILTER_VALIDATE_BOOLEAN );

		switch ( $source_type ) {
			case 'zendesk':
				if ( $use_saved_credentials ) {
					return array();
				}

				return array(
					'subdomain' => sanitize_text_field( (string) $request->get_param( 'subdomain' ) ),
					'email'     => sanitize_email( (string) $request->get_param( 'email' ) ),
					'api_token' => sanitize_text_field( (string) $request->get_param( 'api_token' ) ),
				);

			case 'notion':
				if ( $use_saved_credentials ) {
					return array();
				}

				return array(
					'api_key'     => sanitize_text_field( (string) $request->get_param( 'api_key' ) ),
					'database_id' => sanitize_text_field( (string) $request->get_param( 'database_id' ) ),
				);


		}

		return array();
	}

	/**
	 * GET /admin/forms — list forms.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_forms(): \WP_REST_Response {
		$manager = Kivor_Form_Manager::instance( $this->settings );

		return new \WP_REST_Response( array(
			'forms' => $manager->get_forms(),
		), 200 );
	}

	/**
	 * GET /admin/forms/{id} — get a form.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function get_form( \WP_REST_Request $request ) {
		$manager = Kivor_Form_Manager::instance( $this->settings );
		$form    = $manager->get_form( absint( $request['id'] ) );

		if ( ! $form ) {
			return new \WP_Error(
				'kivor_chat_agent_form_not_found',
				__( 'Form not found.', 'kivor-chat-agent' ),
				array( 'status' => 404 )
			);
		}

		return new \WP_REST_Response( array( 'form' => $form ), 200 );
	}

	/**
	 * POST /admin/forms — create form.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function create_form( \WP_REST_Request $request ) {
		if ( ! Kivor_Feature_Gates::is_feature_available( 'forms' ) ) {
			return $this->pro_required_response( __( 'Forms are available in Pro.', 'kivor-chat-agent' ) );
		}

		$manager = Kivor_Form_Manager::instance( $this->settings );
		$data    = $request->get_json_params();

		if ( ! is_array( $data ) ) {
			$data = $request->get_params();
		}

		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$result = $manager->create_form( $data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response( array( 'form' => $result ), 201 );
	}

	/**
	 * PUT /admin/forms/{id} — update form.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function update_form( \WP_REST_Request $request ) {
		if ( ! Kivor_Feature_Gates::is_feature_available( 'forms' ) ) {
			return $this->pro_required_response( __( 'Forms are available in Pro.', 'kivor-chat-agent' ) );
		}

		$manager = Kivor_Form_Manager::instance( $this->settings );
		$data    = $request->get_json_params();

		if ( ! is_array( $data ) ) {
			$data = $request->get_params();
		}

		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$result = $manager->update_form( absint( $request['id'] ), $data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response( array( 'form' => $result ), 200 );
	}

	/**
	 * DELETE /admin/forms/{id} — delete form.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function delete_form( \WP_REST_Request $request ) {
		if ( ! Kivor_Feature_Gates::is_feature_available( 'forms' ) ) {
			return $this->pro_required_response( __( 'Forms are available in Pro.', 'kivor-chat-agent' ) );
		}

		$manager = Kivor_Form_Manager::instance( $this->settings );
		$result  = $manager->delete_form( absint( $request['id'] ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * GET /admin/forms/submissions — list submissions.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_form_submissions( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! Kivor_Feature_Gates::is_feature_available( 'forms' ) ) {
			return $this->pro_required_response( __( 'Forms are available in Pro.', 'kivor-chat-agent' ) );
		}

		$manager = Kivor_Form_Manager::instance( $this->settings );

		$result = $manager->get_submissions( array(
			'page'     => absint( $request->get_param( 'page' ) ?: 1 ),
			'per_page' => absint( $request->get_param( 'per_page' ) ?: 20 ),
			'form_id'  => absint( $request->get_param( 'form_id' ) ?: 0 ),
		) );

		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * GET /admin/forms/submissions/export — export submissions CSV.
	 *
	 * @return \WP_REST_Response
	 */
	public function export_form_submissions(): \WP_REST_Response {
		if ( ! Kivor_Feature_Gates::is_feature_available( 'forms' ) ) {
			return $this->pro_required_response( __( 'Forms are available in Pro.', 'kivor-chat-agent' ) );
		}

		$manager = Kivor_Form_Manager::instance( $this->settings );
		$result  = $manager->get_submissions( array( 'page' => 1, 'per_page' => 20000 ) );

		$items = $result['items'] ?? array();
		if ( empty( $items ) ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => __( 'No submissions to export.', 'kivor-chat-agent' ),
			), 200 );
		}

		$handle = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- In-memory stream.
		fputcsv( $handle, array( 'Submission ID', 'Form ID', 'Form Name', 'Session ID', 'Created At', 'Data (JSON)' ) );

		foreach ( $items as $row ) {
			fputcsv( $handle, array(
				(int) $row['id'],
				(int) $row['form_id'],
				(string) ( $row['form_name'] ?? '' ),
				$this->sanitize_csv_value( (string) ( $row['session_id'] ?? '' ) ),
				$this->sanitize_csv_value( (string) ( $row['created_at'] ?? '' ) ),
				$this->sanitize_csv_value( wp_json_encode( $row['data'] ?? array() ) ),
			) );
		}

		rewind( $handle );
		$csv = stream_get_contents( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing in-memory stream.

		return new \WP_REST_Response( array(
			'success'  => true,
			'filename' => 'kivor-chat-agent-form-submissions-' . gmdate( 'Y-m-d' ) . '.csv',
			'data'     => base64_encode( $csv ),
		), 200 );
	}

	/**
	 * Neutralize spreadsheet formula injection in CSV cell values.
	 *
	 * @param string $value Raw CSV value.
	 * @return string
	 */
	private function sanitize_csv_value( string $value ): string {
		if ( '' === $value ) {
			return $value;
		}

		if ( preg_match( '/^[=+\-@\t\r]/', $value ) ) {
			return "'" . $value;
		}

		return $value;
	}

	/**
	 * Build a consistent response for pro-only endpoints.
	 *
	 * @param string $message Message body.
	 * @return \WP_REST_Response
	 */
	private function pro_required_response( string $message ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'success' => false,
				'message' => $message,
				'upgrade_url' => Kivor_Feature_Gates::get_upgrade_url(),
			),
			200
		);
	}

	/**
	 * Mask sensitive fields in settings for safe transport.
	 *
	 * API keys are replaced with "****{last4}" to prevent
	 * leaking secrets through the REST API.
	 *
	 * @param array $settings Full settings array.
	 * @return array Settings with masked API keys.
	 */
	private function mask_sensitive_fields( array $settings ): array {
		// Mask AI provider API keys.
		if ( isset( $settings['ai_provider']['providers'] ) ) {
			foreach ( $settings['ai_provider']['providers'] as $key => &$provider ) {
				if ( ! empty( $provider['api_key'] ) ) {
					$provider['api_key'] = $this->mask_value( $provider['api_key'] );
				}
			}
		}

		if ( isset( $settings['embedding']['providers'] ) && is_array( $settings['embedding']['providers'] ) ) {
			foreach ( $settings['embedding']['providers'] as $provider_key => &$provider ) {
				if ( ! empty( $provider['api_key'] ) ) {
					$provider['api_key'] = $this->mask_value( (string) $provider['api_key'] );
				}
			}
		}

		// Mask Pinecone API key.
		if ( ! empty( $settings['embedding']['pinecone']['api_key'] ) ) {
			$settings['embedding']['pinecone']['api_key'] = $this->mask_value( $settings['embedding']['pinecone']['api_key'] );
		}

		// Mask Qdrant API key.
		if ( ! empty( $settings['embedding']['qdrant']['api_key'] ) ) {
			$settings['embedding']['qdrant']['api_key'] = $this->mask_value( $settings['embedding']['qdrant']['api_key'] );
		}

		// Mask voice provider API keys.
		if ( ! empty( $settings['voice']['providers']['openai']['api_key'] ) ) {
			$settings['voice']['providers']['openai']['api_key'] = $this->mask_value( $settings['voice']['providers']['openai']['api_key'] );
		}
		if ( ! empty( $settings['voice']['providers']['cartesia']['api_key'] ) ) {
			$settings['voice']['providers']['cartesia']['api_key'] = $this->mask_value( $settings['voice']['providers']['cartesia']['api_key'] );
		}
		if ( ! empty( $settings['voice']['providers']['deepgram']['api_key'] ) ) {
			$settings['voice']['providers']['deepgram']['api_key'] = $this->mask_value( $settings['voice']['providers']['deepgram']['api_key'] );
		}


		if ( ! empty( $settings['external_platforms']['zendesk']['api_token'] ) ) {
			$settings['external_platforms']['zendesk']['api_token'] = $this->mask_value( $settings['external_platforms']['zendesk']['api_token'] );
		}

		if ( ! empty( $settings['external_platforms']['notion']['api_key'] ) ) {
			$settings['external_platforms']['notion']['api_key'] = $this->mask_value( $settings['external_platforms']['notion']['api_key'] );
		}


		if ( ! empty( $settings['analytics']['alert_email'] ) ) {
			$settings['analytics']['alert_email'] = sanitize_email( (string) $settings['analytics']['alert_email'] );
		}

		return $settings;
	}

	/**
	 * Mask a sensitive string value.
	 *
	 * @param string $value Original value.
	 * @return string Masked value like "****abcd".
	 */
	private function mask_value( string $value ): string {
		if ( strlen( $value ) <= 4 ) {
			return '****';
		}
		return '****' . substr( $value, -4 );
	}

	/**
	 * Check if a value looks like a masked API key.
	 *
	 * @param string $value Value to check.
	 * @return bool
	 */
	private function is_masked_value( string $value ): bool {
		return strpos( $value, '****' ) === 0;
	}

	/**
	 * Restore masked API keys from stored settings.
	 *
	 * When the frontend sends back masked keys (e.g., "****abcd"),
	 * we replace them with the real stored values to avoid overwriting
	 * actual keys with masked strings.
	 *
	 * @param string $group  Settings group name.
	 * @param array  $values Submitted values.
	 * @return array Values with masked keys restored.
	 */
	private function restore_masked_keys( string $group, array $values ): array {
		switch ( $group ) {
			case 'ai_provider':
				if ( isset( $values['providers'] ) && is_array( $values['providers'] ) ) {
					foreach ( $values['providers'] as $provider_key => &$provider_values ) {
						if ( isset( $provider_values['api_key'] ) && $this->is_masked_value( $provider_values['api_key'] ) ) {
							$stored = $this->settings->get( "ai_provider.providers.{$provider_key}.api_key" );
							$provider_values['api_key'] = $stored ?? '';
						}
					}
				}
				break;

			case 'embedding':
				if ( isset( $values['providers'] ) && is_array( $values['providers'] ) ) {
					foreach ( $values['providers'] as $provider_key => &$provider_values ) {
						if ( isset( $provider_values['api_key'] ) && $this->is_masked_value( (string) $provider_values['api_key'] ) ) {
							$provider_values['api_key'] = $this->settings->get( 'embedding.providers.' . sanitize_key( (string) $provider_key ) . '.api_key' ) ?? '';
						}
					}
				}
				if ( isset( $values['pinecone']['api_key'] ) && $this->is_masked_value( $values['pinecone']['api_key'] ) ) {
					$values['pinecone']['api_key'] = $this->settings->get( 'embedding.pinecone.api_key' ) ?? '';
				}
				if ( isset( $values['qdrant']['api_key'] ) && $this->is_masked_value( $values['qdrant']['api_key'] ) ) {
					$values['qdrant']['api_key'] = $this->settings->get( 'embedding.qdrant.api_key' ) ?? '';
				}
				break;

			case 'voice':
				if ( isset( $values['providers']['openai']['api_key'] ) && $this->is_masked_value( $values['providers']['openai']['api_key'] ) ) {
					$values['providers']['openai']['api_key'] = $this->settings->get( 'voice.providers.openai.api_key' ) ?? '';
				}
				if ( isset( $values['providers']['cartesia']['api_key'] ) && $this->is_masked_value( $values['providers']['cartesia']['api_key'] ) ) {
					$values['providers']['cartesia']['api_key'] = $this->settings->get( 'voice.providers.cartesia.api_key' ) ?? '';
				}
				if ( isset( $values['providers']['deepgram']['api_key'] ) && $this->is_masked_value( $values['providers']['deepgram']['api_key'] ) ) {
					$values['providers']['deepgram']['api_key'] = $this->settings->get( 'voice.providers.deepgram.api_key' ) ?? '';
				}


				break;

			case 'external_platforms':
				if ( isset( $values['zendesk']['api_token'] ) && $this->is_masked_value( $values['zendesk']['api_token'] ) ) {
					$values['zendesk']['api_token'] = $this->settings->get( 'external_platforms.zendesk.api_token' ) ?? '';
				}

				if ( isset( $values['notion']['api_key'] ) && $this->is_masked_value( $values['notion']['api_key'] ) ) {
					$values['notion']['api_key'] = $this->settings->get( 'external_platforms.notion.api_key' ) ?? '';
				}

				break;
		}

		return $values;
	}
}
