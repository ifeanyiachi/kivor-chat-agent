<?php
/**
 * Azure OpenAI embedding provider.
 *
 * @package KivorAgent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class Kivor_Embedding_Azure_OpenAI extends Kivor_Embedding_Provider {

	/**
	 * Azure endpoint.
	 *
	 * @var string
	 */
	private string $endpoint;

	/**
	 * Azure deployment name.
	 *
	 * @var string
	 */
	private string $deployment;

	/**
	 * API version.
	 *
	 * @var string
	 */
	private string $api_version;

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
	 * Constructor.
	 *
	 * @param string $api_key     API key.
	 * @param string $model       Model name.
	 * @param string $endpoint    Azure endpoint URL.
	 * @param string $deployment  Deployment name.
	 * @param string $api_version API version.
	 */
	public function __construct( string $api_key, string $model, string $endpoint, string $deployment, string $api_version = '2023-05-15' ) {
		parent::__construct( $api_key, $model );
		$this->endpoint    = rtrim( $endpoint, '/' );
		$this->deployment  = $deployment;
		$this->api_version = $api_version;
	}

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
			__( 'Azure OpenAI response missing embedding data.', 'kivor-chat-agent' )
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

		if ( '' === $this->endpoint || '' === $this->deployment ) {
			return new \WP_Error(
				'kivor_chat_agent_embedding_invalid_config',
				__( 'Azure OpenAI endpoint and deployment are required.', 'kivor-chat-agent' )
			);
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
			$this->build_url(),
			array(
				'input' => $sanitized,
			),
			array(
				'api-key' => $this->api_key,
			),
			120
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( empty( $response['data'] ) || ! is_array( $response['data'] ) ) {
			return new \WP_Error(
				'kivor_chat_agent_embedding_invalid_response',
				__( 'Azure OpenAI batch embedding response missing data.', 'kivor-chat-agent' )
			);
		}

		$sorted = $response['data'];
		usort( $sorted, function ( $a, $b ) {
			return ( $a['index'] ?? 0 ) - ( $b['index'] ?? 0 );
		} );

		$vectors = array();
		foreach ( $sorted as $item ) {
			if ( empty( $item['embedding'] ) || ! is_array( $item['embedding'] ) ) {
				return new \WP_Error(
					'kivor_chat_agent_embedding_invalid_response',
					__( 'Azure OpenAI batch embedding contains invalid data.', 'kivor-chat-agent' )
				);
			}
			$vectors[] = array_map( 'floatval', $item['embedding'] );
		}

		return $vectors;
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
		return 'Azure OpenAI';
	}

	/**
	 * Build endpoint URL.
	 *
	 * @return string
	 */
	private function build_url(): string {
		return sprintf(
			'%s/openai/deployments/%s/embeddings?api-version=%s',
			$this->endpoint,
			rawurlencode( $this->deployment ),
			rawurlencode( $this->api_version )
		);
	}
}
