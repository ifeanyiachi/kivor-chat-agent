<?php
/**
 * AI provider factory.
 *
 * Creates the appropriate AI provider instance based on settings.
 *
 * @package KivorAgent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class Kivor_AI_Factory {

    /**
     * Create an AI provider instance based on current settings.
     *
     * @param Kivor_Settings $settings Plugin settings.
     * @param string|null    $provider Override provider name (null = use active provider from settings).
     * @return Kivor_AI_Provider|WP_Error
     */
    public static function create( Kivor_Settings $settings, ?string $provider = null ) {
        $ai_settings   = $settings->get( 'ai_provider' );
        $provider_name = $provider ?? ( $ai_settings['active_provider'] ?? 'openai' );
        $providers     = $ai_settings['providers'] ?? array();

        if ( ! isset( $providers[ $provider_name ] ) ) {
            return new \WP_Error(
                'kivor_chat_agent_unknown_provider',
                sprintf(
                    /* translators: %s: Provider name */
                    __( 'Unknown AI provider: %s', 'kivor-chat-agent' ),
                    $provider_name
                )
            );
        }

        $config = $providers[ $provider_name ];

        if ( empty( $config['api_key'] ) ) {
            return new \WP_Error(
                'kivor_chat_agent_no_api_key',
                sprintf(
                    /* translators: %s: Provider name */
                    __( 'API key not configured for %s.', 'kivor-chat-agent' ),
                    ucfirst( $provider_name )
                )
            );
        }

        switch ( $provider_name ) {
            case 'openai':
                return new Kivor_AI_OpenAI( $config['api_key'], $config['model'] ?? 'gpt-4o-mini' );

            case 'gemini':
                return new Kivor_AI_Gemini( $config['api_key'], self::normalize_gemini_chat_model( (string) ( $config['model'] ?? '' ) ) );

            case 'openrouter':
                return new Kivor_AI_OpenRouter( $config['api_key'], $config['model'] ?? '' );

            default:
                return new \WP_Error(
                    'kivor_chat_agent_unknown_provider',
                    sprintf(
                        /* translators: %s: Provider name */
                        __( 'Unknown AI provider: %s', 'kivor-chat-agent' ),
                        $provider_name
                    )
                );
        }
    }

    /**
     * Normalize Gemini chat model IDs.
     *
     * @param string $model Raw model value.
     * @return string
     */
    private static function normalize_gemini_chat_model( string $model ): string {
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

        return in_array( $model, $allowed_models, true ) ? $model : 'gemini-2.5-flash';
    }

    /**
     * Create a provider instance for a specific provider (used by test connection).
     *
     * @param string $provider_name Provider key ('openai', 'gemini', 'openrouter').
     * @param string $api_key       API key.
     * @param string $model         Model identifier.
     * @return Kivor_AI_Provider|WP_Error
     */
    public static function create_for_test( string $provider_name, string $api_key, string $model ) {
        switch ( $provider_name ) {
            case 'openai':
                return new Kivor_AI_OpenAI( $api_key, $model );

            case 'gemini':
                return new Kivor_AI_Gemini( $api_key, self::normalize_gemini_chat_model( $model ) );

            case 'openrouter':
                return new Kivor_AI_OpenRouter( $api_key, $model );

            default:
                return new \WP_Error(
                    'kivor_chat_agent_unknown_provider',
                    sprintf(
                        /* translators: %s: Provider name */
                        __( 'Unknown AI provider: %s', 'kivor-chat-agent' ),
                        $provider_name
                    )
                );
        }
    }
}
