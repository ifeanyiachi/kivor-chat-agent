<?php
/**
 * Qdrant vector store.
 *
 * Stores and searches embeddings using the Qdrant REST API.
 *
 * @package KivorAgent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class Kivor_Qdrant_Store extends Kivor_Vector_Store {

	/**
	 * Qdrant endpoint URL.
	 *
	 * @var string
	 */
	private string $endpoint_url;

	/**
	 * Qdrant API key (optional for self-hosted).
	 *
	 * @var string
	 */
	private string $api_key;

	/**
	 * Collection name.
	 *
	 * @var string
	 */
	private string $collection_name;

	/**
	 * Constructor.
	 *
	 * @param string $endpoint_url    Qdrant REST API base URL.
	 * @param string $api_key         Qdrant API key (optional).
	 * @param string $collection_name Collection name.
	 */
	public function __construct( string $endpoint_url, string $api_key = '', string $collection_name = 'kivor_chat_agent_products' ) {
		$this->endpoint_url    = rtrim( $endpoint_url, '/' );
		$this->api_key         = $api_key;
		// URL-encode collection name to prevent path injection (VULN-014 fix).
		$this->collection_name = rawurlencode( $collection_name );
	}

	/**
	 * Make a Qdrant API request.
	 *
	 * @param string $endpoint API endpoint path.
	 * @param string $method   HTTP method.
	 * @param array  $body     Request body.
	 * @param int    $timeout  Timeout in seconds.
	 * @return array|WP_Error
	 */
	private function request( string $endpoint, string $method = 'GET', array $body = array(), int $timeout = 60 ) {
		$url = $this->endpoint_url . $endpoint;

		$headers = array(
			'Content-Type' => 'application/json',
		);
		if ( ! empty( $this->api_key ) ) {
			$headers['api-key'] = $this->api_key;
		}

		$args = array(
			'method'  => $method,
			'headers' => $headers,
			'timeout' => $timeout,
		);

		if ( ! empty( $body ) && in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body_raw    = wp_remote_retrieve_body( $response );
		$decoded     = json_decode( $body_raw, true );

		if ( $status_code < 200 || $status_code >= 300 ) {
			$error_msg = '';
			if ( is_array( $decoded ) ) {
				$error_msg = $decoded['status']['error'] ?? ( $decoded['message'] ?? '' );
			}
			if ( empty( $error_msg ) ) {
				$error_msg = mb_substr( $body_raw, 0, 200 );
			}

			return new \WP_Error(
				'kivor_chat_agent_qdrant_api_error',
				sprintf(
					/* translators: 1: HTTP status code, 2: Error message */
					__( 'Qdrant API error (%1$d): %2$s', 'kivor-chat-agent' ),
					$status_code,
					$error_msg
				),
				array( 'status' => $status_code )
			);
		}

		return $decoded ?: array();
	}

	/**
	 * Build a deterministic integer point ID from object type and ID.
	 *
	 * Qdrant requires integer or UUID point IDs. We use SHA-256 truncated
	 * to 53 bits (JS safe integer range) to avoid collisions. CRC32's 32-bit
	 * space has ~50% collision probability at ~77K items (birthday paradox).
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @return int
	 */
	private function build_point_id( string $object_type, int $object_id ): int {
		// Use SHA-256 for a much larger hash space, then truncate to 53 bits
		// (max safe integer in JSON/JS: 2^53 - 1 = 9007199254740991).
		$hash_hex = hash( 'sha256', $object_type . '_' . $object_id );
		// Take first 13 hex chars (52 bits) to stay within safe integer range.
		$truncated = substr( $hash_hex, 0, 13 );
		return intval( hexdec( $truncated ) );
	}

	/**
	 * Upsert a single embedding.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @param array  $embedding   Float array.
	 * @param array  $metadata    Metadata.
	 * @param string $content_hash Content hash.
	 * @return true|WP_Error
	 */
	public function upsert( string $object_type, int $object_id, array $embedding, array $metadata = array(), string $content_hash = '' ) {
		// Validate embedding array contents (VULN-013 fix).
		$valid = $this->validate_embedding( $embedding );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$point_id = $this->build_point_id( $object_type, $object_id );

		$payload = array_merge( $metadata, array(
			'_object_type'  => $object_type,
			'_object_id'    => $object_id,
			'_content_hash' => $content_hash,
		) );

		$result = $this->request(
			"/collections/{$this->collection_name}/points",
			'PUT',
			array(
				'points' => array(
					array(
						'id'      => $point_id,
						'vector'  => $embedding,
						'payload' => $payload,
					),
				),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	/**
	 * Upsert multiple embeddings in a batch.
	 *
	 * @param array $items Array of embedding items.
	 * @return true|WP_Error
	 */
	public function upsert_batch( array $items ) {
		if ( empty( $items ) ) {
			return true;
		}

		$points = array();
		foreach ( $items as $item ) {
			$point_id = $this->build_point_id( $item['object_type'], $item['object_id'] );
			$payload  = array_merge( $item['metadata'] ?? array(), array(
				'_object_type'  => $item['object_type'],
				'_object_id'    => $item['object_id'],
				'_content_hash' => $item['content_hash'] ?? '',
			) );

			$points[] = array(
				'id'      => $point_id,
				'vector'  => $item['embedding'],
				'payload' => $payload,
			);
		}

		// Qdrant handles large batches well, but chunk at 100 for safety.
		$chunks = array_chunk( $points, 100 );

		foreach ( $chunks as $chunk ) {
			$result = $this->request(
				"/collections/{$this->collection_name}/points",
				'PUT',
				array( 'points' => $chunk ),
				120
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * Search for similar vectors.
	 *
	 * @param array  $query_embedding Float array.
	 * @param int    $limit           Max results.
	 * @param string $object_type     Filter by type.
	 * @param array  $filters         Additional Qdrant filter conditions.
	 * @return array|WP_Error
	 */
	public function search( array $query_embedding, int $limit = 10, string $object_type = '', array $filters = array() ) {
		$body = array(
			'vector'       => $query_embedding,
			'limit'        => $limit,
			'with_payload' => true,
		);

		// Build filter.
		$must = array();
		if ( ! empty( $object_type ) ) {
			$must[] = array(
				'key'   => '_object_type',
				'match' => array( 'value' => $object_type ),
			);
		}
		if ( ! empty( $filters ) ) {
			foreach ( $filters as $key => $value ) {
				$must[] = array(
					'key'   => $key,
					'match' => array( 'value' => $value ),
				);
			}
		}
		if ( ! empty( $must ) ) {
			$body['filter'] = array( 'must' => $must );
		}

		$result = $this->request(
			"/collections/{$this->collection_name}/points/search",
			'POST',
			$body
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$matches = array();
		$points  = $result['result'] ?? array();

		foreach ( $points as $point ) {
			$payload = $point['payload'] ?? array();
			$matches[] = array(
				'object_id'   => (int) ( $payload['_object_id'] ?? 0 ),
				'object_type' => $payload['_object_type'] ?? '',
				'score'       => (float) ( $point['score'] ?? 0 ),
				'metadata'    => $payload,
			);
		}

		return $matches;
	}

	/**
	 * Delete an embedding.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @return true|WP_Error
	 */
	public function delete( string $object_type, int $object_id ) {
		$point_id = $this->build_point_id( $object_type, $object_id );

		$result = $this->request(
			"/collections/{$this->collection_name}/points/delete",
			'POST',
			array(
				'points' => array( $point_id ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	/**
	 * Delete all embeddings.
	 *
	 * @param string $object_type Filter by type. Empty = delete all points.
	 * @return true|WP_Error
	 */
	public function delete_all( string $object_type = '' ) {
		if ( ! empty( $object_type ) ) {
			$result = $this->request(
				"/collections/{$this->collection_name}/points/delete",
				'POST',
				array(
					'filter' => array(
						'must' => array(
							array(
								'key'   => '_object_type',
								'match' => array( 'value' => $object_type ),
							),
						),
					),
				)
			);
		} else {
			// Delete the collection and recreate it would be cleaner,
			// but for simplicity we filter-delete all kivor points.
			$result = $this->request(
				"/collections/{$this->collection_name}/points/delete",
				'POST',
				array(
					'filter' => array(
						'must' => array(
							array(
								'key'        => '_object_type',
								'match'      => array( 'any' => array( 'product', 'kb_article' ) ),
							),
						),
					),
				)
			);
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	/**
	 * Get the content hash for a stored embedding.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @return string|null
	 */
	public function get_content_hash( string $object_type, int $object_id ): ?string {
		$point_id = $this->build_point_id( $object_type, $object_id );

		$result = $this->request(
			"/collections/{$this->collection_name}/points/{$point_id}",
			'GET'
		);

		if ( is_wp_error( $result ) ) {
			return null;
		}

		$point = $result['result'] ?? array();
		return $point['payload']['_content_hash'] ?? null;
	}

	/**
	 * Get all stored object IDs for a given type.
	 *
	 * Uses scroll API to iterate through all points with the given type.
	 *
	 * @param string $object_type Object type.
	 * @return array
	 */
	public function get_stored_ids( string $object_type ): array {
		$ids    = array();
		$offset = null;

		do {
			$body = array(
				'filter' => array(
					'must' => array(
						array(
							'key'   => '_object_type',
							'match' => array( 'value' => $object_type ),
						),
					),
				),
				'limit'        => 100,
				'with_payload' => array( '_object_id' ),
				'with_vector'  => false,
			);

			if ( null !== $offset ) {
				$body['offset'] = $offset;
			}

			$result = $this->request(
				"/collections/{$this->collection_name}/points/scroll",
				'POST',
				$body
			);

			if ( is_wp_error( $result ) ) {
				break;
			}

			$points = $result['result']['points'] ?? array();
			foreach ( $points as $point ) {
				$obj_id = $point['payload']['_object_id'] ?? null;
				if ( null !== $obj_id ) {
					$ids[] = (int) $obj_id;
				}
			}

			$offset = $result['result']['next_page_offset'] ?? null;

		} while ( null !== $offset );

		return $ids;
	}

	/**
	 * Get the total count of stored embeddings.
	 *
	 * @param string $object_type Filter by type.
	 * @return int
	 */
	public function count( string $object_type = '' ): int {
		if ( ! empty( $object_type ) ) {
			$result = $this->request(
				"/collections/{$this->collection_name}/points/count",
				'POST',
				array(
					'filter' => array(
						'must' => array(
							array(
								'key'   => '_object_type',
								'match' => array( 'value' => $object_type ),
							),
						),
					),
					'exact' => true,
				)
			);

			if ( is_wp_error( $result ) ) {
				return 0;
			}

			return (int) ( $result['result']['count'] ?? 0 );
		}

		// Collection-level count.
		$result = $this->request(
			"/collections/{$this->collection_name}",
			'GET'
		);

		if ( is_wp_error( $result ) ) {
			return 0;
		}

		return (int) ( $result['result']['points_count'] ?? 0 );
	}

	/**
	 * Get all stored vectors with metadata.
	 *
	 * @param string $object_type Object type filter.
	 * @param int    $limit       Max rows.
	 * @param int    $offset      Offset.
	 * @return array|WP_Error
	 */
	public function get_all_vectors( string $object_type = '', int $limit = 100, int $offset = 0 ) {
		$limit  = max( 1, $limit );
		$offset = max( 0, $offset );

		$body = array(
			'limit'        => $limit,
			'offset'       => 0,
			'with_payload' => true,
			'with_vector'  => false,
		);

		if ( '' !== $object_type ) {
			$body['filter'] = array(
				'must' => array(
					array(
						'key'   => '_object_type',
						'match' => array( 'value' => $object_type ),
					),
				),
			);
		}

		$result = $this->request(
			"/collections/{$this->collection_name}/points/scroll",
			'POST',
			$body
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$points = $result['result']['points'] ?? array();
		if ( $offset > 0 ) {
			$points = array_slice( $points, $offset );
		}

		return array_map(
			static function ( array $point ): array {
				$payload = $point['payload'] ?? array();
				return array(
					'object_id'    => (int) ( $payload['_object_id'] ?? 0 ),
					'object_type'  => (string) ( $payload['_object_type'] ?? '' ),
					'content_hash' => (string) ( $payload['_content_hash'] ?? '' ),
					'metadata'     => $payload,
					'updated_at'   => (string) ( $payload['updated_at'] ?? '' ),
				);
			},
			$points
		);
	}

	/**
	 * Test the Qdrant connection.
	 *
	 * @return true|WP_Error
	 */
	public function test_connection() {
		if ( empty( $this->endpoint_url ) ) {
			return new \WP_Error(
				'kivor_chat_agent_qdrant_no_endpoint',
				__( 'Qdrant endpoint URL is not configured.', 'kivor-chat-agent' )
			);
		}

		// Try to get collection info.
		$result = $this->request(
			"/collections/{$this->collection_name}",
			'GET'
		);

		if ( is_wp_error( $result ) ) {
			$error_data = $result->get_error_data();

			// 404 = collection doesn't exist yet, which is fine.
			if ( isset( $error_data['status'] ) && 404 === $error_data['status'] ) {
				return true;
			}

			return $result;
		}

		return true;
	}

	/**
	 * Ensure the collection exists with the correct vector size.
	 *
	 * Called before first sync to create the collection if needed.
	 *
	 * @param int $dimensions Vector dimensions.
	 * @return true|WP_Error
	 */
	public function ensure_collection( int $dimensions ) {
		// Check if collection exists.
		$result = $this->request(
			"/collections/{$this->collection_name}",
			'GET'
		);

		if ( ! is_wp_error( $result ) ) {
			// Collection exists.
			return true;
		}

		$error_data = $result->get_error_data();
		if ( ! isset( $error_data['status'] ) || 404 !== $error_data['status'] ) {
			return $result;
		}

		// Create the collection.
		$create_result = $this->request(
			"/collections/{$this->collection_name}",
			'PUT',
			array(
				'vectors' => array(
					'size'     => $dimensions,
					'distance' => 'Cosine',
				),
			)
		);

		if ( is_wp_error( $create_result ) ) {
			return $create_result;
		}

		// Create payload index for _object_type for efficient filtering.
		$this->request(
			"/collections/{$this->collection_name}/index",
			'PUT',
			array(
				'field_name' => '_object_type',
				'field_schema' => 'keyword',
			)
		);

		return true;
	}
}
