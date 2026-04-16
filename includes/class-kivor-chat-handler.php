<?php
/**
 * Chat handler.
 *
 * Orchestrates the full chat pipeline: validation, GDPR consent, prompt building,
 * two-pass product search via tool calling, AI response, and logging.
 *
 * @package KivorAgent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class Kivor_Chat_Handler {

	/**
	 * Settings instance.
	 *
	 * @var Kivor_Settings
	 */
	private Kivor_Settings $settings;

	/**
	 * Prompt builder instance.
	 *
	 * @var Kivor_Prompt_Builder
	 */
	private Kivor_Prompt_Builder $prompt_builder;

	/**
	 * Constructor.
	 *
	 * @param Kivor_Settings $settings Plugin settings.
	 */
	public function __construct( Kivor_Settings $settings ) {
		$this->settings       = $settings;
		$this->prompt_builder = new Kivor_Prompt_Builder( $settings );
	}

	/**
	 * Handle a chat request (non-streaming).
	 *
	 * @param array $request {
	 *     Request data.
	 *
	 *     @type string $message    User message.
	 *     @type string $session_id Session identifier.
	 *     @type array  $history    Conversation history.
	 *     @type bool   $consent    GDPR consent given.
	 * }
	 * @return array|WP_Error {
	 *     @type string $reply      AI response text.
	 *     @type array  $products   Product cards data (if any).
	 *     @type string $session_id Session ID.
	 * }
	 */
	public function handle( array $request ) {
		// 1. Validate input.
		$validated = $this->validate_request( $request );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$message    = $validated['message'];
		$session_id = $validated['session_id'];
		$history    = $validated['history'];
		$consent    = $validated['consent'];
		$forms      = array();

		// 2. Check GDPR consent.
		$consent_check = $this->check_consent( $consent, $session_id );
		if ( is_wp_error( $consent_check ) ) {
			return $consent_check;
		}


		// 3. Create AI provider.
		$provider = Kivor_AI_Factory::create( $this->settings );
		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		// 4. Build initial context (knowledge base lookup).
		$kb_context = $this->get_kb_context( $message );
		$system_prompt = $this->prompt_builder->build( array(
			'kb_articles' => $kb_context,
		) );

		// 5. Build messages with history.
		$messages = $this->prompt_builder->build_messages( $system_prompt, $history, $message );

		// 6. Get tool definitions for product search.
		$tools   = $this->prompt_builder->get_tool_definitions();
		$options = array();
		if ( ! empty( $tools ) ) {
			$options['tools'] = $tools;
		}

		// 7. First pass: send to AI (may return tool call or direct response).
		$response = $provider->chat( $messages, $options );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$products = array();

		// 8. Handle tool calls (two-pass flow).
		if ( ! empty( $response['tool_calls'] ) ) {
			$tool_result = $this->execute_tool_calls( $response['tool_calls'] );
			$products    = $tool_result['products'];
			$forms       = $tool_result['forms'];

			if ( ! empty( $forms ) ) {
				$this->track_recommendations_once( $session_id, $products );
				$this->log_conversation( $session_id, $message, '', $products, $forms, $consent );

				return array(
					'reply'      => '',
					'products'   => $products,
					'forms'      => $forms,
					'session_id' => $session_id,
				);
			}

			// Rebuild system prompt with product results.
			$system_prompt_with_products = $this->prompt_builder->build( array(
				'kb_articles'     => $kb_context,
				'product_results' => $tool_result['context_text'],
			) );

			// Build second-pass messages: original + assistant tool call + tool results.
			$second_pass_messages = $this->build_second_pass_messages(
				$system_prompt_with_products,
				$history,
				$message,
				$response,
				$tool_result
			);

			// Second pass: AI generates natural language response with product context.
			$response = $provider->chat( $second_pass_messages );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
		}

		$reply = $response['content'] ?? '';
		$this->track_recommendations_once( $session_id, $products );

		// 9. Log conversation if enabled.
		$this->log_conversation( $session_id, $message, $reply, $products, $forms, $consent );

		return array(
			'reply'      => $reply,
			'products'   => $products,
			'forms'      => $forms,
			'session_id' => $session_id,
		);
	}

	/**
	 * Validate the chat request.
	 *
	 * @param array $request Raw request data.
	 * @return array|WP_Error Validated and sanitized data.
	 */
	private function validate_request( array $request ) {
		$message = isset( $request['message'] ) ? Kivor_Sanitizer::sanitize_message( $request['message'] ) : '';

		if ( empty( $message ) ) {
			return new \WP_Error(
				'kivor_chat_agent_empty_message',
				__( 'Please enter a message.', 'kivor-chat-agent' ),
				array( 'status' => 400 )
			);
		}

		$session_id = ! empty( $request['session_id'] )
			? Kivor_Sanitizer::sanitize_session_id( $request['session_id'] )
			: Kivor_Sanitizer::generate_session_id();

		// Validate and sanitize conversation history.
		$history = array();
		if ( ! empty( $request['history'] ) && is_array( $request['history'] ) ) {
			foreach ( $request['history'] as $msg ) {
				if ( ! isset( $msg['role'], $msg['content'] ) ) {
					continue;
				}
				if ( ! in_array( $msg['role'], array( 'user', 'assistant' ), true ) ) {
					continue;
				}
				$history[] = array(
					'role'    => sanitize_text_field( $msg['role'] ),
					'content' => Kivor_Sanitizer::sanitize_message( $msg['content'] ),
				);
			}
		}

		$consent = ! empty( $request['consent'] );

		return array(
			'message'    => $message,
			'session_id' => $session_id,
			'history'    => $history,
			'consent'    => $consent,
		);
	}

	/**
	 * Check if GDPR consent is required and provided.
	 *
	 * Delegates to the Kivor_Consent_Manager for centralized consent handling.
	 *
	 * @param bool   $consent    Whether consent was given.
	 * @param string $session_id Session ID.
	 * @return true|WP_Error
	 */
	private function check_consent( bool $consent, string $session_id ) {
		$manager = new Kivor_Consent_Manager( $this->settings );
		return $manager->validate_consent( $consent, $session_id );
	}

	/**
	 * Get relevant knowledge base context for a message.
	 *
	 * Retrieves semantically relevant KB articles (or falls back to keyword search)
	 * via the `kivor_chat_agent_kb_context` filter hooked by Kivor_Knowledge_Base.
	 *
	 * @param string $message User message.
	 * @return string Knowledge base context text, or empty string.
	 */
	private function get_kb_context( string $message ): string {
		/**
		 * Filter the knowledge base context for a chat message.
		 *
		 * @since 1.0.0
		 * @param string $context Empty string by default.
		 * @param string $message The user's message.
		 */
		return apply_filters( 'kivor_chat_agent_kb_context', '', $message );
	}

	/**
	 * Execute tool calls returned by the AI.
	 *
	 * Currently supports 'search_products'. Additional tools can be added here.
	 *
	 * @param array $tool_calls Array of tool call objects from AI response.
	 * @return array {
	 *     @type array  $products     Product card data for the frontend.
	 *     @type string $context_text Product results formatted for AI context.
	 *     @type array  $tool_results Tool result messages for the second pass.
	 * }
	 */
	private function execute_tool_calls( array $tool_calls ): array {
		$products      = array();
		$forms         = array();
		$context_text  = '';
		$tool_results  = array();
		$form_manager  = Kivor_Form_Manager::instance( $this->settings );

		foreach ( $tool_calls as $tool_call ) {
			$function_name = $tool_call['function']['name'] ?? '';
			$arguments     = $tool_call['function']['arguments'] ?? '{}';
			$tool_call_id  = $tool_call['id'] ?? '';

			if ( is_string( $arguments ) ) {
				$arguments = json_decode( $arguments, true ) ?? array();
			}

			switch ( $function_name ) {
				case 'search_products':
					$search_result = $this->execute_product_search( $arguments );
					$products      = $search_result['products'];
					$context_text  = $search_result['context_text'];

					$tool_results[] = array(
						'tool_call_id' => $tool_call_id,
						'role'         => 'tool',
						'content'      => $context_text,
					);
					break;

				case 'show_form':
					$form_id = absint( $arguments['form_id'] ?? 0 );
					$form    = $form_manager->get_form_for_ai( $form_id );

					if ( $form ) {
						$forms[] = $form_manager->build_form_payload( $form, false, false );
						$tool_results[] = array(
							'tool_call_id' => $tool_call_id,
							'role'         => 'tool',
							'content'      => wp_json_encode( array(
								'success' => true,
								'form_id' => $form_id,
							) ),
						);
					} else {
						$tool_results[] = array(
							'tool_call_id' => $tool_call_id,
							'role'         => 'tool',
							'content'      => wp_json_encode( array(
								'error' => 'Form not found or not AI-eligible.',
							) ),
						);
					}
					break;

				default:
					$tool_results[] = array(
						'tool_call_id' => $tool_call_id,
						'role'         => 'tool',
						'content'      => wp_json_encode( array(
							'error' => "Unknown function: {$function_name}",
						) ),
					);
					break;
			}
		}

		return array(
			'products'     => $products,
			'forms'        => $forms,
			'context_text' => $context_text,
			'tool_results' => $tool_results,
		);
	}

	/**
	 * Execute a product search.
	 *
	 * Delegates to Kivor_Hybrid_Search via the 'kivor_chat_agent_product_search' filter,
	 * which combines keyword and semantic vector search.
	 *
	 * @param array $params Search parameters from AI function call.
	 * @return array {
	 *     @type array  $products     Product card data.
	 *     @type string $context_text Products formatted as text for AI context.
	 * }
	 */
	private function execute_product_search( array $params ): array {
		/**
		 * Filter to execute product search.
		 *
		 * @since 1.0.0
		 * @param array $result  Default empty result.
		 * @param array $params  Search parameters.
		 */
		$result = apply_filters( 'kivor_chat_agent_product_search', array(
			'products'     => array(),
			'context_text' => 'No products found.',
		), $params );

		return $result;
	}

	/**
	 * Build second-pass messages for the two-pass flow.
	 *
	 * After the AI returns tool calls and we execute them, we need to send
	 * the results back so the AI can generate a natural language response.
	 *
	 * @param string $system_prompt  Updated system prompt (with product context).
	 * @param array  $history        Conversation history.
	 * @param string $user_message   Original user message.
	 * @param array  $first_response First pass AI response (contains tool_calls).
	 * @param array  $tool_result    Executed tool results.
	 * @return array Messages array for the second AI call.
	 */
	private function build_second_pass_messages(
		string $system_prompt,
		array $history,
		string $user_message,
		array $first_response,
		array $tool_result
	): array {
		$messages = $this->prompt_builder->build_messages( $system_prompt, $history, $user_message );

		// Add the assistant message with tool calls.
		$assistant_msg = array(
			'role'       => 'assistant',
			'content'    => $first_response['content'] ?? null,
			'tool_calls' => $first_response['tool_calls'],
		);
		$messages[] = $assistant_msg;

		// Add tool results.
		foreach ( $tool_result['tool_results'] as $tr ) {
			$messages[] = array(
				'role'         => 'tool',
				'tool_call_id' => $tr['tool_call_id'],
				'content'      => $tr['content'],
			);
		}

		return $messages;
	}

	/**
	 * Log a conversation exchange to the database.
	 *
	 * Delegates to the standalone Kivor_Logger class.
	 *
	 * @param string $session_id Session ID.
	 * @param string $user_msg   User message.
	 * @param string $bot_reply  Bot reply.
	 * @param array  $products   Product data (for metadata).
	 * @param bool   $consent    Whether consent was given.
	 */
	private function log_conversation( string $session_id, string $user_msg, string $bot_reply, array $products, array $forms, bool $consent ): void {
		$logger = new Kivor_Logger( $this->settings );
		$metadata_products = $products;
		if ( ! empty( $forms ) ) {
			$metadata_products = array_merge( $metadata_products, array( '_forms' => $forms ) );
		}
		$logger->log_conversation( $session_id, $user_msg, $bot_reply, $metadata_products, $consent );

		$analytics = new Kivor_Analytics( $this->settings );
		$analytics->maybe_alert_negative_sentiment();
	}

	/**
	 * Track recommendation events for analytics.
	 *
	 * @param string $session_id Session ID.
	 * @param array  $products   Product cards.
	 * @return void
	 */
	private function track_recommendations_once( string $session_id, array $products ): void {
		if ( empty( $products ) ) {
			return;
		}

		$tracker = new Kivor_Conversion_Tracker( $this->settings );
		$tracker->track_recommendations_once( $session_id, $products );
	}
}
