<?php
/**
 * Frontend controller.
 *
 * Enqueues widget JavaScript, CSS, and passes configuration to the
 * frontend via wp_add_inline_script.
 *
 * @package KivorAgent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class Kivor_Frontend {

	/**
	 * Settings instance.
	 *
	 * @var Kivor_Settings
	 */
	private Kivor_Settings $settings;

	/**
	 * Handle prefix for enqueued assets.
	 *
	 * @var string
	 */
	private string $handle = 'kivor-chat-agent';

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
	 */
	public function init(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_head', array( $this, 'inject_custom_css' ) );
	}

	/**
	 * Enqueue frontend JS and CSS.
	 */
	public function enqueue_assets(): void {
		// Don't load in admin, REST, or AJAX (except WC AJAX).
		if ( is_admin() ) {
			return;
		}

		$version = KIVOR_AGENT_VERSION;
		$url     = KIVOR_AGENT_URL;
		$asset_version = static function ( string $relative_path ) use ( $version ): string {
			$asset_path = KIVOR_AGENT_PATH . ltrim( $relative_path, '/' );
			return file_exists( $asset_path ) ? (string) filemtime( $asset_path ) : $version;
		};

		// CSS.
		wp_enqueue_style(
			$this->handle,
			$url . 'frontend/css/kivor-chat-agent-widget.css',
			array(),
			$asset_version( 'frontend/css/kivor-chat-agent-widget.css' )
		);

		// Main widget controller — must load first.
		wp_enqueue_script(
			$this->handle,
			$url . 'frontend/src/kivor-chat-agent-widget.js',
			array(),
			$asset_version( 'frontend/src/kivor-chat-agent-widget.js' ),
			true
		);

		// Chat module.
		wp_enqueue_script(
			$this->handle . '-chat',
			$url . 'frontend/src/kivor-chat-agent-chat.js',
			array( $this->handle ),
			$asset_version( 'frontend/src/kivor-chat-agent-chat.js' ),
			true
		);

		$forms = $this->settings->get( 'forms' );

		// WhatsApp module (only if enabled).
		$whatsapp = $this->settings->get( 'whatsapp' );
		if ( ! empty( $whatsapp['enabled'] ) ) {
			wp_enqueue_script(
				$this->handle . '-whatsapp',
				$url . 'frontend/src/kivor-chat-agent-whatsapp.js',
				array( $this->handle ),
				$asset_version( 'frontend/src/kivor-chat-agent-whatsapp.js' ),
				true
			);
		}

		// Product cards (only if WooCommerce active).
		if ( $this->is_woocommerce_active() ) {
			wp_enqueue_script(
				$this->handle . '-product-card',
				$url . 'frontend/src/kivor-chat-agent-product-card.js',
				array( $this->handle ),
				$asset_version( 'frontend/src/kivor-chat-agent-product-card.js' ),
				true
			);
		}

		// Consent module (only if GDPR enabled).
		$gdpr = $this->settings->get( 'gdpr' );
		if ( ! empty( $gdpr['enabled'] ) && ! empty( $gdpr['consent_required'] ) ) {
			wp_enqueue_script(
				$this->handle . '-consent',
				$url . 'frontend/src/kivor-chat-agent-consent.js',
				array( $this->handle ),
				$asset_version( 'frontend/src/kivor-chat-agent-consent.js' ),
				true
			);
		}

		if ( ! empty( $forms['enabled'] ) ) {
			wp_enqueue_script(
				$this->handle . '-forms',
				$url . 'frontend/src/kivor-chat-agent-forms.js',
				array( $this->handle, $this->handle . '-chat' ),
				$asset_version( 'frontend/src/kivor-chat-agent-forms.js' ),
				true
			);
		}

		// Inject configuration object before the main widget script.
		$config = $this->build_config();
		wp_add_inline_script(
			$this->handle,
			'window.kivorChatAgentConfig = ' . wp_json_encode( $config ) . ';',
			'before'
		);
	}

	/**
	 * Inject admin-defined custom CSS into the page head.
	 */
	public function inject_custom_css(): void {
		$custom_css = $this->settings->get( 'general.custom_css', '' );

		if ( empty( $custom_css ) ) {
			return;
		}

		// Sanitize: strip tags first.
		$custom_css = wp_strip_all_tags( $custom_css );

		// Defense-in-depth: remove dangerous CSS constructs that could
		// execute JavaScript or load external resources (VULN-010 fix).
		$custom_css = preg_replace(
			'/\b(expression|javascript|behavior|vbscript|@import|binding)\b/i',
			'/* blocked */',
			$custom_css
		);

		// Strip url() values that point to non-data, non-relative schemes.
		$custom_css = preg_replace_callback(
			'/url\s*\(\s*(["\']?)(.+?)\1\s*\)/i',
			function ( $matches ) {
				$url = trim( $matches[2] );
				// Allow relative paths and data: URIs only.
				if ( preg_match( '/^(https?:|\/\/|ftp:|javascript:|vbscript:)/i', $url ) ) {
					return '/* url blocked */';
				}
				return $matches[0];
			},
			$custom_css
		);

		echo "\n<style id=\"kivor-chat-agent-custom-css\">\n" . $custom_css . "\n</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS is admin-controlled and sanitized above.
	}

	/**
	 * Build the frontend configuration object.
	 *
	 * Only includes data needed by the widget — no API keys or sensitive data.
	 *
	 * @return array
	 */
	private function build_config(): array {
		$general    = $this->settings->get( 'general' );
		$ai         = $this->settings->get( 'ai_provider' );
		$appearance = $this->settings->get( 'appearance' );
		$gdpr       = $this->settings->get( 'gdpr' );
		$whatsapp   = $this->settings->get( 'whatsapp' );
		$voice      = $this->settings->get( 'voice' );
		$phone_call = $this->settings->get( 'phone_call' );
		$forms      = $this->settings->get( 'forms' );

		$config = array(
			// REST API.
			'rest_url' => esc_url_raw( rest_url() ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'site_url' => esc_url_raw( site_url() ),

			// General.
			'bot_name'      => $general['bot_name'] ?? 'Kivor',
			'use_in_app_intro' => ! empty( $general['use_in_app_intro'] ),
			'chatbot_title' => $general['chatbot_title'] ?? '',
			'chatbot_description' => $general['chatbot_description'] ?? '',
			'first_greeting_message' => $general['first_greeting_message'] ?? 'Hi, welcome! You\'re speaking with AI Agent. I\'m here to answer your questions and help you out.',
			'bot_avatar'    => $general['bot_avatar'] ?? '',
			'widget_logo'   => $general['widget_logo'] ?? '',
			'chat_tab_label' => $general['chat_tab_label'] ?? 'Chat',
			'chat_position' => $general['chat_position'] ?? 'bottom-right',
			'show_end_session_button' => ! empty( $general['show_end_session_button'] ),

			// AI settings (non-sensitive).
			'conversation_memory_size' => intval( $ai['conversation_memory_size'] ?? 10 ),


			// Appearance.
			'appearance' => array(
				'product_card_show_price'      => ! empty( $appearance['product_card_show_price'] ),
				'product_card_show_link'       => ! empty( $appearance['product_card_show_link'] ),
				'product_card_show_add_to_cart' => ! empty( $appearance['product_card_show_add_to_cart'] ),
				'product_card_show_image'      => ! empty( $appearance['product_card_show_image'] ),
				'product_card_layout'          => $appearance['product_card_layout'] ?? 'carousel',
				'widget_primary_color'         => $appearance['widget_primary_color'] ?? '#10b981',
				'widget_primary_hover_color'   => $appearance['widget_primary_hover_color'] ?? '#059669',
				'widget_primary_text_color'    => $appearance['widget_primary_text_color'] ?? '#ffffff',
				'widget_background_color'      => $appearance['widget_background_color'] ?? '#ffffff',
				'widget_background_alt_color'  => $appearance['widget_background_alt_color'] ?? '#f3f4f6',
				'widget_text_color'            => $appearance['widget_text_color'] ?? '#1f2937',
				'widget_text_muted_color'      => $appearance['widget_text_muted_color'] ?? '#6b7280',
				'widget_border_color'          => $appearance['widget_border_color'] ?? '#e5e7eb',
				'widget_user_bubble_color'     => $appearance['widget_user_bubble_color'] ?? '#10b981',
				'widget_user_text_color'       => $appearance['widget_user_text_color'] ?? '#ffffff',
				'widget_bot_bubble_color'      => $appearance['widget_bot_bubble_color'] ?? '#f3f4f6',
				'widget_bot_text_color'        => $appearance['widget_bot_text_color'] ?? '#1f2937',
				'widget_tab_background_color'  => $appearance['widget_tab_background_color'] ?? '#ffffff',
				'widget_tab_text_color'        => $appearance['widget_tab_text_color'] ?? '#374151',
				'widget_tab_active_color'      => $appearance['widget_tab_active_color'] ?? '#10b981',
				'widget_tab_active_text_color' => $appearance['widget_tab_active_text_color'] ?? '#10b981',
			),
		);

		$widget_logo_id = intval( $general['widget_logo_id'] ?? 0 );
		if ( $widget_logo_id > 0 ) {
			$widget_logo_url = wp_get_attachment_url( $widget_logo_id );
			if ( is_string( $widget_logo_url ) && '' !== trim( $widget_logo_url ) ) {
				$config['widget_logo'] = esc_url_raw( $widget_logo_url );
			}
		}

		// GDPR config (only public-facing bits).
		if ( ! empty( $gdpr['enabled'] ) ) {
			$gdpr_config = array(
				'enabled'          => true,
				'consent_required' => ! empty( $gdpr['consent_required'] ),
				'consent_message'  => $gdpr['consent_message'] ?? '',
				'show_privacy_link' => ! empty( $gdpr['show_privacy_link'] ),
			);

			// Resolve privacy page URL.
			$privacy_page_id = intval( $gdpr['privacy_page_id'] ?? 0 );
			if ( $privacy_page_id > 0 ) {
				$privacy_url = get_permalink( $privacy_page_id );
				if ( $privacy_url ) {
					$gdpr_config['privacy_url'] = esc_url( $privacy_url );
				}
			} elseif ( function_exists( 'get_privacy_policy_url' ) ) {
				// Fall back to WP's built-in privacy policy page.
				$wp_privacy_url = get_privacy_policy_url();
				if ( $wp_privacy_url ) {
					$gdpr_config['privacy_url'] = esc_url( $wp_privacy_url );
				}
			}

			$config['gdpr'] = $gdpr_config;
		} else {
			$config['gdpr'] = array( 'enabled' => false );
		}

		// WhatsApp config (only if enabled).
		if ( ! empty( $whatsapp['enabled'] ) ) {
			$config['whatsapp'] = array(
				'enabled'           => true,
				'name'              => $whatsapp['name'] ?? 'WhatsApp Support',
				'number'            => $whatsapp['number'] ?? '',
				'prefilled_message' => $whatsapp['prefilled_message'] ?? '',
			);
		} else {
			$config['whatsapp'] = array( 'enabled' => false );
		}

		$config['phone_call'] = array(
			'enabled'      => ! empty( $phone_call['enabled'] ),
			'mobile_only'  => ! empty( $phone_call['mobile_only'] ),
			'number'       => $phone_call['number'] ?? '',
			'button_label' => $phone_call['button_label'] ?? 'Call Support',
		);

		$config['voice'] = array(
			'enabled'              => ! empty( $voice['enabled'] ),
			'input_enabled'        => ! empty( $voice['input_enabled'] ),
			'interaction_mode'     => $voice['interaction_mode'] ?? 'push_to_talk',
			'auto_send_mode'       => $voice['auto_send_mode'] ?? 'silence',
			'auto_send_delay_ms'   => intval( $voice['auto_send_delay_ms'] ?? 800 ),
			'confidence_threshold' => floatval( $voice['confidence_threshold'] ?? 0.65 ),
			'auto_detect_language' => ! empty( $voice['auto_detect_language'] ),
			'default_language'     => $voice['default_language'] ?? 'en-US',
			'stt_provider'         => $voice['stt_provider'] ?? 'webspeech',
			'stt_model'            => $voice['stt_model'] ?? '',
			'limits'               => array(
				'max_stt_seconds' => intval( $voice['limits']['max_stt_seconds'] ?? 20 ),
			),
			'providers'            => array(
				'openai'   => array(
					'stt_model' => (string) ( $voice['providers']['openai']['stt_model'] ?? '' ),
				),
				'cartesia' => array(
					'stt_model' => (string) ( $voice['providers']['cartesia']['stt_model'] ?? '' ),
				),
				'deepgram' => array(
					'stt_model' => (string) ( $voice['providers']['deepgram']['stt_model'] ?? '' ),
				),
			),
		);

		$form_manager = Kivor_Form_Manager::instance( $this->settings );
		$primary_form = null;
		$tab_form = null;

		if ( ! empty( $forms['enabled'] ) ) {
			$primary_block_input = ! empty( $forms['primary_block_input'] );
			$primary_allow_skip  = ! empty( $forms['primary_allow_skip'] ) && ! $primary_block_input;
			$primary_submit_message = isset( $forms['primary_submit_message'] ) && '' !== trim( (string) $forms['primary_submit_message'] )
				? (string) $forms['primary_submit_message']
				: 'Thanks. What can I help you with today?';

			$primary = $form_manager->get_primary_form();
			if ( $primary ) {
				$primary_form = $form_manager->build_form_payload(
					$primary,
					true,
					$primary_block_input
				);
			}

			$tab = $form_manager->get_tab_form();
			if ( $tab ) {
				$tab_form = $form_manager->build_form_payload( $tab, false, false );
			}

			$config['forms'] = array(
				'enabled'             => true,
				'primary_form'        => $primary_form,
				'tab_form'            => $tab_form,
				'tab_label'           => isset( $forms['tab_label'] ) && '' !== trim( (string) $forms['tab_label'] ) ? (string) $forms['tab_label'] : 'Form',
				'primary_allow_skip'  => $primary_allow_skip,
				'primary_block_input' => $primary_block_input,
				'primary_submit_message' => $primary_submit_message,
				'show_field_titles'   => ! array_key_exists( 'show_field_titles', $forms ) || ! empty( $forms['show_field_titles'] ),
			);
		} else {
			$config['forms'] = array( 'enabled' => false );
		}

		// WooCommerce AJAX URL for add-to-cart.
		if ( $this->is_woocommerce_active() ) {
			$config['wc_ajax_url'] = \WC_AJAX::get_endpoint( '%%endpoint%%' );
		}

		/**
		 * Filter the frontend config before it's serialized.
		 *
		 * @param array $config Frontend configuration.
		 */
		return apply_filters( 'kivor_chat_agent_frontend_config', $config );
	}

	/**
	 * Check if WooCommerce is active.
	 *
	 * @return bool
	 */
	private function is_woocommerce_active(): bool {
		return class_exists( 'WooCommerce' );
	}
}
