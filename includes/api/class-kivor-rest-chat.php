<?php
/**
 * REST API controller for chat endpoints.
 *
 * Provides:
 * - POST /kivor-chat-agent/v1/chat — Standard chat request/response.
 *
 * @package KivorAgent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class Kivor_Rest_Chat {

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
		// Standard chat endpoint.
		register_rest_route( self::NAMESPACE, '/chat', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'handle_chat' ),
			'permission_callback' => array( $this, 'check_permissions' ),
			'args'                => $this->get_chat_args(),
		) );


		// Consent endpoint — record explicit GDPR consent.
		register_rest_route( self::NAMESPACE, '/consent', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'handle_consent' ),
			'permission_callback' => array( $this, 'check_permissions' ),
			'args'                => array(
				'session_id' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => __( 'Chat session ID.', 'kivor-chat-agent' ),
				),
				'consent' => array(
					'required'    => true,
					'type'        => 'boolean',
					'description' => __( 'Whether consent is given (true) or revoked (false).', 'kivor-chat-agent' ),
				),
			),
		) );

		// Public form submission endpoint.
		register_rest_route( self::NAMESPACE, '/forms/submit', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'handle_form_submit' ),
			'permission_callback' => array( $this, 'check_permissions' ),
			'args'                => array(
				'form_id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'session_id' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'data' => array(
					'required' => true,
					'type'     => 'object',
				),
			),
		) );

		register_rest_route( self::NAMESPACE, '/analytics/event', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'handle_analytics_event' ),
			'permission_callback' => array( $this, 'check_permissions' ),
			'args'                => array(
				'session_id' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'product_id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'event_type' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				),
				'source_url' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
				),
			),
		) );
	}

	/**
	 * Permission callback for chat endpoints.
	 *
	 * Chat is public (no auth required), but we check rate limits
	 * and nonce when available.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return true|WP_Error
	 */
	public function check_permissions( \WP_REST_Request $request ) {
		// Verify nonce if provided (cookie-based auth for logged-in users).
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( $nonce && ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_Error(
				'kivor_chat_agent_invalid_nonce',
				__( 'Invalid security token. Please refresh the page and try again.', 'kivor-chat-agent' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Handle a standard (non-streaming) chat request.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function handle_chat( \WP_REST_Request $request ) {
		$handler = new Kivor_Chat_Handler( $this->settings );

		$result = $handler->handle( array(
			'message'    => $request->get_param( 'message' ),
			'session_id' => $request->get_param( 'session_id' ),
			'history'    => $request->get_param( 'history' ) ?? array(),
			'consent'    => $request->get_param( 'consent' ),
		) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * Handle explicit GDPR consent recording.
	 *
	 * Called when the user clicks "Accept" on the consent dialog.
	 * Records the consent in the database so subsequent chat requests
	 * for this session don't need to re-consent.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function handle_consent( \WP_REST_Request $request ): \WP_REST_Response {
		$session_id = $request->get_param( 'session_id' );
		$consent    = (bool) $request->get_param( 'consent' );

		$manager = new Kivor_Consent_Manager( $this->settings );

		if ( $consent ) {
			$manager->record_consent( $session_id, 'chat_logging', true );
		} else {
			$manager->revoke_consent( $session_id );
		}

		return new \WP_REST_Response( array(
			'success'    => true,
			'session_id' => $session_id,
			'consented'  => $consent,
		), 200 );
	}

	/**
	 * Handle public form submission.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function handle_form_submit( \WP_REST_Request $request ) {
		$manager = Kivor_Form_Manager::instance( $this->settings );

		if ( ! $manager->is_enabled() ) {
			return new \WP_Error(
				'kivor_chat_agent_forms_disabled',
				__( 'Forms feature is disabled.', 'kivor-chat-agent' ),
				array( 'status' => 400 )
			);
		}

		$form_id    = absint( $request->get_param( 'form_id' ) );
		$session_id = Kivor_Sanitizer::sanitize_session_id( (string) $request->get_param( 'session_id' ) );
		$data       = $request->get_param( 'data' );

		if ( ! is_array( $data ) ) {
			return new \WP_Error(
				'kivor_chat_agent_invalid_form_data',
				__( 'Invalid form submission payload.', 'kivor-chat-agent' ),
				array( 'status' => 400 )
			);
		}

		$result = $manager->submit_form( $form_id, $session_id, $data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * Handle analytics event tracking.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function handle_analytics_event( \WP_REST_Request $request ): \WP_REST_Response {
		$session_id = Kivor_Sanitizer::sanitize_session_id( (string) $request->get_param( 'session_id' ) );
		$product_id = absint( $request->get_param( 'product_id' ) );
		$event_type = sanitize_key( (string) $request->get_param( 'event_type' ) );
		$source_url = esc_url_raw( (string) $request->get_param( 'source_url' ) );

		if ( '' === trim( $session_id ) || $product_id <= 0 || ! in_array( $event_type, array( 'clicked', 'added_to_cart', 'recommended', 'purchased' ), true ) ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => __( 'Invalid analytics event payload.', 'kivor-chat-agent' ),
			), 400 );
		}

		$tracker = new Kivor_Conversion_Tracker( $this->settings );
		$tracker->track_event( $session_id, $product_id, $event_type, $source_url );

		if ( class_exists( 'WooCommerce' ) && function_exists( 'WC' ) ) {
			$wc = WC();
			if ( $wc && isset( $wc->session ) && $wc->session ) {
				$wc->session->set( 'kivor_chat_agent_session_id', $session_id );
			}
		}

		return new \WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Get the argument schema for chat endpoints.
	 *
	 * @return array
	 */
	private function get_chat_args(): array {
		return array(
			'message'    => array(
				'required'          => true,
				'type'              => 'string',
				'description'       => __( 'The user message.', 'kivor-chat-agent' ),
				'sanitize_callback' => function ( $value ) {
					return Kivor_Sanitizer::sanitize_message( (string) $value, 2000 );
				},
				'validate_callback' => function ( $value ) {
					$value = (string) $value;

					if ( empty( trim( $value ) ) ) {
						return new \WP_Error(
							'kivor_chat_agent_empty_message',
							__( 'Message cannot be empty.', 'kivor-chat-agent' )
						);
					}

					$length = function_exists( 'mb_strlen' )
						? (int) mb_strlen( $value, 'UTF-8' )
						: strlen( $value );

					if ( $length > 2000 ) {
						return new \WP_Error(
							'kivor_chat_agent_message_too_long',
							sprintf(
								/* translators: %d: maximum message length */
								__( 'Message exceeds maximum length of %d characters.', 'kivor-chat-agent' ),
								2000
							),
							array( 'status' => 400 )
						);
					}

					return true;
				},
			),
			'session_id' => array(
				'required'          => false,
				'type'              => 'string',
				'description'       => __( 'Session identifier for conversation continuity.', 'kivor-chat-agent' ),
				'sanitize_callback' => function ( $value ) {
					return Kivor_Sanitizer::sanitize_session_id( $value );
				},
			),
			'history'    => array(
				'required'    => false,
				'type'        => 'array',
				'description' => __( 'Conversation history array.', 'kivor-chat-agent' ),
				'default'     => array(),
				'items'       => array(
					'type'       => 'object',
					'properties' => array(
						'role'    => array(
							'type' => 'string',
							'enum' => array( 'user', 'assistant' ),
						),
						'content' => array(
							'type' => 'string',
						),
					),
				),
			),
			'consent'    => array(
				'required'    => false,
				'type'        => 'boolean',
				'description' => __( 'Whether GDPR consent was given.', 'kivor-chat-agent' ),
				'default'     => false,
			),
		);
	}
}
