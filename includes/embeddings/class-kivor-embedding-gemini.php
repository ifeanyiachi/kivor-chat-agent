<?php
/**
 * Google Gemini embedding provider.
 *
 * @package KivorAgent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class Kivor_Embedding_Gemini extends Kivor_Embedding_Provider {

	/**
	 * API base URL.
	 *
	 * @var string
	 */
	private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models';

	/**
	 * Known model dimensions.
	 *
	 * @var array<string, int>
	 */
	private const MODEL_DIMENSIONS = array(
		'gemini-embedding-001'    => 768,
		'gemini-embedding-001-v1' => 768,
	);

	/**
	 * Generate one embedding.
	 *
	 * @param string $text Text to embed.
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
			$this->build_url(),
			array(
				'content' => array(
					'parts' => array(
						array(
							'text' => $text,
						),
					),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$values = $response['embedding']['values'] ?? null;
		if ( ! is_array( $values ) || empty( $values ) ) {
			return new \WP_Error(
				'kivor_chat_agent_embedding_invalid_response',
				__( 'Gemini embedding response missing values.', 'kivor-chat-agent' )
			);
		}

		return array_map( 'floatval', $values );
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

		$embeddings = array();
		foreach ( $texts as $text ) {
			$vector = $this->generate_embedding( (string) $text );
			if ( is_wp_error( $vector ) ) {
				return $vector;
			}
			$embeddings[] = $vector;
		}

		return $embeddings;
	}

	/**
	 * Get dimensions.
	 *
	 * @return int
	 */
	public function get_dimensions(): int {
		return self::MODEL_DIMENSIONS[ $this->model ] ?? 768;
	}

	/**
	 * Provider name.
	 *
	 * @return string
	 */
	public function get_provider_name(): string {
		return 'Gemini';
	}

	/**
	 * Build endpoint URL.
	 *
	 * @return string
	 */
	private function build_url(): string {
		return sprintf(
			'%s/%s:embedContent?key=%s',
			self::API_BASE,
			rawurlencode( $this->model ),
			rawurlencode( $this->api_key )
		);
	}
}
