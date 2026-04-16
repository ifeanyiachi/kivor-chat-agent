<?php
/**
 * Embedding provider factory.
 *
 * @package KivorAgent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class Kivor_Embedding_Factory {

	/**
	 * Create an embedding provider instance from settings.
	 *
	 * @param array       $embedding_settings Embedding settings array.
	 * @param string|null $provider           Optional provider override.
	 * @return Kivor_Embedding_Provider|WP_Error
	 */
	public static function create( array $embedding_settings, ?string $provider = null ) {
		$providers = $embedding_settings['providers'] ?? array();

		$provider_name = $provider ?? ( $embedding_settings['active_provider'] ?? 'openai' );
		$provider_name = sanitize_key( (string) $provider_name );

		if ( ! isset( $providers[ $provider_name ] ) || ! is_array( $providers[ $provider_name ] ) ) {
			return new \WP_Error(
				'kivor_chat_agent_unknown_embedding_provider',
				sprintf(
					/* translators: %s: Provider name */
					__( 'Unknown embedding provider: %s', 'kivor-chat-agent' ),
					$provider_name
				)
			);
		}

		$config = $providers[ $provider_name ];

		if ( empty( $config['api_key'] ) ) {
			return new \WP_Error(
				'kivor_chat_agent_embedding_no_api_key',
				sprintf(
					/* translators: %s: Provider name */
					__( 'Embedding API key not configured for %s.', 'kivor-chat-agent' ),
					$provider_name
				)
			);
		}

		$model = sanitize_text_field( (string) ( $config['model'] ?? '' ) );

		switch ( $provider_name ) {
			case 'openai':
				return new Kivor_Embedding_OpenAI( (string) $config['api_key'], '' !== $model ? $model : 'text-embedding-3-small' );

			case 'gemini':
				return new Kivor_Embedding_Gemini( (string) $config['api_key'], '' !== $model ? $model : 'gemini-embedding-001' );

			case 'openrouter':
				return new Kivor_Embedding_OpenRouter( (string) $config['api_key'], '' !== $model ? $model : 'openai/text-embedding-3-small' );

			case 'cohere':
				return new Kivor_Embedding_Cohere( (string) $config['api_key'], '' !== $model ? $model : 'embed-english-v3.0' );

			case 'azure_openai':
				return new Kivor_Embedding_Azure_OpenAI(
					(string) $config['api_key'],
					'' !== $model ? $model : 'text-embedding-3-small',
					(string) ( $config['endpoint'] ?? '' ),
					(string) ( $config['deployment'] ?? '' ),
					(string) ( $config['api_version'] ?? '2023-05-15' )
				);

			default:
				return new \WP_Error(
					'kivor_chat_agent_unknown_embedding_provider',
					sprintf(
						/* translators: %s: Provider name */
						__( 'Unknown embedding provider: %s', 'kivor-chat-agent' ),
						$provider_name
					)
				);
		}
	}

	/**
	 * Create a provider instance for test-connection actions.
	 *
	 * @param string $provider_name Provider key.
	 * @param array  $config        Provider config.
	 * @return Kivor_Embedding_Provider|WP_Error
	 */
	public static function create_for_test( string $provider_name, array $config ) {
		$settings = array(
			'active_provider' => $provider_name,
			'providers'       => array(
				$provider_name => $config,
			),
		);

		return self::create( $settings, $provider_name );
	}

	/**
	 * Get supported embedding providers.
	 *
	 * @return array<string, string>
	 */
	public static function get_available_providers(): array {
		return array(
			'openai'       => 'OpenAI',
			'gemini'       => 'Google Gemini',
			'openrouter'   => 'OpenRouter',
			'cohere'       => 'Cohere',
			'azure_openai' => 'Azure OpenAI',
		);
	}
}
