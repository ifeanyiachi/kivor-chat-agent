<?php
/**
 * OpenAI embedding provider.
 *
 * Generates embedding vectors using the OpenAI Embeddings API.
 * Default model: text-embedding-3-small (1536 dimensions).
 *
 * @package KivorAgent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class Kivor_Embedding_OpenAI extends Kivor_Embedding_Provider {

	/**
	 * API base URL.
	 *
	 * @var string
	 */
	private const API_URL = 'https://api.openai.com/v1/embeddings';

	/**
	 * Known model dimensions.
	 *
	 * @var array<string, int>
	 */
	private const MODEL_DIMENSIONS = array(
		'text-embedding-3-small' => 1536,
		'text-embedding-3-large' => 3072,
		'text-embedding-ada-002' => 1536,
	);

	/**
	 * Maximum texts per batch request.
	 *
	 * OpenAI allows up to 2048 inputs per request, but we limit to avoid
	 * timeouts and keep payloads reasonable.
	 *
	 * @var int
	 */
	private const MAX_BATCH_SIZE = 100;

	/**
	 * Generate an embedding vector for a single text.
	 *
	 * @param string $text Text to embed.
	 * @return array|WP_Error Float array of the embedding vector, or WP_Error.
	 */
	public function generate_embedding( string $text ) {
		$valid = $this->validate_config();
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$text = $this->sanitize_input( $text );
		if ( empty( $text ) ) {
			return new \WP_Error(
				'kivor_chat_agent_embedding_empty_input',
				__( 'Cannot generate embedding for empty text.', 'kivor-chat-agent' )
			);
		}

		$response = $this->make_request(
			self::API_URL,
			array(
				'input' => $text,
				'model' => $this->model,
			),
			array(
				'Authorization' => 'Bearer ' . $this->api_key,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( empty( $response['data'][0]['embedding'] ) ) {
			return new \WP_Error(
				'kivor_chat_agent_embedding_invalid_response',
				__( 'OpenAI embedding response missing embedding data.', 'kivor-chat-agent' )
			);
		}

		return $response['data'][0]['embedding'];
	}

	/**
	 * Generate embedding vectors for a batch of texts.
	 *
	 * Splits into sub-batches of MAX_BATCH_SIZE if needed.
	 *
	 * @param array $texts Array of strings to embed.
	 * @return array|WP_Error Array of float arrays (one per input text), or WP_Error.
	 */
	public function generate_embeddings_batch( array $texts ) {
		$valid = $this->validate_config();
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		// Sanitize and validate inputs.
		$sanitized = array();
		foreach ( $texts as $text ) {
			$clean = $this->sanitize_input( $text );
			if ( empty( $clean ) ) {
				return new \WP_Error(
					'kivor_chat_agent_embedding_empty_input',
					__( 'Cannot generate embedding for empty text in batch.', 'kivor-chat-agent' )
				);
			}
			$sanitized[] = $clean;
		}

		if ( empty( $sanitized ) ) {
			return array();
		}

		$all_embeddings = array();
		$chunks         = array_chunk( $sanitized, self::MAX_BATCH_SIZE );

		foreach ( $chunks as $chunk ) {
			$response = $this->make_request(
				self::API_URL,
				array(
					'input' => $chunk,
					'model' => $this->model,
				),
				array(
					'Authorization' => 'Bearer ' . $this->api_key,
				),
				120 // Longer timeout for batch requests.
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			if ( empty( $response['data'] ) || ! is_array( $response['data'] ) ) {
				return new \WP_Error(
					'kivor_chat_agent_embedding_invalid_response',
					__( 'OpenAI batch embedding response missing data.', 'kivor-chat-agent' )
				);
			}

			// OpenAI returns embeddings in order of input, but sort by index to be safe.
			$sorted = $response['data'];
			usort( $sorted, function ( $a, $b ) {
				return ( $a['index'] ?? 0 ) - ( $b['index'] ?? 0 );
			} );

			foreach ( $sorted as $item ) {
				if ( empty( $item['embedding'] ) ) {
					return new \WP_Error(
						'kivor_chat_agent_embedding_invalid_response',
						__( 'OpenAI batch embedding response contains invalid embedding data.', 'kivor-chat-agent' )
					);
				}
				$all_embeddings[] = $item['embedding'];
			}
		}

		return $all_embeddings;
	}

	/**
	 * Get the embedding dimension size for the current model.
	 *
	 * @return int
	 */
	public function get_dimensions(): int {
		return self::MODEL_DIMENSIONS[ $this->model ] ?? 1536;
	}

	/**
	 * Get the provider name.
	 *
	 * @return string
	 */
	public function get_provider_name(): string {
		return 'OpenAI';
	}

	/**
	 * Sanitize input text for embedding.
	 *
	 * Trims whitespace, removes null bytes, and truncates to 8191 tokens
	 * (approximately 32000 characters for safety).
	 *
	 * @param string $text Raw input text.
	 * @return string Cleaned text.
	 */
	private function sanitize_input( string $text ): string {
		$text = trim( $text );
		$text = str_replace( "\0", '', $text );

		// Rough token limit: 8191 tokens ≈ 32000 chars for English text.
		if ( mb_strlen( $text ) > 32000 ) {
			$text = mb_substr( $text, 0, 32000 );
		}

		return $text;
	}
}
