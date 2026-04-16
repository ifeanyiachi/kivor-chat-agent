<?php
/**
 * Local vector store.
 *
	 * Stores embeddings in the WordPress database (wp_kivor_embeddings table)
 * and performs cosine similarity search in PHP.
 *
 * Suitable for stores with < 5,000 vectors. For larger stores, use
 * Pinecone or Qdrant.
 *
 * @package KivorAgent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class Kivor_Local_Store extends Kivor_Vector_Store {

	/**
	 * Get the embeddings table name.
	 *
	 * @return string
	 */
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'kivor_embeddings';
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
		global $wpdb;

		$table        = $this->table();
		$binary       = $this->pack_vector( $embedding );
		$metadata_json = wp_json_encode( $metadata );

		// Check if exists.
		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE object_type = %s AND object_id = %d",
				$object_type,
				$object_id
			)
		);

		if ( $existing_id ) {
			// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
			$result = $wpdb->update(
				$table,
				array(
					'embedding'    => $binary,
					'metadata'     => $metadata_json,
					'content_hash' => $content_hash,
					'updated_at'   => current_time( 'mysql' ),
				),
				array(
					'object_type' => $object_type,
					'object_id'   => $object_id,
				),
				array( '%s', '%s', '%s', '%s' ),
				array( '%s', '%d' )
			);
		} else {
			// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
			$result = $wpdb->insert(
				$table,
				array(
					'object_type'  => $object_type,
					'object_id'    => $object_id,
					'embedding'    => $binary,
					'metadata'     => $metadata_json,
					'content_hash' => $content_hash,
					'updated_at'   => current_time( 'mysql' ),
				),
				array( '%s', '%d', '%s', '%s', '%s', '%s' )
			);
		}

		if ( false === $result ) {
			return new \WP_Error(
				'kivor_chat_agent_local_store_error',
				sprintf(
					/* translators: %s: Database error message */
					__( 'Failed to store embedding: %s', 'kivor-chat-agent' ),
					$wpdb->last_error
				)
			);
		}

		return true;
	}

	/**
	 * Search for similar vectors using cosine similarity.
	 *
	 * Loads all embeddings of the specified type into memory and computes
	 * cosine similarity. This is O(n) but acceptable for < 5,000 vectors.
	 *
	 * @param array  $query_embedding Float array of the query vector.
	 * @param int    $limit           Maximum number of results.
	 * @param string $object_type     Filter by object type.
	 * @param array  $filters         Not used for local store.
	 * @return array|WP_Error
	 */
	public function search( array $query_embedding, int $limit = 10, string $object_type = '', array $filters = array() ) {
		global $wpdb;

		$table = $this->table();

		$where = '';
		if ( ! empty( $object_type ) ) {
			// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
			$where = $wpdb->prepare( ' WHERE object_type = %s', $object_type );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT object_id, object_type, embedding, metadata FROM {$table}{$where}",
			ARRAY_A
		);

		if ( null === $rows ) {
			return new \WP_Error(
				'kivor_chat_agent_local_store_error',
				__( 'Failed to query embeddings from database.', 'kivor-chat-agent' )
			);
		}

		if ( empty( $rows ) ) {
			return array();
		}

		// Compute cosine similarity for each row.
		$scored = array();

		// Pre-compute query magnitude.
		$query_magnitude = $this->magnitude( $query_embedding );
		if ( $query_magnitude == 0.0 ) {
			return array();
		}

		foreach ( $rows as $row ) {
			$stored_embedding = $this->unpack_vector( $row['embedding'] );
			if ( empty( $stored_embedding ) ) {
				continue;
			}

			$score = $this->cosine_similarity( $query_embedding, $stored_embedding, $query_magnitude );

			$scored[] = array(
				'object_id'   => (int) $row['object_id'],
				'object_type' => $row['object_type'],
				'score'       => $score,
				'metadata'    => json_decode( $row['metadata'], true ) ?: array(),
			);
		}

		// Sort by score descending.
		usort( $scored, function ( $a, $b ) {
			return $b['score'] <=> $a['score'];
		} );

		return array_slice( $scored, 0, $limit );
	}

	/**
	 * Delete an embedding.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @return true|WP_Error
	 */
	public function delete( string $object_type, int $object_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$result = $wpdb->delete(
			$this->table(),
			array(
				'object_type' => $object_type,
				'object_id'   => $object_id,
			),
			array( '%s', '%d' )
		);

		if ( false === $result ) {
			return new \WP_Error(
				'kivor_chat_agent_local_store_error',
				__( 'Failed to delete embedding.', 'kivor-chat-agent' )
			);
		}

		return true;
	}

	/**
	 * Delete all embeddings, optionally filtered by type.
	 *
	 * @param string $object_type Object type. Empty for all.
	 * @return true|WP_Error
	 */
	public function delete_all( string $object_type = '' ) {
		global $wpdb;

		$table = $this->table();

		if ( ! empty( $object_type ) ) {
			// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
			$result = $wpdb->delete(
				$table,
				array( 'object_type' => $object_type ),
				array( '%s' )
			);
		} else {
			// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
			$result = $wpdb->query( "TRUNCATE TABLE {$table}" );
		}

		if ( false === $result ) {
			return new \WP_Error(
				'kivor_chat_agent_local_store_error',
				__( 'Failed to delete embeddings.', 'kivor-chat-agent' )
			);
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
		global $wpdb;

		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$hash = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT content_hash FROM {$this->table()} WHERE object_type = %s AND object_id = %d",
				$object_type,
				$object_id
			)
		);

		return $hash ?: null;
	}

	/**
	 * Get all stored object IDs for a given type.
	 *
	 * @param string $object_type Object type.
	 * @return array
	 */
	public function get_stored_ids( string $object_type ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT object_id FROM {$this->table()} WHERE object_type = %s",
				$object_type
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Get the total count of stored embeddings.
	 *
	 * @param string $object_type Filter by object type.
	 * @return int
	 */
	public function count( string $object_type = '' ): int {
		global $wpdb;

		$table = $this->table();

		if ( ! empty( $object_type ) ) {
			// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE object_type = %s",
					$object_type
				)
			);
		}

		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
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
		global $wpdb;

		$table = $this->table();
		$limit = max( 1, $limit );
		$offset = max( 0, $offset );

		if ( '' !== $object_type ) {
			// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT object_id, object_type, content_hash, metadata, updated_at FROM {$table} WHERE object_type = %s ORDER BY updated_at DESC LIMIT %d OFFSET %d",
					$object_type,
					$limit,
					$offset
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT object_id, object_type, content_hash, metadata, updated_at FROM {$table} ORDER BY updated_at DESC LIMIT %d OFFSET %d",
					$limit,
					$offset
				),
				ARRAY_A
			);
		}

		if ( null === $rows ) {
			return new \WP_Error(
				'kivor_chat_agent_local_store_error',
				__( 'Failed to fetch vectors from local store.', 'kivor-chat-agent' )
			);
		}

		return array_map(
			static function ( array $row ): array {
				return array(
					'object_id'    => (int) ( $row['object_id'] ?? 0 ),
					'object_type'  => (string) ( $row['object_type'] ?? '' ),
					'content_hash' => (string) ( $row['content_hash'] ?? '' ),
					'metadata'     => json_decode( (string) ( $row['metadata'] ?? '' ), true ) ?: array(),
					'updated_at'   => (string) ( $row['updated_at'] ?? '' ),
				);
			},
			$rows
		);
	}

	/**
	 * Test the connection (local DB is always available).
	 *
	 * @return true|WP_Error
	 */
	public function test_connection() {
		global $wpdb;

		$table  = $this->table();
		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$result = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );

		if ( $result !== $table ) {
			return new \WP_Error(
				'kivor_chat_agent_local_store_error',
				__( 'Embeddings database table does not exist. Try deactivating and reactivating the plugin.', 'kivor-chat-agent' )
			);
		}

		return true;
	}

	// =========================================================================
	// Vector packing / unpacking
	// =========================================================================

	/**
	 * Pack a float array into a binary string for storage.
	 *
	 * Uses 32-bit floats (4 bytes per dimension) to keep storage compact.
	 * A 1536-dim vector = 6 KB.
	 *
	 * @param array $vector Float array.
	 * @return string Binary string.
	 */
	private function pack_vector( array $vector ): string {
		$binary = '';
		foreach ( $vector as $val ) {
			$binary .= pack( 'f', (float) $val );
		}
		return $binary;
	}

	/**
	 * Unpack a binary string back into a float array.
	 *
	 * @param string $binary Binary string.
	 * @return array Float array.
	 */
	private function unpack_vector( string $binary ): array {
		if ( empty( $binary ) ) {
			return array();
		}

		$count  = strlen( $binary ) / 4; // 4 bytes per float.
		$values = unpack( "f{$count}", $binary );

		return $values ? array_values( $values ) : array();
	}

	// =========================================================================
	// Math helpers
	// =========================================================================

	/**
	 * Compute cosine similarity between two vectors.
	 *
	 * cosine_sim = dot(a, b) / (|a| * |b|)
	 *
	 * @param array $a              First vector.
	 * @param array $b              Second vector.
	 * @param float $a_magnitude    Pre-computed magnitude of vector a (optional optimization).
	 * @return float Similarity score between -1 and 1.
	 */
	private function cosine_similarity( array $a, array $b, float $a_magnitude = 0.0 ): float {
		$len = min( count( $a ), count( $b ) );
		if ( 0 === $len ) {
			return 0.0;
		}

		$dot = 0.0;
		$mag_b = 0.0;

		for ( $i = 0; $i < $len; $i++ ) {
			$dot   += $a[ $i ] * $b[ $i ];
			$mag_b += $b[ $i ] * $b[ $i ];
		}

		$mag_b = sqrt( $mag_b );

		if ( $a_magnitude == 0.0 ) {
			$a_magnitude = $this->magnitude( $a );
		}

		$denominator = $a_magnitude * $mag_b;
		if ( $denominator == 0.0 ) {
			return 0.0;
		}

		return $dot / $denominator;
	}

	/**
	 * Compute the magnitude (L2 norm) of a vector.
	 *
	 * @param array $vector Float array.
	 * @return float
	 */
	private function magnitude( array $vector ): float {
		$sum = 0.0;
		foreach ( $vector as $val ) {
			$sum += $val * $val;
		}
		return sqrt( $sum );
	}
}
