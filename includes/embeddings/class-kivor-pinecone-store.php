<?php
/**
 * Pinecone vector store.
 *
 * Stores and searches embeddings using the Pinecone REST API.
 *
 * @package KivorAgent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class Kivor_Pinecone_Store extends Kivor_Vector_Store {

	/**
	 * Pinecone API key.
	 *
	 * @var string
	 */
	private string $api_key;

	/**
	 * Pinecone index name.
	 *
	 * @var string
	 */
	private string $index_name;

	/**
	 * Pinecone environment / host.
	 *
	 * This is the full index host URL, e.g. "https://my-index-abc123.svc.environment.pinecone.io"
	 *
	 * @var string
	 */
	private string $environment;

	/**
	 * Namespace for Kivor vectors.
	 *
	 * @var string
	 */
	private const NAMESPACE = 'kivor';

	/**
	 * Constructor.
	 *
	 * @param string $api_key     Pinecone API key.
	 * @param string $index_name  Pinecone index name.
	 * @param string $environment Pinecone environment / index host URL.
	 */
	public function __construct( string $api_key, string $index_name, string $environment ) {
		$this->api_key     = $api_key;
		$this->index_name  = $index_name;
		$this->environment = rtrim( $environment, '/' );
	}

	/**
	 * Get the index base URL.
	 *
	 * Accepts either a full host URL, a bare host, or a legacy environment value.
	 * For legacy values, we resolve the host via the Pinecone control plane.
	 *
	 * @return string|WP_Error
	 */
	private function get_base_url() {
		$environment = trim( $this->environment );

		if ( '' === $environment ) {
			return new \WP_Error(
				'kivor_chat_agent_pinecone_missing_environment',
				__( 'Pinecone host/environment is not configured.', 'kivor-chat-agent' )
			);
		}

		if ( 0 === strpos( $environment, 'https://' ) ) {
			return rtrim( $environment, '/' );
		}

		if ( false !== strpos( $environment, '.pinecone.io' ) ) {
			return 'https://' . rtrim( $environment, '/' );
		}

		$resolved_host = $this->resolve_index_host();
		if ( is_wp_error( $resolved_host ) ) {
			return $resolved_host;
		}

		return 'https://' . rtrim( (string) $resolved_host, '/' );
	}

	/**
	 * Resolve index host from Pinecone control plane.
	 *
	 * @return string|WP_Error
	 */
	private function resolve_index_host() {
		if ( '' === trim( $this->api_key ) ) {
			return new \WP_Error(
				'kivor_chat_agent_pinecone_no_key',
				__( 'Pinecone API key is not configured.', 'kivor-chat-agent' )
			);
		}

		if ( '' === trim( $this->index_name ) ) {
			return new \WP_Error(
				'kivor_chat_agent_pinecone_missing_index_name',
				__( 'Pinecone index name is not configured.', 'kivor-chat-agent' )
			);
		}

		$url = 'https://api.pinecone.io/indexes/' . rawurlencode( $this->index_name );

		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'Api-Key'                => $this->api_key,
					'Accept'                 => 'application/json',
					'X-Pinecone-API-Version' => '2024-07',
				),
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'kivor_chat_agent_pinecone_host_resolution_failed',
				sprintf(
					/* translators: %s: underlying error message */
					__( 'Could not resolve Pinecone index host: %s', 'kivor-chat-agent' ),
					$response->get_error_message()
				)
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body_raw    = wp_remote_retrieve_body( $response );
		$decoded     = json_decode( $body_raw, true );

		if ( $status_code < 200 || $status_code >= 300 ) {
			$error_message = '';
			if ( is_array( $decoded ) ) {
				$error_message = (string) ( $decoded['message'] ?? '' );
			}
			if ( '' === $error_message ) {
				$error_message = mb_substr( (string) $body_raw, 0, 200 );
			}

			return new \WP_Error(
				'kivor_chat_agent_pinecone_host_resolution_failed',
				sprintf(
					/* translators: 1: HTTP status code, 2: error message */
					__( 'Could not resolve Pinecone index host (%1$d): %2$s', 'kivor-chat-agent' ),
					(int) $status_code,
					$error_message
				)
			);
		}

		$host = is_array( $decoded ) ? (string) ( $decoded['host'] ?? '' ) : '';
		if ( '' === trim( $host ) ) {
			return new \WP_Error(
				'kivor_chat_agent_pinecone_missing_host',
				__( 'Pinecone index host was not returned by the API.', 'kivor-chat-agent' )
			);
		}

		return $host;
	}

	/**
	 * Make a Pinecone API request.
	 *
	 * @param string $endpoint API endpoint path.
	 * @param string $method   HTTP method.
	 * @param array  $body     Request body (POST/PUT only).
	 * @param int    $timeout  Timeout in seconds.
	 * @return array|WP_Error Decoded response or WP_Error.
	 */
	private function request( string $endpoint, string $method = 'POST', array $body = array(), int $timeout = 60 ) {
		$base_url = $this->get_base_url();
		if ( is_wp_error( $base_url ) ) {
			return $base_url;
		}

		$url = $base_url . $endpoint;

		$args = array(
			'method'  => $method,
			'headers' => array(
				'Content-Type' => 'application/json',
				'Api-Key'      => $this->api_key,
			),
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
			if ( is_array( $decoded ) && ! empty( $decoded['message'] ) ) {
				$error_msg = $decoded['message'];
			} elseif ( is_array( $decoded ) && ! empty( $decoded['error'] ) ) {
				$error_msg = is_array( $decoded['error'] ) ? ( $decoded['error']['message'] ?? '' ) : $decoded['error'];
			} else {
				$error_msg = mb_substr( $body_raw, 0, 200 );
			}

			return new \WP_Error(
				'kivor_chat_agent_pinecone_api_error',
				sprintf(
					/* translators: 1: HTTP status code, 2: Error message */
					__( 'Pinecone API error (%1$d): %2$s', 'kivor-chat-agent' ),
					$status_code,
					$error_msg
				),
				array( 'status' => $status_code )
			);
		}

		return $decoded ?: array();
	}

	/**
	 * Build a Pinecone vector ID from object type and ID.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @return string
	 */
	private function build_vector_id( string $object_type, int $object_id ): string {
		return "{$object_type}_{$object_id}";
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

		$vector_id = $this->build_vector_id( $object_type, $object_id );

		// Include object type, ID, and content hash in metadata for retrieval.
		$meta = array_merge( $metadata, array(
			'_object_type' => $object_type,
			'_object_id'   => $object_id,
			'_content_hash' => $content_hash,
		) );

		$result = $this->request( '/vectors/upsert', 'POST', array(
			'vectors'   => array(
				array(
					'id'       => $vector_id,
					'values'   => $embedding,
					'metadata' => $meta,
				),
			),
			'namespace' => self::NAMESPACE,
		) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	/**
	 * Upsert multiple embeddings in a batch.
	 *
	 * Pinecone supports batch upserts natively (up to ~100 vectors).
	 *
	 * @param array $items Array of embedding items.
	 * @return true|WP_Error
	 */
	public function upsert_batch( array $items ) {
		if ( empty( $items ) ) {
			return true;
		}

		$vectors = array();
		foreach ( $items as $item ) {
			$vector_id = $this->build_vector_id( $item['object_type'], $item['object_id'] );
			$meta      = array_merge( $item['metadata'] ?? array(), array(
				'_object_type'  => $item['object_type'],
				'_object_id'    => $item['object_id'],
				'_content_hash' => $item['content_hash'] ?? '',
			) );

			$vectors[] = array(
				'id'       => $vector_id,
				'values'   => $item['embedding'],
				'metadata' => $meta,
			);
		}

		// Pinecone recommends batches of 100.
		$chunks = array_chunk( $vectors, 100 );

		foreach ( $chunks as $chunk ) {
			$result = $this->request( '/vectors/upsert', 'POST', array(
				'vectors'   => $chunk,
				'namespace' => self::NAMESPACE,
			), 120 );

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
	 * @param array  $filters         Additional Pinecone metadata filters.
	 * @return array|WP_Error
	 */
	public function search( array $query_embedding, int $limit = 10, string $object_type = '', array $filters = array() ) {
		$body = array(
			'vector'          => $query_embedding,
			'topK'            => $limit,
			'includeMetadata' => true,
			'namespace'       => self::NAMESPACE,
		);

		// Build metadata filter.
		$meta_filter = array();
		if ( ! empty( $object_type ) ) {
			$meta_filter['_object_type'] = array( '$eq' => $object_type );
		}
		if ( ! empty( $filters ) ) {
			$meta_filter = array_merge( $meta_filter, $filters );
		}
		if ( ! empty( $meta_filter ) ) {
			$body['filter'] = $meta_filter;
		}

		$result = $this->request( '/query', 'POST', $body );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$matches = array();
		if ( ! empty( $result['matches'] ) ) {
			foreach ( $result['matches'] as $match ) {
				$metadata = $match['metadata'] ?? array();
				$matches[] = array(
					'object_id'   => (int) ( $metadata['_object_id'] ?? 0 ),
					'object_type' => $metadata['_object_type'] ?? '',
					'score'       => (float) ( $match['score'] ?? 0 ),
					'metadata'    => $metadata,
				);
			}
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
		$vector_id = $this->build_vector_id( $object_type, $object_id );

		$result = $this->request( '/vectors/delete', 'POST', array(
			'ids'       => array( $vector_id ),
			'namespace' => self::NAMESPACE,
		) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	/**
	 * Delete all embeddings.
	 *
	 * @param string $object_type Filter by type. Empty = delete all.
	 * @return true|WP_Error
	 */
	public function delete_all( string $object_type = '' ) {
		if ( ! empty( $object_type ) ) {
			// Delete by metadata filter (requires Pinecone serverless or S1+).
			$result = $this->request( '/vectors/delete', 'POST', array(
				'filter'    => array(
					'_object_type' => array( '$eq' => $object_type ),
				),
				'namespace' => self::NAMESPACE,
			) );
		} else {
			$result = $this->request( '/vectors/delete', 'POST', array(
				'deleteAll' => true,
				'namespace' => self::NAMESPACE,
			) );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	/**
	 * Get the content hash for a stored embedding.
	 *
	 * Pinecone requires a fetch to read metadata.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @return string|null
	 */
	public function get_content_hash( string $object_type, int $object_id ): ?string {
		$vector_id = $this->build_vector_id( $object_type, $object_id );

		$result = $this->request(
			sprintf( '/vectors/fetch?ids=%s&namespace=%s', rawurlencode( $vector_id ), self::NAMESPACE ),
			'GET'
		);

		if ( is_wp_error( $result ) ) {
			return null;
		}

		$vectors = $result['vectors'] ?? array();
		if ( isset( $vectors[ $vector_id ]['metadata']['_content_hash'] ) ) {
			return $vectors[ $vector_id ]['metadata']['_content_hash'];
		}

		return null;
	}

	/**
	 * Get all stored object IDs for a given type.
	 *
	 * Pinecone doesn't support listing all vectors easily.
	 * We use the list endpoint with a prefix filter.
	 *
	 * @param string $object_type Object type.
	 * @return array
	 */
	public function get_stored_ids( string $object_type ): array {
		$ids    = array();
		$prefix = $object_type . '_';
		$cursor = null;

		do {
			$endpoint = sprintf(
				'/vectors/list?namespace=%s&prefix=%s&limit=100',
				self::NAMESPACE,
				rawurlencode( $prefix )
			);

			if ( $cursor ) {
				$endpoint .= '&paginationToken=' . rawurlencode( $cursor );
			}

			$result = $this->request( $endpoint, 'GET' );

			if ( is_wp_error( $result ) ) {
				break;
			}

			if ( ! empty( $result['vectors'] ) && is_array( $result['vectors'] ) ) {
				foreach ( $result['vectors'] as $vector ) {
					$vector_id = is_array( $vector ) ? (string) ( $vector['id'] ?? '' ) : (string) $vector;
					if ( preg_match( '/_(\d+)$/', $vector_id, $matches ) ) {
						$ids[] = (int) $matches[1];
					}
				}
			}

			$cursor = $result['pagination']['next'] ?? null;

		} while ( $cursor );

		return $ids;
	}

	/**
	 * Get the total count of stored embeddings.
	 *
	 * Uses the describe_index_stats endpoint.
	 *
	 * @param string $object_type Not used — Pinecone stats are namespace-level.
	 * @return int
	 */
	public function count( string $object_type = '' ): int {
		$result = $this->request( '/describe_index_stats', 'POST' );

		if ( is_wp_error( $result ) ) {
			return 0;
		}

		$namespaces = $result['namespaces'] ?? array();
		if ( isset( $namespaces[ self::NAMESPACE ]['vectorCount'] ) ) {
			return (int) $namespaces[ self::NAMESPACE ]['vectorCount'];
		}

		return 0;
	}

	/**
	 * Get all stored vectors with metadata.
	 *
	 * Uses list + fetch to build rows for admin management views.
	 *
	 * @param string $object_type Object type.
	 * @param int    $limit       Limit.
	 * @param int    $offset      Offset.
	 * @return array|WP_Error
	 */
	public function get_all_vectors( string $object_type = '', int $limit = 100, int $offset = 0 ) {
		$limit   = max( 1, $limit );
		$offset  = max( 0, $offset );
		$needed  = $offset + $limit;
		$cursor  = null;
		$all_ids = array();

		do {
			$endpoint = sprintf( '/vectors/list?namespace=%s&limit=100', rawurlencode( self::NAMESPACE ) );
			if ( '' !== $object_type ) {
				$endpoint .= '&prefix=' . rawurlencode( $object_type . '_' );
			}
			if ( $cursor ) {
				$endpoint .= '&paginationToken=' . rawurlencode( (string) $cursor );
			}

			$list_result = $this->request( $endpoint, 'GET' );
			if ( is_wp_error( $list_result ) ) {
				return $list_result;
			}

			$vectors = $list_result['vectors'] ?? array();
			if ( is_array( $vectors ) ) {
				foreach ( $vectors as $vector ) {
					$vector_id = is_array( $vector ) ? (string) ( $vector['id'] ?? '' ) : (string) $vector;
					if ( '' !== $vector_id ) {
						$all_ids[] = $vector_id;
					}
				}
			}

			$cursor = $list_result['pagination']['next'] ?? null;

			if ( count( $all_ids ) >= $needed ) {
				break;
			}
		} while ( $cursor );

		if ( empty( $all_ids ) ) {
			return array();
		}

		$page_ids = array_slice( $all_ids, $offset, $limit );
		if ( empty( $page_ids ) ) {
			return array();
		}

		$rows = array();
		foreach ( $page_ids as $vector_id ) {
			$fetch_endpoint = sprintf(
				'/vectors/fetch?namespace=%s&ids=%s',
				rawurlencode( self::NAMESPACE ),
				rawurlencode( (string) $vector_id )
			);
			$fetch_result = $this->request( $fetch_endpoint, 'GET' );
			if ( is_wp_error( $fetch_result ) ) {
				return $fetch_result;
			}

			$fetched_vectors = is_array( $fetch_result['vectors'] ?? null ) ? $fetch_result['vectors'] : array();
			if ( ! isset( $fetched_vectors[ $vector_id ] ) || ! is_array( $fetched_vectors[ $vector_id ] ) ) {
				continue;
			}

			$vector_data = $fetched_vectors[ $vector_id ];
			$metadata    = is_array( $vector_data['metadata'] ?? null ) ? $vector_data['metadata'] : array();
			$parsed_id   = 0;
			$parsed_type = '';
			if ( preg_match( '/^(.*)_(\d+)$/', (string) $vector_id, $matches ) ) {
				$parsed_type = sanitize_key( (string) $matches[1] );
				$parsed_id   = absint( (int) $matches[2] );
			}

			$rows[] = array(
				'object_id'    => (int) ( $metadata['_object_id'] ?? $parsed_id ),
				'object_type'  => (string) ( $metadata['_object_type'] ?? $parsed_type ),
				'content_hash' => (string) ( $metadata['_content_hash'] ?? '' ),
				'metadata'     => $metadata,
				'updated_at'   => (string) ( $metadata['synced_at'] ?? $metadata['updated_at'] ?? '' ),
			);
		}

		return $rows;
	}

	/**
	 * Test the Pinecone connection.
	 *
	 * @return true|WP_Error
	 */
	public function test_connection() {
		if ( empty( $this->api_key ) ) {
			return new \WP_Error(
				'kivor_chat_agent_pinecone_no_key',
				__( 'Pinecone API key is not configured.', 'kivor-chat-agent' )
			);
		}

		$result = $this->request( '/describe_index_stats', 'POST' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}
}
