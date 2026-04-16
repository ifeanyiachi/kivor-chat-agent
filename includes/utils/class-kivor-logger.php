<?php
/**
 * Chat logger.
 *
 * Centralizes chat log storage, retrieval, and session management.
 *
 * @package KivorAgent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class Kivor_Logger {

	/**
	 * Settings instance.
	 *
	 * @var Kivor_Settings
	 */
	private Kivor_Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Kivor_Settings $settings Settings instance.
	 */
	public function __construct( Kivor_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Check if logging is enabled.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		$log_settings = $this->settings->get( 'chat_logs' );
		return ! empty( $log_settings['logging_enabled'] );
	}

	/**
	 * Log a conversation exchange (user message + bot reply).
	 *
	 * @param string $session_id Session ID.
	 * @param string $user_msg   User message.
	 * @param string $bot_reply  Bot reply.
	 * @param array  $products   Product data (for metadata).
	 * @param bool   $consent    Whether consent was given.
	 */
	public function log_conversation( string $session_id, string $user_msg, string $bot_reply, array $products = array(), bool $consent = false ): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$sentiment = '';
		if ( $this->should_analyze_sentiment( $session_id ) ) {
			$analyzer  = new Kivor_Sentiment_Analyzer( $this->settings );
			$sentiment = $analyzer->classify( $user_msg );
		}

		$ip_hash = $this->get_ip_hash();
		$user_id = get_current_user_id();
		$now     = current_time( 'mysql', true );

		// Log user message.
		$this->insert_log( array(
			'session_id'    => $session_id,
			'user_ip_hash'  => $ip_hash,
			'user_id'       => $user_id ?: null,
			'role'          => 'user',
			'message'       => $user_msg,
			'metadata'      => null,
			'sentiment'     => $sentiment,
			'created_at'    => $now,
			'consent_given' => $consent ? 1 : 0,
		) );

		// Log assistant reply.
		$metadata = null;
		if ( ! empty( $products ) ) {
			$metadata = wp_json_encode( array( 'products' => $products ) );
		}

		$this->insert_log( array(
			'session_id'    => $session_id,
			'user_ip_hash'  => $ip_hash,
			'user_id'       => $user_id ?: null,
			'role'          => 'assistant',
			'message'       => $bot_reply,
			'metadata'      => $metadata,
			'sentiment'     => null,
			'created_at'    => $now,
			'consent_given' => $consent ? 1 : 0,
		) );
	}

	/**
	 * Log a single message.
	 *
	 * @param string      $session_id Session ID.
	 * @param string      $role       'user' or 'assistant'.
	 * @param string      $message    Message content.
	 * @param array|null  $metadata   Optional metadata.
	 * @param bool        $consent    Whether consent was given.
	 */
	public function log_message( string $session_id, string $role, string $message, ?array $metadata = null, bool $consent = false ): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$this->insert_log( array(
			'session_id'    => $session_id,
			'user_ip_hash'  => $this->get_ip_hash(),
			'user_id'       => get_current_user_id() ?: null,
			'role'          => $role,
			'message'       => $message,
			'metadata'      => $metadata ? wp_json_encode( $metadata ) : null,
			'sentiment'     => null,
			'created_at'    => current_time( 'mysql', true ),
			'consent_given' => $consent ? 1 : 0,
		) );
	}

	/**
	 * Get logs for a session.
	 *
	 * @param string $session_id Session ID.
	 * @return array
	 */
	public function get_session_logs( string $session_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'kivor_chat_logs';

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
	 * Delete logs older than a given number of days.
	 *
	 * @param int $days Number of days to retain.
	 * @return int Number of deleted records.
	 */
	public function cleanup_older_than( int $days ): int {
		if ( $days <= 0 ) {
			return 0;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'kivor_chat_logs';

		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
				$days
			)
		);
	}

	/**
	 * Insert a log row into the database.
	 *
	 * @param array $data Row data.
	 */
	private function insert_log( array $data ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'kivor_chat_logs';

		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$wpdb->insert( $table, $data, array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d' ) );
	}

	/**
	 * Check whether sentiment should be analyzed for this message.
	 *
	 * @param string $session_id Session ID.
	 * @return bool
	 */
	private function should_analyze_sentiment( string $session_id ): bool {
		if ( ! (bool) $this->settings->get( 'analytics.enabled', false ) ) {
			return false;
		}

		$mode = (string) $this->settings->get( 'analytics.analyze_mode', 'first_message' );
		if ( 'every_message' === $mode ) {
			return true;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'kivor_chat_logs';
		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE session_id = %s AND role = 'user'", $session_id )
		);

		return $count <= 0;
	}

	/**
	 * Get the hashed IP address, respecting GDPR anonymize setting.
	 *
	 * @return string
	 */
	private function get_ip_hash(): string {
		$gdpr_settings = $this->settings->get( 'gdpr' );
		$anonymize     = ! empty( $gdpr_settings['anonymize_ips'] );

		return Kivor_Sanitizer::hash_ip(
			Kivor_Sanitizer::get_client_ip(),
			$anonymize
		);
	}
}
