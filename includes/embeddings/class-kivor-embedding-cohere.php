<?php
/**
 * Cohere embedding provider.
 *
 * @package KivorAgent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class Kivor_Embedding_Cohere extends Kivor_Embedding_Provider {

	/**
	 * API URL.
	 *
	 * @var string
	 */
	private const API_URL = 'https://api.cohere.ai/v1/embed';

	/**
	 * Known model dimensions.
	 *
	 * @var array<string, int>
	 */
	private const MODEL_DIMENSIONS = array(
		'embed-english-v3.0'            => 1024,
		'embed-english-light-v3.0'      => 384,
		'embed-multilingual-v3.0'       => 1024,
		'embed-multilingual-light-v3.0' => 384,
	);

	/**
	 * Generate one embedding.
	 *
	 * @param string $text Text.
	 * @return array|WP_Error
	 */
	public function generate_embedding( string $text ) {
		$result = $this->generate_embeddings_batch( array( $text ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $result[0] ?? new \WP_Error(
			'kivor_chat_agent_embedding_invalid_response',
			__( 'Cohere embedding response missing embedding data.', 'kivor-chat-agent' )
		);
	}

	/**
	 * Generate embeddings for a batch.
	 *
	 * @param array $texts Text array.
	 * @return array|WP_Error
	 */
	public function generate_embeddings_batch( array $texts ) {
		$valid = $this->validate_config();
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		if ( empty( $texts ) ) {
			return array();
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
				'texts'      => $sanitized,
				'model'      => $this->model,
				'input_type' => 'search_document',
			),
			array(
				'Authorization' => 'Bearer ' . $this->api_key,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$embeddings = $response['embeddings'] ?? null;
		if ( ! is_array( $embeddings ) || empty( $embeddings ) ) {
			return new \WP_Error(
				'kivor_chat_agent_embedding_invalid_response',
				__( 'Cohere batch embedding response missing embeddings.', 'kivor-chat-agent' )
			);
		}

		return array_map(
			static function ( $vector ) {
				return is_array( $vector ) ? array_map( 'floatval', $vector ) : array();
			},
			$embeddings
		);
	}

	/**
	 * Get dimensions.
	 *
	 * @return int
	 */
	public function get_dimensions(): int {
		return self::MODEL_DIMENSIONS[ $this->model ] ?? 1024;
	}

	/**
	 * Provider name.
	 *
	 * @return string
	 */
	public function get_provider_name(): string {
		return 'Cohere';
	}
}
