<?php
/**
 * Consent Manager.
 *
 * Centralizes GDPR consent operations: recording, checking, and revoking consent.
 * Stores records in the `kivor_` table.
 *
 * @package KivorAgent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class Kivor_Consent_Manager {

	/**
	 * Settings instance.
	 *
	 * @var Kivor_Settings
	 */
	private Kivor_Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Kivor_Settings $settings Plugin settings.
	 */
	public function __construct( Kivor_Settings $settings ) {
		$this->settings = $settings;
	}

	// =========================================================================
	// Public API
	// =========================================================================

	/**
	 * Check whether GDPR consent is required.
	 *
	 * @return bool True if GDPR is enabled AND consent is required.
	 */
	public function is_consent_required(): bool {
		$gdpr = $this->settings->get( 'gdpr' );

		return ! empty( $gdpr['enabled'] ) && ! empty( $gdpr['consent_required'] );
	}

	/**
	 * Check whether a session has already consented.
	 *
	 * Looks for the LATEST consent record to respect revocations.
	 * A revocation (consented = 0) after a grant means consent is no longer active.
	 *
	 * @param string $session_id Session identifier.
	 * @return bool True if the most recent consent record is a grant.
	 */
	public function has_consent( string $session_id ): bool {
		if ( empty( $session_id ) ) {
			return false;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'kivor_consent_log';

		// Get the most recent consent record for this session and type.
		// A revocation record (consented = 0) must override earlier grants.
		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$row = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT consented FROM {$table} WHERE session_id = %s AND consent_type = 'chat_logging' ORDER BY created_at DESC LIMIT 1",
				$session_id
			)
		);

		return null !== $row && intval( $row ) === 1;
	}

	/**
	 * Validate consent for a chat request.
	 *
	 * Returns true if consent is not required, already given, or provided now.
	 * Returns WP_Error if consent is required but not provided.
	 *
	 * @param bool   $consent_given Whether consent was given with this request.
	 * @param string $session_id    Session identifier.
	 * @return true|\WP_Error
	 */
	public function validate_consent( bool $consent_given, string $session_id ) {
		if ( ! $this->is_consent_required() ) {
			return true;
		}

		// Check if already consented in a previous request.
		if ( $this->has_consent( $session_id ) ) {
			return true;
		}

		if ( ! $consent_given ) {
			return new \WP_Error(
				'kivor_chat_agent_consent_required',
				__( 'Please accept the data processing terms to use the chat.', 'kivor-chat-agent' ),
				array( 'status' => 403 )
			);
		}

		// Record consent.
		$this->record_consent( $session_id, 'chat_logging' );

		return true;
	}

	/**
	 * Record a consent event.
	 *
	 * @param string $session_id   Session identifier.
	 * @param string $consent_type Type of consent (e.g., 'chat_logging').
	 * @param bool   $consented    Whether consent was given (true) or revoked (false).
	 */
	public function record_consent( string $session_id, string $consent_type = 'chat_logging', bool $consented = true ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'kivor_consent_log';

		$gdpr_settings = $this->settings->get( 'gdpr' );
		$anonymize     = ! empty( $gdpr_settings['anonymize_ips'] );

		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$wpdb->insert( $table, array(
			'session_id'   => $session_id,
			'consent_type' => $consent_type,
			'consented'    => $consented ? 1 : 0,
			'ip_hash'      => Kivor_Sanitizer::hash_ip(
				Kivor_Sanitizer::get_client_ip(),
				$anonymize
			),
			'created_at' => current_time( 'mysql', true ),
		), array( '%s', '%s', '%d', '%s', '%s' ) );
	}

	/**
	 * Revoke consent for a session.
	 *
	 * Records a revocation event. Does NOT delete the original consent records,
	 * since those serve as an audit trail. The latest record per session determines
	 * the current consent status.
	 *
	 * @param string $session_id Session identifier.
	 */
	public function revoke_consent( string $session_id ): void {
		$this->record_consent( $session_id, 'chat_logging', false );
	}

	/**
	 * Get all consent records for a session.
	 *
	 * @param string $session_id Session identifier.
	 * @return array Array of consent records.
	 */
	public function get_consent_records( string $session_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'kivor_consent_log';

		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE session_id = %s ORDER BY created_at ASC",
				$session_id
			),
			ARRAY_A
		) ?: array();
	}

	/**
	 * Get consent records by IP hash (for privacy data export/erasure).
	 *
	 * @param string $ip_hash Hashed IP address.
	 * @param int    $page    Page number (1-indexed).
	 * @param int    $per_page Records per page.
	 * @return array {
	 *     @type array $records Consent records.
	 *     @type bool  $done    Whether this is the last page.
	 * }
	 */
	public function get_consent_records_by_ip( string $ip_hash, int $page = 1, int $per_page = 100 ): array {
		global $wpdb;
		$table  = $wpdb->prefix . 'kivor_consent_log';
		$offset = ( $page - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$records = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE ip_hash = %s ORDER BY created_at ASC LIMIT %d OFFSET %d",
				$ip_hash,
				$per_page + 1, // Fetch one extra to know if there's a next page.
				$offset
			),
			ARRAY_A
		) ?: array();

		$has_more = count( $records ) > $per_page;
		if ( $has_more ) {
			array_pop( $records ); // Remove the extra record.
		}

		return array(
			'records' => $records,
			'done'    => ! $has_more,
		);
	}

	/**
	 * Delete all consent records for a session (for GDPR erasure).
	 *
	 * @param string $session_id Session identifier.
	 * @return int Number of records deleted.
	 */
	public function delete_consent_records( string $session_id ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'kivor_consent_log';

		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		return (int) $wpdb->delete( $table, array( 'session_id' => $session_id ), array( '%s' ) );
	}

	/**
	 * Delete all consent records by IP hash (for GDPR erasure).
	 *
	 * @param string $ip_hash Hashed IP address.
	 * @return int Number of records deleted.
	 */
	public function delete_consent_records_by_ip( string $ip_hash ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'kivor_consent_log';

		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		return (int) $wpdb->delete( $table, array( 'ip_hash' => $ip_hash ), array( '%s' ) );
	}
}
