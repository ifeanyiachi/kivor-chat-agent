<?php
/**
 * Abstract embedding provider.
 *
 * Base class for all embedding providers (OpenAI, etc.).
 * Follows the same pattern as Kivor_AI_Provider but for embeddings.
 *
 * @package KivorAgent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

abstract class Kivor_Embedding_Provider {

	/**
	 * API key.
	 *
	 * @var string
	 */
	protected string $api_key;

	/**
	 * Model identifier.
	 *
	 * @var string
	 */
	protected string $model;

	/**
	 * Constructor.
	 *
	 * @param string $api_key API key for the provider.
	 * @param string $model   Model identifier.
	 */
	public function __construct( string $api_key, string $model ) {
		$this->api_key = $api_key;
		$this->model   = $model;
	}

	/**
	 * Generate an embedding vector for a single text.
	 *
	 * @param string $text Text to embed.
	 * @return array|WP_Error Float array of the embedding vector, or WP_Error.
	 */
	abstract public function generate_embedding( string $text );

	/**
	 * Generate embedding vectors for a batch of texts.
	 *
	 * @param array $texts Array of strings to embed.
	 * @return array|WP_Error Array of float arrays (one per input text), or WP_Error.
	 */
	abstract public function generate_embeddings_batch( array $texts );

	/**
	 * Get the embedding dimension size for the current model.
	 *
	 * @return int
	 */
	abstract public function get_dimensions(): int;

	/**
	 * Get the provider name.
	 *
	 * @return string
	 */
	abstract public function get_provider_name(): string;

	/**
	 * Test the embedding API connection.
	 *
	 * Sends a minimal embedding request to verify credentials.
	 *
	 * @return array|WP_Error Array with 'success' => true on success, or WP_Error.
	 */
	public function test_connection() {
		$result = $this->generate_embedding( 'test' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success'    => true,
			'provider'   => $this->get_provider_name(),
			'model'      => $this->model,
			'dimensions' => count( $result ),
		);
	}

	/**
	 * Validate that API key and model are configured.
	 *
	 * @return true|WP_Error
	 */
	protected function validate_config() {
		if ( empty( $this->api_key ) ) {
			return new \WP_Error(
				'kivor_chat_agent_embedding_no_api_key',
				sprintf(
					/* translators: %s: Provider name */
					__( '%s embedding API key is not configured.', 'kivor-chat-agent' ),
					$this->get_provider_name()
				)
			);
		}

		if ( empty( $this->model ) ) {
			return new \WP_Error(
				'kivor_chat_agent_embedding_no_model',
				sprintf(
					/* translators: %s: Provider name */
					__( '%s embedding model is not configured.', 'kivor-chat-agent' ),
					$this->get_provider_name()
				)
			);
		}

		return true;
	}

	/**
	 * Make an HTTP request to the embedding API.
	 *
	 * @param string $url     API endpoint URL.
	 * @param array  $body    Request body (will be JSON-encoded).
	 * @param array  $headers Additional headers.
	 * @param int    $timeout Request timeout in seconds.
	 * @return array|WP_Error Decoded response body or WP_Error.
	 */
	protected function make_request( string $url, array $body, array $headers = array(), int $timeout = 60 ) {
		$default_headers = array(
			'Content-Type' => 'application/json',
		);

		$response = wp_remote_post( $url, array(
			'headers' => array_merge( $default_headers, $headers ),
			'body'    => wp_json_encode( $body ),
			'timeout' => $timeout,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body_raw    = wp_remote_retrieve_body( $response );
		$decoded     = json_decode( $body_raw, true );

		if ( $status_code < 200 || $status_code >= 300 ) {
			$error_message = $this->extract_error_message( $decoded, $body_raw );
			return new \WP_Error(
				'kivor_chat_agent_embedding_api_error',
				sprintf(
					/* translators: 1: Provider name, 2: HTTP status code, 3: Error message */
					__( '%1$s embedding API error (%2$d): %3$s', 'kivor-chat-agent' ),
					$this->get_provider_name(),
					$status_code,
					$error_message
				),
				array( 'status' => $status_code )
			);
		}

		if ( null === $decoded ) {
			return new \WP_Error(
				'kivor_chat_agent_embedding_parse_error',
				__( 'Failed to parse embedding API response.', 'kivor-chat-agent' )
			);
		}

		return $decoded;
	}

	/**
	 * Extract a human-readable error message from an API error response.
	 *
	 * @param array|null $decoded  Decoded JSON response.
	 * @param string     $raw_body Raw response body.
	 * @return string
	 */
	protected function extract_error_message( ?array $decoded, string $raw_body ): string {
		if ( is_array( $decoded ) ) {
			if ( ! empty( $decoded['error']['message'] ) ) {
				return $decoded['error']['message'];
			}
			if ( ! empty( $decoded['message'] ) ) {
				return $decoded['message'];
			}
		}

		return mb_substr( $raw_body, 0, 200 );
	}
}
