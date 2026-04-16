<?php
/**
 * Plugin settings manager.
 *
 * Handles all plugin settings with defaults, validation, and sanitization.
 * Settings are stored as a single JSON blob in wp_options under 'kivor_chat_agent_settings'.
 *
 * @package KivorAgent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class Kivor_Settings {

    /**
     * Option key in wp_options table.
     *
     * @var string
     */
    const OPTION_KEY = 'kivor_chat_agent_settings';

    /**
     * Cached settings array.
     *
     * @var array|null
     */
    private ?array $settings = null;

    /**
     * Get all settings merged with defaults.
     *
     * @return array
     */
    public function get_all(): array {
        if ( null === $this->settings ) {
            $saved          = get_option( self::OPTION_KEY, array() );
            $this->settings = $this->merge_with_defaults( is_array( $saved ) ? $saved : array() );
        }

        return $this->settings;
    }

    /**
     * Get a specific settings group or nested value.
     *
     * @param string $key     Dot-notation key (e.g., 'general', 'ai_provider.active_provider').
     * @param mixed  $default Default if key not found.
     * @return mixed
     */
    public function get( string $key, $default = null ) {
        $settings = $this->get_all();
        $keys     = explode( '.', $key );
        $value    = $settings;

        foreach ( $keys as $k ) {
            if ( ! is_array( $value ) || ! array_key_exists( $k, $value ) ) {
                return $default;
            }
            $value = $value[ $k ];
        }

        return $value;
    }

    /**
     * Update a settings group.
     *
     * @param string $group  Top-level group key (e.g., 'general', 'ai_provider').
     * @param array  $values Values to merge into the group.
     * @return bool Whether the update was successful.
     */
    public function update_group( string $group, array $values ): bool {
        $settings = $this->get_all();

        if ( ! array_key_exists( $group, $settings ) ) {
            return false;
        }

        $sanitized = $this->sanitize_group( $group, $values );
        $settings[ $group ] = array_merge( $settings[ $group ], $sanitized );

        return $this->save( $settings );
    }

    /**
     * Update all settings at once.
     *
     * @param array $new_settings Full settings array.
     * @return bool
     */
    public function update_all( array $new_settings ): bool {
        $defaults  = $this->get_defaults();
        $sanitized = array();

        foreach ( $defaults as $group => $group_defaults ) {
            if ( isset( $new_settings[ $group ] ) && is_array( $new_settings[ $group ] ) ) {
                $sanitized[ $group ] = array_merge(
                    $group_defaults,
                    $this->sanitize_group( $group, $new_settings[ $group ] )
                );
            } else {
                $sanitized[ $group ] = $group_defaults;
            }
        }

        return $this->save( $sanitized );
    }

    /**
     * Save settings to the database.
     *
     * @param array $settings Settings to save.
     * @return bool
     */
	private function save( array $settings ): bool {
		$this->settings = $settings;

		$existing = get_option( self::OPTION_KEY, null );
		if ( $existing === $settings ) {
			// WordPress returns false from update_option when value is unchanged.
			// Treat "no changes" as a successful save operation.
			return true;
		}

		return update_option( self::OPTION_KEY, $settings, true );
	}

    /**
     * Reset all settings to defaults.
     *
     * @return bool
     */
    public function reset(): bool {
        $this->settings = null;
        return update_option( self::OPTION_KEY, $this->get_defaults(), true );
    }

    /**
     * Clear the cached settings (force re-read from DB).
     */
    public function clear_cache(): void {
        $this->settings = null;
    }

    /**
     * Merge saved settings with defaults (deep merge).
     *
     * @param array $saved Saved settings from database.
     * @return array
     */
    private function merge_with_defaults( array $saved ): array {
        $defaults = $this->get_defaults();
        $merged   = array();

        foreach ( $defaults as $group => $group_defaults ) {
			if ( isset( $saved[ $group ] ) && is_array( $saved[ $group ] ) ) {
				$merged[ $group ] = array_merge( $group_defaults, $saved[ $group ] );

                // Deep merge for nested arrays like 'providers' in ai_provider.
                if ( 'ai_provider' === $group && isset( $saved[ $group ]['providers'] ) ) {
                    foreach ( $group_defaults['providers'] as $provider_key => $provider_defaults ) {
                        if ( isset( $saved[ $group ]['providers'][ $provider_key ] ) ) {
                            $merged[ $group ]['providers'][ $provider_key ] = array_merge(
                                $provider_defaults,
                                $saved[ $group ]['providers'][ $provider_key ]
                            );
                        }
                    }
                }

				// Deep merge for embedding sub-providers (pinecone, qdrant).
				if ( 'embedding' === $group ) {
					foreach ( array( 'pinecone', 'qdrant' ) as $sub_key ) {
                        if ( isset( $saved[ $group ][ $sub_key ] ) && is_array( $saved[ $group ][ $sub_key ] ) ) {
                            $merged[ $group ][ $sub_key ] = array_merge(
                                $group_defaults[ $sub_key ],
                                $saved[ $group ][ $sub_key ]
                            );
                        }
                    }

					if ( isset( $saved[ $group ]['providers'] ) && is_array( $saved[ $group ]['providers'] ) ) {
						foreach ( $group_defaults['providers'] as $provider_key => $provider_defaults ) {
							if ( isset( $saved[ $group ]['providers'][ $provider_key ] ) && is_array( $saved[ $group ]['providers'][ $provider_key ] ) ) {
								$merged[ $group ]['providers'][ $provider_key ] = array_merge(
									$provider_defaults,
									$saved[ $group ]['providers'][ $provider_key ]
								);
							}
						}
					}

					// Backward compatibility with legacy single-provider keys.
					// Only migrate legacy keys when modern provider config is missing.
					$has_modern_active = isset( $saved[ $group ]['active_provider'] )
						&& '' !== trim( (string) $saved[ $group ]['active_provider'] );
					$has_modern_providers = isset( $saved[ $group ]['providers'] )
						&& is_array( $saved[ $group ]['providers'] )
						&& ! empty( $saved[ $group ]['providers'] );

					if ( ! $has_modern_active && ! $has_modern_providers && isset( $saved[ $group ]['provider'] ) ) {
						$legacy_provider = sanitize_key( (string) $saved[ $group ]['provider'] );
						if ( isset( $merged[ $group ]['providers'][ $legacy_provider ] ) ) {
							if ( isset( $saved[ $group ]['api_key'] ) && '' !== trim( (string) $saved[ $group ]['api_key'] ) ) {
								$merged[ $group ]['providers'][ $legacy_provider ]['api_key'] = (string) $saved[ $group ]['api_key'];
							}
							if ( isset( $saved[ $group ]['model'] ) && '' !== trim( (string) $saved[ $group ]['model'] ) ) {
								$merged[ $group ]['providers'][ $legacy_provider ]['model'] = (string) $saved[ $group ]['model'];
							}
							$merged[ $group ]['providers'][ $legacy_provider ]['enabled'] = true;
							$merged[ $group ]['active_provider'] = $legacy_provider;
						}
					}
                }

				// Deep merge for voice providers and limits.
				if ( 'voice' === $group ) {
					if ( isset( $saved[ $group ]['providers'] ) && is_array( $saved[ $group ]['providers'] ) ) {
						foreach ( $group_defaults['providers'] as $provider_key => $provider_defaults ) {
							if ( isset( $saved[ $group ]['providers'][ $provider_key ] ) && is_array( $saved[ $group ]['providers'][ $provider_key ] ) ) {
								$merged[ $group ]['providers'][ $provider_key ] = array_merge(
									$provider_defaults,
									$saved[ $group ]['providers'][ $provider_key ]
								);
							}
						}
					}

					if ( isset( $saved[ $group ]['limits'] ) && is_array( $saved[ $group ]['limits'] ) ) {
						$merged[ $group ]['limits'] = array_merge(
							$group_defaults['limits'],
							$saved[ $group ]['limits']
						);
					}
				}

				if ( 'external_platforms' === $group ) {
					foreach ( array( 'wordpress', 'zendesk', 'notion', 'content_options' ) as $sub_key ) {
						if ( isset( $saved[ $group ][ $sub_key ] ) && is_array( $saved[ $group ][ $sub_key ] ) ) {
							$merged[ $group ][ $sub_key ] = array_merge(
								$group_defaults[ $sub_key ],
								$saved[ $group ][ $sub_key ]
							);
						}
					}
				}
            } else {
                $merged[ $group ] = $group_defaults;
            }
        }

        return $merged;
    }

    /**
     * Get all default settings.
     *
     * @return array
     */
    public function get_defaults(): array {
        return array(
			'general'     => array(
				'bot_name'                     => 'Kivor',
				'use_in_app_intro'             => false,
				'chatbot_title'                => '',
				'chatbot_description'          => '',
				'first_greeting_message'       => 'Hi, welcome! You\'re speaking with AI Agent. I\'m here to answer your questions and help you out.',
				'bot_avatar'                   => '',
				'widget_logo_id'              => 0,
				'widget_logo'                 => '',
				'chat_tab_label'               => 'Chat',
				'chat_position'                => 'bottom-right',
				'show_end_session_button'      => false,
				'custom_instructions'          => '',
				'override_system_instructions' => false,
				'total_uninstall'              => false,
				'custom_css'                   => '',
			),

            'ai_provider' => array(
                'active_provider'         => 'openai',
                'conversation_memory_size' => 10,
                'providers'               => array(
                    'openai'     => array(
                        'api_key' => '',
                        'model'   => 'gpt-4o-mini',
                        'enabled' => true,
                    ),
                    'gemini'     => array(
                        'api_key' => '',
                        'model'   => 'gemini-2.5-flash',
                        'enabled' => false,
                    ),
                    'openrouter' => array(
                        'api_key' => '',
                        'model'   => '',
                        'enabled' => false,
                    ),
                ),
            ),

			'embedding'   => array(
                'active_provider'       => 'openai',
                'fallback_provider'     => 'local',
                'vector_store'         => 'local',
                'sync_on_product_save' => true,
                'providers'            => array(
                    'openai'      => array(
                        'enabled' => true,
                        'api_key' => '',
                        'model'   => 'text-embedding-3-small',
                    ),
                    'gemini'      => array(
                        'enabled' => false,
                        'api_key' => '',
                        'model'   => 'gemini-embedding-001',
                    ),
                    'openrouter'  => array(
                        'enabled' => false,
                        'api_key' => '',
                        'model'   => 'openai/text-embedding-3-small',
                    ),
                    'cohere'      => array(
                        'enabled' => false,
                        'api_key' => '',
                        'model'   => 'embed-english-v3.0',
                    ),
                ),
                'pinecone'             => array(
                    'api_key'     => '',
                    'index_name'  => '',
                    'environment' => '',
                ),
                'qdrant'               => array(
                    'endpoint_url'    => '',
                    'api_key'         => '',
                    'collection_name' => 'kivor_chat_agent_products',
                ),
            ),

            'appearance'  => array(
                'product_card_show_price'       => true,
                'product_card_show_link'        => true,
                'product_card_show_add_to_cart'  => true,
                'product_card_show_image'       => true,
                'product_card_layout'           => 'carousel',
				'widget_primary_color'         => '#10b981',
				'widget_primary_hover_color'   => '#059669',
				'widget_primary_text_color'    => '#ffffff',
				'widget_background_color'      => '#ffffff',
				'widget_background_alt_color'  => '#f3f4f6',
				'widget_text_color'            => '#1f2937',
				'widget_text_muted_color'      => '#6b7280',
				'widget_border_color'          => '#e5e7eb',
				'widget_user_bubble_color'     => '#10b981',
				'widget_user_text_color'       => '#ffffff',
				'widget_bot_bubble_color'      => '#f3f4f6',
				'widget_bot_text_color'        => '#1f2937',
				'widget_tab_background_color'  => '#ffffff',
				'widget_tab_text_color'        => '#374151',
				'widget_tab_active_color'      => '#10b981',
				'widget_tab_active_text_color' => '#10b981',
            ),

            'chat_logs'   => array(
                'logging_enabled'    => true,
                'auto_cleanup_days'  => 90,
            ),

			'analytics'   => array(
				'enabled'          => false,
				'provider'         => 'openai',
				'analyze_mode'     => 'first_message',
				'alert_threshold'  => 30,
				'alert_email'      => '',
				'attribution_days' => 14,
			),

            'gdpr'        => array(
                'enabled'              => true,
                'consent_required'     => true,
                'consent_message'      => 'By chatting, you agree to our data processing. See our Privacy Policy.',
                'data_retention_days'  => 90,
                'anonymize_ips'        => true,
                'show_privacy_link'    => true,
                'privacy_page_id'      => 0,
            ),

            'whatsapp'    => array(
                'enabled'            => false,
                'name'               => 'WhatsApp Support',
                'number'             => '',
                'prefilled_message'  => 'Hello! I need help with...',
            ),

			'phone_call'  => array(
				'enabled'             => false,
				'mobile_only'         => true,
				'number'              => '',
				'button_label'        => 'Call Support',
			),

			'forms'       => array(
				'enabled'              => false,
				'primary_form_id'      => 0,
				'tab_form_id'          => 0,
				'tab_label'            => 'Form',
				'primary_block_input'  => true,
				'primary_allow_skip'   => false,
				'primary_submit_message' => 'Thanks. What can I help you with today?',
				'show_field_titles'    => true,
				'notify_email_enabled' => false,
				'notify_email_to'      => '',
			),

			'voice'       => array(
				'enabled'               => false,
				'input_enabled'         => true,
				'interaction_mode'      => 'push_to_talk',
				'auto_send_mode'        => 'silence',
				'auto_send_delay_ms'    => 800,
				'confidence_threshold'  => 0.65,
				'auto_detect_language'  => false,
				'default_language'      => 'en-US',
				'stt_provider'          => 'webspeech',
				'stt_model'             => '',
				'providers'             => array(
					'openai'   => array(
						'api_key'   => '',
						'stt_model' => 'gpt-4o-mini-transcribe',
					),
					'cartesia' => array(
						'api_key'   => '',
						'version'   => '2025-04-16',
						'stt_model' => 'ink-whisper',
					),
					'deepgram' => array(
						'api_key'   => '',
						'stt_model' => 'nova-2',
					),
				),
				'limits'               => array(
					'max_stt_seconds' => 20,
				),
			),

			'external_platforms' => array(
				'wordpress'       => array(
					'enabled'       => false,
					'posts_enabled' => true,
					'pages_enabled' => true,
					'sync_mode'     => 'incremental',
					'trigger'       => 'daily',
					'last_sync_at'   => '',
					'last_sync_ok'   => false,
					'last_sync_message' => '',
					'last_sync_counts'  => array(),
					'last_test_at'   => '',
					'last_test_ok'   => false,
					'last_test_message' => '',
				),
				'zendesk'        => array(
					'enabled'    => false,
					'subdomain'  => '',
					'email'      => '',
					'api_token'  => '',
					'sync_mode'  => 'incremental',
					'trigger'    => 'manual',
					'last_sync_at'   => '',
					'last_sync_ok'   => false,
					'last_sync_message' => '',
					'last_sync_counts'  => array(),
					'last_test_at'   => '',
					'last_test_ok'   => false,
					'last_test_message' => '',
				),
				'notion'         => array(
					'enabled'     => false,
					'api_key'     => '',
					'database_id' => '',
					'sync_mode'   => 'incremental',
					'trigger'     => 'manual',
					'last_sync_at'   => '',
					'last_sync_ok'   => false,
					'last_sync_message' => '',
					'last_sync_counts'  => array(),
					'last_test_at'   => '',
					'last_test_ok'   => false,
					'last_test_message' => '',
				),
				'content_options' => array(
					'split_by_headers' => false,
					'add_read_more'    => false,
				),
			),
        );
    }

    /**
     * Sanitize a settings group.
     *
     * @param string $group  Group key.
     * @param array  $values Raw values to sanitize.
     * @return array Sanitized values.
     */
    private function sanitize_group( string $group, array $values ): array {
        $sanitized = array();

			switch ( $group ) {
            case 'general':
                $sanitized = $this->sanitize_general( $values );
                break;

            case 'ai_provider':
                $sanitized = $this->sanitize_ai_provider( $values );
                break;

            case 'embedding':
                $sanitized = $this->sanitize_embedding( $values );
                break;

            case 'appearance':
                $sanitized = $this->sanitize_appearance( $values );
                break;

			case 'chat_logs':
				$sanitized = $this->sanitize_chat_logs( $values );
				break;

			case 'analytics':
				$sanitized = $this->sanitize_analytics( $values );
				break;

            case 'gdpr':
                $sanitized = $this->sanitize_gdpr( $values );
                break;

			case 'whatsapp':
				$sanitized = $this->sanitize_whatsapp( $values );
				break;

			case 'phone_call':
				$sanitized = $this->sanitize_phone_call( $values );
				break;

			case 'voice':
				$sanitized = $this->sanitize_voice( $values );
				break;

			case 'forms':
				$sanitized = $this->sanitize_forms( $values );
				break;

			case 'external_platforms':
				$sanitized = $this->sanitize_external_platforms( $values );
				break;

            default:
                $sanitized = array_map( 'sanitize_text_field', $values );
                break;
        }

        return $sanitized;
    }

    /**
     * Sanitize general settings.
     *
     * @param array $values Raw values.
     * @return array
     */
    private function sanitize_general( array $values ): array {
        $sanitized = array();

        if ( isset( $values['bot_name'] ) ) {
	            $sanitized['bot_name'] = sanitize_text_field( $values['bot_name'] );
        }

		if ( isset( $values['use_in_app_intro'] ) ) {
			$sanitized['use_in_app_intro'] = (bool) $values['use_in_app_intro'];
		}

		if ( isset( $values['chatbot_title'] ) ) {
			$sanitized['chatbot_title'] = sanitize_text_field( $values['chatbot_title'] );
		}

		if ( isset( $values['chatbot_description'] ) ) {
			$sanitized['chatbot_description'] = sanitize_textarea_field( $values['chatbot_description'] );
		}

		if ( isset( $values['first_greeting_message'] ) ) {
			$sanitized['first_greeting_message'] = sanitize_textarea_field( $values['first_greeting_message'] );
		}

        if ( isset( $values['bot_avatar'] ) ) {
            $sanitized['bot_avatar'] = esc_url_raw( $values['bot_avatar'] );
        }

        if ( isset( $values['widget_logo'] ) ) {
            $widget_logo = esc_url_raw( $values['widget_logo'] );
            $sanitized['widget_logo'] = $this->is_allowed_widget_logo_url( $widget_logo ) ? $widget_logo : '';
        }

        if ( isset( $values['widget_logo_id'] ) ) {
            $sanitized['widget_logo_id'] = absint( $values['widget_logo_id'] );
        }

        if ( isset( $values['chat_tab_label'] ) ) {
	            $sanitized['chat_tab_label'] = sanitize_text_field( $values['chat_tab_label'] );
        }

        if ( isset( $values['chat_position'] ) ) {
            $sanitized['chat_position'] = in_array( $values['chat_position'], array( 'bottom-right', 'bottom-left' ), true )
                ? $values['chat_position']
                : 'bottom-right';
        }

        if ( isset( $values['show_end_session_button'] ) ) {
            $sanitized['show_end_session_button'] = (bool) $values['show_end_session_button'];
        }

        if ( isset( $values['custom_instructions'] ) ) {
			$sanitized['custom_instructions'] = sanitize_textarea_field( $values['custom_instructions'] );
		}

		if ( isset( $values['override_system_instructions'] ) ) {
			$sanitized['override_system_instructions'] = (bool) $values['override_system_instructions'];
		}

		if ( isset( $values['total_uninstall'] ) ) {
			$sanitized['total_uninstall'] = (bool) $values['total_uninstall'];
		}

        if ( isset( $values['custom_css'] ) ) {
            $sanitized['custom_css'] = wp_strip_all_tags( $values['custom_css'] );
        }

        return $sanitized;
    }

    /**
     * Validate custom widget logo URL.
     *
     * Only PNG and SVG files are allowed.
     *
     * @param string $url Logo URL.
     * @return bool
     */
    private function is_allowed_widget_logo_url( string $url ): bool {
        if ( '' === trim( $url ) ) {
            return true;
        }

        $path = wp_parse_url( $url, PHP_URL_PATH );
        if ( ! is_string( $path ) || '' === $path ) {
            return false;
        }

        $ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
        return in_array( $ext, array( 'png', 'svg' ), true );
    }

    /**
     * Sanitize AI provider settings.
     *
     * @param array $values Raw values.
     * @return array
     */
    private function sanitize_ai_provider( array $values ): array {
        $sanitized = array();

        if ( isset( $values['active_provider'] ) ) {
            $sanitized['active_provider'] = in_array( $values['active_provider'], array( 'openai', 'gemini', 'openrouter' ), true )
                ? $values['active_provider']
                : 'openai';
        }

        if ( isset( $values['conversation_memory_size'] ) ) {
            $sanitized['conversation_memory_size'] = min( max( absint( $values['conversation_memory_size'] ), 0 ), 50 );
        }

        if ( isset( $values['providers'] ) && is_array( $values['providers'] ) ) {
            $sanitized['providers'] = array();
            foreach ( $values['providers'] as $provider_key => $provider_values ) {
                if ( ! in_array( $provider_key, array( 'openai', 'gemini', 'openrouter' ), true ) ) {
                    continue;
                }
                $sanitized['providers'][ $provider_key ] = array(
                    'api_key' => isset( $provider_values['api_key'] ) ? sanitize_text_field( $provider_values['api_key'] ) : '',
                    'model'   => isset( $provider_values['model'] ) ? sanitize_text_field( $provider_values['model'] ) : '',
                    'enabled' => isset( $provider_values['enabled'] ) ? (bool) $provider_values['enabled'] : false,
                );

                if ( 'gemini' === $provider_key ) {
                    $sanitized['providers'][ $provider_key ]['model'] = $this->normalize_gemini_chat_model( $sanitized['providers'][ $provider_key ]['model'] );
                }
            }
        }

        if ( isset( $sanitized['active_provider'] ) && 'gemini' === $sanitized['active_provider'] ) {
            if ( ! isset( $sanitized['providers']['gemini'] ) || ! is_array( $sanitized['providers']['gemini'] ) ) {
                $sanitized['providers']['gemini'] = array(
                    'api_key' => '',
                    'model'   => 'gemini-2.5-flash',
                    'enabled' => false,
                );
            }

            $sanitized['providers']['gemini']['model'] = $this->normalize_gemini_chat_model( (string) ( $sanitized['providers']['gemini']['model'] ?? '' ) );
        }

        return $sanitized;
    }

    /**
     * Normalize Gemini chat model values for backward compatibility.
     *
     * @param string $model Model ID from settings.
     * @return string
     */
    private function normalize_gemini_chat_model( string $model ): string {
        $model = trim( $model );

        $legacy_map = array(
            'gemini-1.5-flash' => 'gemini-2.5-flash',
            'gemini-1.5-pro'   => 'gemini-2.5-pro',
            'gemini-2.0-flash' => 'gemini-2.5-flash',
        );

        if ( isset( $legacy_map[ $model ] ) ) {
            return $legacy_map[ $model ];
        }

        $allowed_models = array(
            'gemini-2.5-flash',
            'gemini-2.5-flash-lite',
            'gemini-2.5-pro',
        );

        if ( in_array( $model, $allowed_models, true ) ) {
            return $model;
        }

        return 'gemini-2.5-flash';
    }

    /**
     * Sanitize embedding settings.
     *
     * @param array $values Raw values.
     * @return array
     */
    private function sanitize_embedding( array $values ): array {
        $sanitized = array();

        $provider_keys = array( 'openai', 'gemini', 'openrouter', 'cohere' );

        if ( isset( $values['active_provider'] ) ) {
            $active_provider = sanitize_key( (string) $values['active_provider'] );
            $sanitized['active_provider'] = in_array( $active_provider, $provider_keys, true )
                ? $active_provider
                : 'openai';
        }

        if ( isset( $values['fallback_provider'] ) ) {
            $fallback_provider = sanitize_key( (string) $values['fallback_provider'] );
            $valid_fallbacks   = array_merge( array( 'none', 'local' ), $provider_keys );
            $sanitized['fallback_provider'] = in_array( $fallback_provider, $valid_fallbacks, true )
                ? $fallback_provider
                : 'local';
        }

        if ( isset( $values['providers'] ) && is_array( $values['providers'] ) ) {
            $providers = array();

            foreach ( $provider_keys as $provider_key ) {
                $provider_values = isset( $values['providers'][ $provider_key ] ) && is_array( $values['providers'][ $provider_key ] )
                    ? $values['providers'][ $provider_key ]
                    : array();

                $provider = array(
                    'enabled' => ! empty( $provider_values['enabled'] ),
                    'api_key' => isset( $provider_values['api_key'] ) ? sanitize_text_field( (string) $provider_values['api_key'] ) : '',
                    'model'   => isset( $provider_values['model'] ) ? sanitize_text_field( (string) $provider_values['model'] ) : '',
                );


                $providers[ $provider_key ] = $provider;
            }

            $sanitized['providers'] = $providers;
        }

		// Backward compatibility with legacy single-provider fields.
		if ( isset( $values['provider'] ) && ! isset( $sanitized['active_provider'] ) ) {
			$legacy_provider = sanitize_key( (string) $values['provider'] );
			if ( in_array( $legacy_provider, $provider_keys, true ) ) {
				$sanitized['active_provider'] = $legacy_provider;
			}
		}

		if ( ! isset( $sanitized['active_provider'] ) && isset( $values['providers'] ) && is_array( $values['providers'] ) ) {
			foreach ( $provider_keys as $provider_key ) {
				if ( ! empty( $values['providers'][ $provider_key ]['enabled'] ) ) {
					$sanitized['active_provider'] = $provider_key;
					break;
				}
			}
		}

		if ( ! isset( $sanitized['active_provider'] ) ) {
			$sanitized['active_provider'] = 'openai';
		}

		if ( isset( $sanitized['fallback_provider'] ) && $sanitized['fallback_provider'] === $sanitized['active_provider'] ) {
			$sanitized['fallback_provider'] = 'none';
		}

		if ( isset( $values['api_key'] ) ) {
			$legacy_provider = $sanitized['active_provider'] ?? 'openai';
			if ( ! isset( $sanitized['providers'] ) ) {
				$sanitized['providers'] = array();
			}
			if ( ! isset( $sanitized['providers'][ $legacy_provider ] ) ) {
				$sanitized['providers'][ $legacy_provider ] = array(
					'enabled' => true,
					'api_key' => '',
					'model'   => '',
				);
			}
			$sanitized['providers'][ $legacy_provider ]['api_key'] = sanitize_text_field( (string) $values['api_key'] );
		}

		if ( isset( $values['model'] ) ) {
			$legacy_provider = $sanitized['active_provider'] ?? 'openai';
			if ( ! isset( $sanitized['providers'] ) ) {
				$sanitized['providers'] = array();
			}
			if ( ! isset( $sanitized['providers'][ $legacy_provider ] ) ) {
				$sanitized['providers'][ $legacy_provider ] = array(
					'enabled' => true,
					'api_key' => '',
					'model'   => '',
				);
			}
			$sanitized['providers'][ $legacy_provider ]['model'] = sanitize_text_field( (string) $values['model'] );
		}

        if ( isset( $values['vector_store'] ) ) {
            $sanitized['vector_store'] = in_array( $values['vector_store'], array( 'local', 'pinecone', 'qdrant' ), true )
                ? $values['vector_store']
                : 'local';
        }

        if ( isset( $values['sync_on_product_save'] ) ) {
            $sanitized['sync_on_product_save'] = (bool) $values['sync_on_product_save'];
        }

        if ( isset( $values['pinecone'] ) && is_array( $values['pinecone'] ) ) {
            $sanitized['pinecone'] = array(
                'api_key'     => isset( $values['pinecone']['api_key'] ) ? sanitize_text_field( $values['pinecone']['api_key'] ) : '',
                'index_name'  => isset( $values['pinecone']['index_name'] ) ? sanitize_text_field( $values['pinecone']['index_name'] ) : '',
                'environment' => isset( $values['pinecone']['environment'] ) ? sanitize_text_field( $values['pinecone']['environment'] ) : '',
            );
        }

        if ( isset( $values['qdrant'] ) && is_array( $values['qdrant'] ) ) {
            $sanitized['qdrant'] = array(
                'endpoint_url'    => isset( $values['qdrant']['endpoint_url'] ) ? esc_url_raw( $values['qdrant']['endpoint_url'] ) : '',
                'api_key'         => isset( $values['qdrant']['api_key'] ) ? sanitize_text_field( $values['qdrant']['api_key'] ) : '',
                'collection_name' => isset( $values['qdrant']['collection_name'] ) ? sanitize_text_field( $values['qdrant']['collection_name'] ) : 'kivor_chat_agent_products',
            );
        }

        return $sanitized;
    }

    /**
     * Sanitize appearance settings.
     *
     * @param array $values Raw values.
     * @return array
     */
    private function sanitize_appearance( array $values ): array {
        $sanitized = array();

        foreach ( array( 'product_card_show_price', 'product_card_show_link', 'product_card_show_add_to_cart', 'product_card_show_image' ) as $key ) {
            if ( isset( $values[ $key ] ) ) {
                $sanitized[ $key ] = (bool) $values[ $key ];
            }
        }

        if ( isset( $values['product_card_layout'] ) ) {
            $sanitized['product_card_layout'] = in_array( $values['product_card_layout'], array( 'carousel', 'list' ), true )
                ? $values['product_card_layout']
                : 'carousel';
        }

		foreach ( array(
			'widget_primary_color',
			'widget_primary_hover_color',
			'widget_primary_text_color',
			'widget_background_color',
			'widget_background_alt_color',
			'widget_text_color',
			'widget_text_muted_color',
			'widget_border_color',
			'widget_user_bubble_color',
			'widget_user_text_color',
			'widget_bot_bubble_color',
			'widget_bot_text_color',
			'widget_tab_background_color',
			'widget_tab_text_color',
			'widget_tab_active_color',
			'widget_tab_active_text_color',
		) as $color_key ) {
			if ( ! isset( $values[ $color_key ] ) ) {
				continue;
			}

			$color = sanitize_hex_color( (string) $values[ $color_key ] );
			if ( $color ) {
				$sanitized[ $color_key ] = $color;
			}
		}

        return $sanitized;
    }

    /**
     * Sanitize chat logs settings.
     *
     * @param array $values Raw values.
     * @return array
     */
    private function sanitize_chat_logs( array $values ): array {
        $sanitized = array();

        if ( isset( $values['logging_enabled'] ) ) {
            $sanitized['logging_enabled'] = (bool) $values['logging_enabled'];
        }

        if ( isset( $values['auto_cleanup_days'] ) ) {
            $sanitized['auto_cleanup_days'] = max( absint( $values['auto_cleanup_days'] ), 0 );
        }

        return $sanitized;
    }

	/**
	 * Sanitize analytics settings.
	 *
	 * @param array $values Raw values.
	 * @return array
	 */
	private function sanitize_analytics( array $values ): array {
		$sanitized = array();

		if ( isset( $values['enabled'] ) ) {
			$sanitized['enabled'] = (bool) $values['enabled'];
		}

		if ( isset( $values['provider'] ) ) {
			$sanitized['provider'] = in_array( $values['provider'], array( 'openai', 'gemini', 'openrouter' ), true )
				? $values['provider']
				: 'openai';
		}

		if ( isset( $values['analyze_mode'] ) ) {
			$sanitized['analyze_mode'] = in_array( $values['analyze_mode'], array( 'first_message', 'every_message' ), true )
				? $values['analyze_mode']
				: 'first_message';
		}

		if ( isset( $values['alert_threshold'] ) ) {
			$sanitized['alert_threshold'] = min( max( absint( $values['alert_threshold'] ), 1 ), 100 );
		}

		if ( isset( $values['alert_email'] ) ) {
			$email = sanitize_email( (string) $values['alert_email'] );
			$sanitized['alert_email'] = is_email( $email ) ? $email : '';
		}

		if ( isset( $values['attribution_days'] ) ) {
			$sanitized['attribution_days'] = min( max( absint( $values['attribution_days'] ), 7 ), 30 );
		}

		return $sanitized;
	}

    /**
     * Sanitize GDPR settings.
     *
     * @param array $values Raw values.
     * @return array
     */
    private function sanitize_gdpr( array $values ): array {
        $sanitized = array();

        foreach ( array( 'enabled', 'consent_required', 'anonymize_ips', 'show_privacy_link' ) as $key ) {
            if ( isset( $values[ $key ] ) ) {
                $sanitized[ $key ] = (bool) $values[ $key ];
            }
        }

        if ( isset( $values['consent_message'] ) ) {
            $sanitized['consent_message'] = sanitize_textarea_field( $values['consent_message'] );
        }

        if ( isset( $values['data_retention_days'] ) ) {
            $sanitized['data_retention_days'] = max( absint( $values['data_retention_days'] ), 0 );
        }

        if ( isset( $values['privacy_page_id'] ) ) {
            $sanitized['privacy_page_id'] = absint( $values['privacy_page_id'] );
        }

        return $sanitized;
    }

    /**
     * Sanitize WhatsApp settings.
     *
     * @param array $values Raw values.
     * @return array
     */
	private function sanitize_whatsapp( array $values ): array {
        $sanitized = array();

        if ( isset( $values['enabled'] ) ) {
            $sanitized['enabled'] = (bool) $values['enabled'];
        }

        if ( isset( $values['name'] ) ) {
            $sanitized['name'] = sanitize_text_field( $values['name'] );
        }

        if ( isset( $values['number'] ) ) {
            // Strip everything except digits and leading +.
            $sanitized['number'] = preg_replace( '/[^\d+]/', '', $values['number'] );
        }

        if ( isset( $values['prefilled_message'] ) ) {
            $sanitized['prefilled_message'] = sanitize_textarea_field( $values['prefilled_message'] );
        }

        return $sanitized;
    }

	/**
	 * Sanitize voice settings.
	 *
	 * @param array $values Raw values.
	 * @return array
	 */
	private function sanitize_voice( array $values ): array {
		$sanitized = array();

		if ( isset( $values['enabled'] ) ) {
			$sanitized['enabled'] = (bool) $values['enabled'];
		}

		if ( isset( $values['input_enabled'] ) ) {
			$sanitized['input_enabled'] = (bool) $values['input_enabled'];
		}


		if ( isset( $values['interaction_mode'] ) ) {
			$sanitized['interaction_mode'] = in_array( $values['interaction_mode'], array( 'push_to_talk' ), true )
				? $values['interaction_mode']
				: 'push_to_talk';
		}


		if ( isset( $values['auto_send_mode'] ) ) {
			$sanitized['auto_send_mode'] = in_array( $values['auto_send_mode'], array( 'silence', 'manual' ), true )
				? $values['auto_send_mode']
				: 'silence';
		}

		if ( isset( $values['auto_send_delay_ms'] ) ) {
			$sanitized['auto_send_delay_ms'] = min( max( absint( $values['auto_send_delay_ms'] ), 200 ), 5000 );
		}

		if ( isset( $values['confidence_threshold'] ) ) {
			$threshold = floatval( $values['confidence_threshold'] );
			if ( $threshold < 0 ) {
				$threshold = 0;
			}
			if ( $threshold > 1 ) {
				$threshold = 1;
			}
			$sanitized['confidence_threshold'] = $threshold;
		}

		if ( isset( $values['auto_detect_language'] ) ) {
			$sanitized['auto_detect_language'] = (bool) $values['auto_detect_language'];
		}

		if ( isset( $values['default_language'] ) ) {
			$sanitized['default_language'] = sanitize_text_field( $values['default_language'] );
		}

		if ( isset( $values['stt_provider'] ) ) {
			$sanitized['stt_provider'] = in_array( $values['stt_provider'], array( 'webspeech', 'openai', 'cartesia', 'deepgram' ), true )
				? $values['stt_provider']
				: 'webspeech';
		}

		if ( isset( $values['stt_model'] ) ) {
			$sanitized['stt_model'] = sanitize_text_field( $values['stt_model'] );
		}

		if ( isset( $values['providers'] ) && is_array( $values['providers'] ) ) {
			$providers = array();
			$sanitize_provider_model = static function ( array $provider_values, string $key, string $default ): string {
				if ( isset( $provider_values[ $key ] ) ) {
					$value = sanitize_text_field( $provider_values[ $key ] );
					return '' === trim( $value ) ? $default : $value;
				}

				return $default;
			};
			foreach ( array( 'openai', 'cartesia', 'deepgram' ) as $provider ) {
				$provider_values = isset( $values['providers'][ $provider ] ) && is_array( $values['providers'][ $provider ] )
					? $values['providers'][ $provider ]
					: array();

				switch ( $provider ) {
					case 'openai':
						$providers['openai'] = array(
							'api_key'   => isset( $provider_values['api_key'] ) ? sanitize_text_field( $provider_values['api_key'] ) : '',
							'stt_model' => $sanitize_provider_model( $provider_values, 'stt_model', 'gpt-4o-mini-transcribe' ),
						);
						break;

					case 'cartesia':
						$providers['cartesia'] = array(
							'api_key'   => isset( $provider_values['api_key'] ) ? sanitize_text_field( $provider_values['api_key'] ) : '',
							'version'   => $sanitize_provider_model( $provider_values, 'version', '2025-04-16' ),
							'stt_model' => $sanitize_provider_model( $provider_values, 'stt_model', 'ink-whisper' ),
						);
						break;

					case 'deepgram':
						$providers['deepgram'] = array(
							'api_key'   => isset( $provider_values['api_key'] ) ? sanitize_text_field( $provider_values['api_key'] ) : '',
							'stt_model' => $sanitize_provider_model( $provider_values, 'stt_model', 'nova-2' ),
						);
						break;

				}
			}
			$sanitized['providers'] = $providers;
		}

		if ( isset( $values['limits'] ) && is_array( $values['limits'] ) ) {
			$sanitized['limits'] = array(
				'max_stt_seconds' => isset( $values['limits']['max_stt_seconds'] ) ? min( max( absint( $values['limits']['max_stt_seconds'] ), 5 ), 120 ) : 20,
			);
		}

		if ( isset( $sanitized['input_enabled'] ) ) {
			$sanitized['enabled'] = (bool) $sanitized['input_enabled'];
		}

		return $sanitized;
	}

	/**
	 * Sanitize phone call settings.
	 *
	 * @param array $values Raw values.
	 * @return array
	 */
	private function sanitize_phone_call( array $values ): array {
		$sanitized = array();

		if ( isset( $values['enabled'] ) ) {
			$sanitized['enabled'] = (bool) $values['enabled'];
		}

		if ( isset( $values['mobile_only'] ) ) {
			$sanitized['mobile_only'] = (bool) $values['mobile_only'];
		}

		if ( isset( $values['number'] ) ) {
			$sanitized['number'] = preg_replace( '/[^\d+]/', '', (string) $values['number'] );
		}

		if ( isset( $values['button_label'] ) ) {
			$sanitized['button_label'] = sanitize_text_field( $values['button_label'] );
		}

		return $sanitized;
	}

	/**
	 * Sanitize forms settings.
	 *
	 * @param array $values Raw values.
	 * @return array
	 */
	private function sanitize_forms( array $values ): array {
		$sanitized = array();
		$primary_block_input = null;

		if ( isset( $values['enabled'] ) ) {
			$sanitized['enabled'] = (bool) $values['enabled'];
		}

		if ( isset( $values['primary_form_id'] ) ) {
			$sanitized['primary_form_id'] = absint( $values['primary_form_id'] );
		}

		if ( isset( $values['tab_form_id'] ) ) {
			$sanitized['tab_form_id'] = absint( $values['tab_form_id'] );
		}

		if ( isset( $values['tab_label'] ) ) {
			$label = sanitize_text_field( (string) $values['tab_label'] );
			$sanitized['tab_label'] = '' !== trim( $label ) ? $label : 'Form';
		}

		if ( isset( $values['primary_block_input'] ) ) {
			$primary_block_input = (bool) $values['primary_block_input'];
			$sanitized['primary_block_input'] = $primary_block_input;
		}

		if ( isset( $values['primary_allow_skip'] ) ) {
			$allow_skip = (bool) $values['primary_allow_skip'];
			if ( true === $primary_block_input ) {
				$allow_skip = false;
			}
			$sanitized['primary_allow_skip'] = $allow_skip;
		}

		if ( true === $primary_block_input ) {
			$sanitized['primary_allow_skip'] = false;
		}

		if ( isset( $values['primary_submit_message'] ) ) {
			$message = sanitize_text_field( (string) $values['primary_submit_message'] );
			$sanitized['primary_submit_message'] = '' !== trim( $message ) ? $message : 'Thanks. What can I help you with today?';
		}

		if ( isset( $values['show_field_titles'] ) ) {
			$sanitized['show_field_titles'] = (bool) $values['show_field_titles'];
		}

		if ( isset( $values['notify_email_enabled'] ) ) {
			$sanitized['notify_email_enabled'] = (bool) $values['notify_email_enabled'];
		}

		if ( isset( $values['notify_email_to'] ) ) {
			$emails = is_array( $values['notify_email_to'] )
				? $values['notify_email_to']
				: explode( ',', (string) $values['notify_email_to'] );

			$emails = array_values(
				array_filter(
					array_map( 'trim', array_map( 'sanitize_email', $emails ) ),
					static function ( string $email ) {
						return '' !== $email && (bool) is_email( $email );
					}
				)
			);

			$sanitized['notify_email_to'] = implode( ',', $emails );
		}

		return $sanitized;
	}

	/**
	 * Sanitize external platform settings.
	 *
	 * @param array $values Raw values.
	 * @return array
	 */
	private function sanitize_external_platforms( array $values ): array {
		$sanitized = array();
		$current   = $this->get( 'external_platforms', array() );

		$get_status_fields = static function ( array $platform_current ): array {
			return array(
				'last_sync_at'      => (string) ( $platform_current['last_sync_at'] ?? '' ),
				'last_sync_ok'      => ! empty( $platform_current['last_sync_ok'] ),
				'last_sync_message' => (string) ( $platform_current['last_sync_message'] ?? '' ),
				'last_sync_counts'  => isset( $platform_current['last_sync_counts'] ) && is_array( $platform_current['last_sync_counts'] ) ? $platform_current['last_sync_counts'] : array(),
				'last_test_at'      => (string) ( $platform_current['last_test_at'] ?? '' ),
				'last_test_ok'      => ! empty( $platform_current['last_test_ok'] ),
				'last_test_message' => (string) ( $platform_current['last_test_message'] ?? '' ),
			);
		};

		$sanitize_sync_mode = static function ( $value ): string {
			$value = sanitize_key( (string) $value );
			return in_array( $value, array( 'full', 'incremental' ), true ) ? $value : 'incremental';
		};

		$sanitize_trigger = static function ( $value ): string {
			$value = sanitize_key( (string) $value );
			return in_array( $value, array( 'on_save', 'hourly', 'daily', 'manual' ), true ) ? $value : 'manual';
		};

		if ( isset( $values['wordpress'] ) && is_array( $values['wordpress'] ) ) {
			$status = $get_status_fields( is_array( $current['wordpress'] ?? null ) ? $current['wordpress'] : array() );
			$sanitized['wordpress'] = array(
				'enabled'       => ! empty( $values['wordpress']['enabled'] ),
				'posts_enabled' => ! empty( $values['wordpress']['posts_enabled'] ),
				'pages_enabled' => ! empty( $values['wordpress']['pages_enabled'] ),
				'sync_mode'     => $sanitize_sync_mode( $values['wordpress']['sync_mode'] ?? 'incremental' ),
				'trigger'       => $sanitize_trigger( $values['wordpress']['trigger'] ?? 'daily' ),
				'last_sync_at'      => $status['last_sync_at'],
				'last_sync_ok'      => $status['last_sync_ok'],
				'last_sync_message' => $status['last_sync_message'],
				'last_sync_counts'  => $status['last_sync_counts'],
				'last_test_at'      => $status['last_test_at'],
				'last_test_ok'      => $status['last_test_ok'],
				'last_test_message' => $status['last_test_message'],
			);
		}

		if ( isset( $values['zendesk'] ) && is_array( $values['zendesk'] ) ) {
			$status = $get_status_fields( is_array( $current['zendesk'] ?? null ) ? $current['zendesk'] : array() );
			$sanitized['zendesk'] = array(
				'enabled'   => ! empty( $values['zendesk']['enabled'] ),
				'subdomain' => sanitize_text_field( (string) ( $values['zendesk']['subdomain'] ?? '' ) ),
				'email'     => sanitize_email( (string) ( $values['zendesk']['email'] ?? '' ) ),
				'api_token' => sanitize_text_field( (string) ( $values['zendesk']['api_token'] ?? '' ) ),
				'sync_mode' => $sanitize_sync_mode( $values['zendesk']['sync_mode'] ?? 'incremental' ),
				'trigger'   => $sanitize_trigger( $values['zendesk']['trigger'] ?? 'manual' ),
				'last_sync_at'      => $status['last_sync_at'],
				'last_sync_ok'      => $status['last_sync_ok'],
				'last_sync_message' => $status['last_sync_message'],
				'last_sync_counts'  => $status['last_sync_counts'],
				'last_test_at'      => $status['last_test_at'],
				'last_test_ok'      => $status['last_test_ok'],
				'last_test_message' => $status['last_test_message'],
			);
		}

		if ( isset( $values['notion'] ) && is_array( $values['notion'] ) ) {
			$status = $get_status_fields( is_array( $current['notion'] ?? null ) ? $current['notion'] : array() );
			$sanitized['notion'] = array(
				'enabled'     => ! empty( $values['notion']['enabled'] ),
				'api_key'     => sanitize_text_field( (string) ( $values['notion']['api_key'] ?? '' ) ),
				'database_id' => sanitize_text_field( (string) ( $values['notion']['database_id'] ?? '' ) ),
				'sync_mode'   => $sanitize_sync_mode( $values['notion']['sync_mode'] ?? 'incremental' ),
				'trigger'     => $sanitize_trigger( $values['notion']['trigger'] ?? 'manual' ),
				'last_sync_at'      => $status['last_sync_at'],
				'last_sync_ok'      => $status['last_sync_ok'],
				'last_sync_message' => $status['last_sync_message'],
				'last_sync_counts'  => $status['last_sync_counts'],
				'last_test_at'      => $status['last_test_at'],
				'last_test_ok'      => $status['last_test_ok'],
				'last_test_message' => $status['last_test_message'],
			);
		}


		if ( isset( $values['content_options'] ) && is_array( $values['content_options'] ) ) {
			$sanitized['content_options'] = array(
				'split_by_headers' => ! empty( $values['content_options']['split_by_headers'] ),
				'add_read_more'    => ! empty( $values['content_options']['add_read_more'] ),
			);
		}

		return $sanitized;
	}
}
