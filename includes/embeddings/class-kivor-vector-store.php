<?php
/**
 * Abstract vector store.
 *
 * Base class for all vector storage backends (local DB, Pinecone, Qdrant).
 *
 * @package KivorAgent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

abstract class Kivor_Vector_Store {

	/**
	 * Upsert a single embedding.
	 *
	 * Inserts or updates an embedding vector with its metadata.
	 *
	 * @param string $object_type Object type (e.g. 'product', 'kb_article').
	 * @param int    $object_id   Object ID.
	 * @param array  $embedding   Float array of the embedding vector.
	 * @param array  $metadata    Metadata to store alongside the vector.
	 * @param string $content_hash Hash of the source content for change detection.
	 * @return true|WP_Error
	 */
	abstract public function upsert( string $object_type, int $object_id, array $embedding, array $metadata = array(), string $content_hash = '' );

	/**
	 * Upsert multiple embeddings in a batch.
	 *
	 * @param array $items Array of arrays, each with keys:
	 *                     'object_type', 'object_id', 'embedding', 'metadata', 'content_hash'.
	 * @return true|WP_Error
	 */
	public function upsert_batch( array $items ) {
		foreach ( $items as $item ) {
			$result = $this->upsert(
				$item['object_type'],
				$item['object_id'],
				$item['embedding'],
				$item['metadata'] ?? array(),
				$item['content_hash'] ?? ''
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
	 * @param array  $query_embedding Float array of the query vector.
	 * @param int    $limit           Maximum number of results.
	 * @param string $object_type     Filter by object type. Empty for all types.
	 * @param array  $filters         Additional metadata filters (store-specific).
	 * @return array|WP_Error Array of results: [ ['object_id' => int, 'score' => float, 'metadata' => array], ... ]
	 */
	abstract public function search( array $query_embedding, int $limit = 10, string $object_type = '', array $filters = array() );

	/**
	 * Delete an embedding by object type and ID.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @return true|WP_Error
	 */
	abstract public function delete( string $object_type, int $object_id );

	/**
	 * Delete all embeddings for a given object type.
	 *
	 * @param string $object_type Object type.
	 * @return true|WP_Error
	 */
	abstract public function delete_all( string $object_type = '' );

	/**
	 * Get the content hash for a stored embedding.
	 *
	 * Used by the sync manager for change detection.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @return string|null Content hash or null if not found.
	 */
	abstract public function get_content_hash( string $object_type, int $object_id ): ?string;

	/**
	 * Get all stored object IDs for a given type.
	 *
	 * Used by the sync manager to detect deleted objects.
	 *
	 * @param string $object_type Object type.
	 * @return array Array of object IDs.
	 */
	abstract public function get_stored_ids( string $object_type ): array;

	/**
	 * Get the total count of stored embeddings.
	 *
	 * @param string $object_type Filter by object type. Empty for all.
	 * @return int
	 */
	abstract public function count( string $object_type = '' ): int;

	/**
	 * Get all stored vectors with metadata.
	 *
	 * @param string $object_type Filter by object type. Empty for all.
	 * @param int    $limit       Max rows to return.
	 * @param int    $offset      Offset for pagination.
	 * @return array|WP_Error
	 */
	abstract public function get_all_vectors( string $object_type = '', int $limit = 100, int $offset = 0 );

	/**
	 * Test the connection to the vector store.
	 *
	 * @return true|WP_Error
	 */
	abstract public function test_connection();

	/**
	 * Validate that an embedding array contains only finite floats.
	 *
	 * Prevents sending invalid data (NaN, Infinity, non-numeric values)
	 * to vector store APIs, which could cause silent data corruption
	 * or API errors (VULN-013 fix).
	 *
	 * @param array $embedding The embedding vector to validate.
	 * @return true|WP_Error
	 */
	protected function validate_embedding( array $embedding ) {
		if ( empty( $embedding ) ) {
			return new \WP_Error(
				'kivor_chat_agent_empty_embedding',
				__( 'Embedding vector is empty.', 'kivor-chat-agent' )
			);
		}

		foreach ( $embedding as $i => $value ) {
			if ( ! is_numeric( $value ) ) {
				return new \WP_Error(
					'kivor_chat_agent_invalid_embedding',
					sprintf(
						/* translators: %d: Array index */
						__( 'Embedding vector contains non-numeric value at index %d.', 'kivor-chat-agent' ),
						$i
					)
				);
			}

			$float = (float) $value;
			if ( is_nan( $float ) || is_infinite( $float ) ) {
				return new \WP_Error(
					'kivor_chat_agent_invalid_embedding',
					sprintf(
						/* translators: %d: Array index */
						__( 'Embedding vector contains NaN or Infinite at index %d.', 'kivor-chat-agent' ),
						$i
					)
				);
			}
		}

		return true;
	}
}
