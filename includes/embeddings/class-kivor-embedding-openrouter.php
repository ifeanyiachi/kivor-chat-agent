<?php
/**
 * OpenRouter embedding provider.
 *
 * @package KivorAgent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class Kivor_Embedding_OpenRouter extends Kivor_Embedding_Provider {

	/**
	 * API URL.
	 *
	 * @var string
	 */
	private const API_URL = 'https://openrouter.ai/api/v1/embeddings';

	/**
	 * Known model dimensions.
	 *
	 * @var array<string, int>
	 */
	private const MODEL_DIMENSIONS = array(
		'openai/text-embedding-3-small'              => 1536,
		'openai/text-embedding-3-large'              => 3072,
		'cohere/cohere-embed-english-v3.0'           => 1024,
		'cohere/cohere-embed-multilingual-v3.0'      => 1024,
	);

	/**
	 * Generate one embedding.
	 *
	 * @param string $text Text.
	 * @return array|WP_Error
	 */
	public function generate_embedding( string $text ) {
		$valid = $this->validate_config();
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$text = trim( $text );
		if ( '' === $text ) {
			return new \WP_Error(
				'kivor_chat_agent_embedding_empty_input',
				__( 'Cannot generate embedding for empty text.', 'kivor-chat-agent' )
			);
		}

		$response = $this->make_request(
			self::API_URL,
			array(
				'model' => $this->model,
				'input' => $text,
			),
			array(
				'Authorization' => 'Bearer ' . $this->api_key,
				'HTTP-Referer'  => home_url(),
				'X-Title'       => get_bloginfo( 'name' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( empty( $response['data'][0]['embedding'] ) || ! is_array( $response['data'][0]['embedding'] ) ) {
			return new \WP_Error(
				'kivor_chat_agent_embedding_invalid_response',
				__( 'OpenRouter embedding response missing embedding data.', 'kivor-chat-agent' )
			);
		}

		return array_map( 'floatval', $response['data'][0]['embedding'] );
	}

	/**
	 * Generate embeddings for a batch.
	 *
	 * @param array $texts Text array.
	 * @return array|WP_Error
	 */
	public function generate_embeddings_batch( array $texts ) {
		if ( empty( $texts ) ) {
			return array();
		}

		$valid = $this->validate_config();
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$sanitized = array();
		foreach ( $texts as $text ) {
			$text = trim( (string) $text );
			if ( '' === $text ) {
				return new \WP_Error(
					'kivor_chat_agent_embedding_empty_input',
					__( 'Cannot generate embedding for empty text in batch.', 'kivor-chat-agent' )
				);
			}
			$sanitized[] = $text;
		}

		$response = $this->make_request(
			self::API_URL,
			array(
				'model' => $this->model,
				'input' => $sanitized,
			),
			array(
				'Authorization' => 'Bearer ' . $this->api_key,
				'HTTP-Referer'  => home_url(),
				'X-Title'       => get_bloginfo( 'name' ),
			),
			120
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( empty( $response['data'] ) || ! is_array( $response['data'] ) ) {
			return new \WP_Error(
				'kivor_chat_agent_embedding_invalid_response',
				__( 'OpenRouter batch embedding response missing data.', 'kivor-chat-agent' )
			);
		}

		$sorted = $response['data'];
		usort( $sorted, function ( $a, $b ) {
			return ( $a['index'] ?? 0 ) - ( $b['index'] ?? 0 );
		} );

		$all_embeddings = array();
		foreach ( $sorted as $item ) {
			if ( empty( $item['embedding'] ) || ! is_array( $item['embedding'] ) ) {
				return new \WP_Error(
					'kivor_chat_agent_embedding_invalid_response',
					__( 'OpenRouter batch embedding response contains invalid data.', 'kivor-chat-agent' )
				);
			}
			$all_embeddings[] = array_map( 'floatval', $item['embedding'] );
		}

		return $all_embeddings;
	}

	/**
	 * Get dimensions.
	 *
	 * @return int
	 */
	public function get_dimensions(): int {
		return self::MODEL_DIMENSIONS[ $this->model ] ?? 1536;
	}

	/**
	 * Provider name.
	 *
	 * @return string
	 */
	public function get_provider_name(): string {
		return 'OpenRouter';
	}
}
