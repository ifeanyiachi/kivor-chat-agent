<?php
/**
 * GDPR controller.
 *
 * Registers WordPress personal data exporters and erasers, adds a suggested
 * privacy policy text, and coordinates GDPR-related functionality.
 *
 * @package KivorAgent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class Kivor_GDPR {

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

	/**
	 * Register hooks.
	 */
	public function init(): void {
		// Personal data exporters.
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporters' ) );

		// Personal data erasers.
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_erasers' ) );

		// Suggested privacy policy text.
		add_action( 'admin_init', array( $this, 'add_privacy_policy_content' ) );
	}

	// =========================================================================
	// Privacy Policy
	// =========================================================================

	/**
	 * Add suggested privacy policy content.
	 *
	 * WordPress displays this in the Privacy Policy editor as suggested text.
	 */
	public function add_privacy_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$gdpr_settings = $this->settings->get( 'gdpr' );
		if ( empty( $gdpr_settings['enabled'] ) ) {
			return;
		}

		$content = $this->get_privacy_policy_text();

		wp_add_privacy_policy_content(
			__( 'Kivor Chat Agent', 'kivor-chat-agent' ),
			wp_kses_post( $content )
		);
	}

	/**
	 * Get the suggested privacy policy text.
	 *
	 * @return string HTML content.
	 */
	private function get_privacy_policy_text(): string {
		$gdpr_settings = $this->settings->get( 'gdpr' );
		$retention     = absint( $gdpr_settings['data_retention_days'] ?? 90 );

		$text  = '<h2>' . __( 'Chatbot (Kivor Chat Agent)', 'kivor-chat-agent' ) . '</h2>';
		$text .= '<p>' . __( 'We use an AI-powered chatbot on this website to help answer your questions and assist with product discovery.', 'kivor-chat-agent' ) . '</p>';

		$text .= '<h3>' . __( 'What data we collect', 'kivor-chat-agent' ) . '</h3>';
		$text .= '<p>' . __( 'When you interact with our chatbot, we may collect the following information:', 'kivor-chat-agent' ) . '</p>';
		$text .= '<ul>';
		$text .= '<li>' . __( 'Chat messages you send and the responses you receive', 'kivor-chat-agent' ) . '</li>';
		$text .= '<li>' . __( 'A hashed version of your IP address (not the actual IP)', 'kivor-chat-agent' ) . '</li>';
		$text .= '<li>' . __( 'A session identifier stored in a cookie', 'kivor-chat-agent' ) . '</li>';
		$text .= '<li>' . __( 'Your consent status and timestamp', 'kivor-chat-agent' ) . '</li>';
		$text .= '</ul>';

		$text .= '<h3>' . __( 'How we use your data', 'kivor-chat-agent' ) . '</h3>';
		$text .= '<p>' . __( 'Your chat data is used to:', 'kivor-chat-agent' ) . '</p>';
		$text .= '<ul>';
		$text .= '<li>' . __( 'Provide relevant answers and product recommendations', 'kivor-chat-agent' ) . '</li>';
		$text .= '<li>' . __( 'Maintain conversation context during your session', 'kivor-chat-agent' ) . '</li>';
		$text .= '<li>' . __( 'Improve the quality of our chatbot responses', 'kivor-chat-agent' ) . '</li>';
		$text .= '</ul>';

		$text .= '<h3>' . __( 'Third-party AI processing', 'kivor-chat-agent' ) . '</h3>';
		$text .= '<p>' . __( 'Your messages are sent to a third-party AI service (such as OpenAI, Google Gemini, or OpenRouter) for processing. These services have their own privacy policies governing how they handle data.', 'kivor-chat-agent' ) . '</p>';

		$text .= '<h3>' . __( 'Data retention', 'kivor-chat-agent' ) . '</h3>';
		$text .= '<p>' . sprintf(
			/* translators: %d: number of days */
			__( 'Chat logs and consent records are automatically deleted after %d days.', 'kivor-chat-agent' ),
			$retention
		) . '</p>';

		$text .= '<h3>' . __( 'Your rights', 'kivor-chat-agent' ) . '</h3>';
		$text .= '<p>' . __( 'You can request export or deletion of your chatbot data through the standard WordPress privacy tools. Use the "Export Personal Data" or "Erase Personal Data" tools under the Tools menu.', 'kivor-chat-agent' ) . '</p>';

		return $text;
	}

	// =========================================================================
	// Data Exporters
	// =========================================================================

	/**
	 * Register personal data exporters.
	 *
	 * @param array $exporters Existing exporters.
	 * @return array Modified exporters.
	 */
	public function register_exporters( array $exporters ): array {
		$exporters['kivor-chat-agent-chat-logs'] = array(
			'exporter_friendly_name' => __( 'Kivor Chat Agent Chat Logs', 'kivor-chat-agent' ),
			'callback'               => array( $this, 'export_chat_logs' ),
		);

		$exporters['kivor-chat-agent-consent'] = array(
			'exporter_friendly_name' => __( 'Kivor Chat Agent Consent Records', 'kivor-chat-agent' ),
			'callback'               => array( $this, 'export_consent_records' ),
		);

		return $exporters;
	}

	/**
	 * Export chat logs for a user.
	 *
	 * WordPress calls this with an email address. We look up the user by email,
	 * then export their chat logs. For non-logged-in users, data is associated
	 * with an IP hash, which can't be reverse-mapped from an email — those records
	 * can only be exported via session-based tools.
	 *
	 * @param string $email_address User email.
	 * @param int    $page          Page number.
	 * @return array {
	 *     @type array $data Export data items.
	 *     @type bool  $done Whether all data has been exported.
	 * }
	 */
	public function export_chat_logs( string $email_address, int $page = 1 ): array {
		$user = get_user_by( 'email', $email_address );

		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		global $wpdb;
		$table    = $wpdb->prefix . 'kivor_chat_logs';
		$per_page = 100;
		$offset   = ( $page - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at ASC LIMIT %d OFFSET %d",
				$user->ID,
				$per_page + 1,
				$offset
			),
			ARRAY_A
		);

		$has_more = count( $rows ) > $per_page;
		if ( $has_more ) {
			array_pop( $rows );
		}

		$export_items = array();

		foreach ( $rows as $row ) {
			$data = array(
				array(
					'name'  => __( 'Session ID', 'kivor-chat-agent' ),
					'value' => $row['session_id'],
				),
				array(
					'name'  => __( 'Role', 'kivor-chat-agent' ),
					'value' => $row['role'],
				),
				array(
					'name'  => __( 'Message', 'kivor-chat-agent' ),
					'value' => $row['message'],
				),
				array(
					'name'  => __( 'Date', 'kivor-chat-agent' ),
					'value' => $row['created_at'],
				),
				array(
					'name'  => __( 'Consent Given', 'kivor-chat-agent' ),
					'value' => $row['consent_given'] ? __( 'Yes', 'kivor-chat-agent' ) : __( 'No', 'kivor-chat-agent' ),
				),
			);

			$export_items[] = array(
				'group_id'          => 'kivor-chat-agent-chat-logs',
				'group_label'       => __( 'Kivor Chat Agent Chat Logs', 'kivor-chat-agent' ),
				'group_description' => __( 'Chat messages exchanged with the Kivor Chat Agent chatbot.', 'kivor-chat-agent' ),
				'item_id'           => 'kivor-chat-agent-chat-log-' . $row['id'],
				'data'              => $data,
			);
		}

		return array(
			'data' => $export_items,
			'done' => ! $has_more,
		);
	}

	/**
	 * Export consent records for a user.
	 *
	 * Since consent records are stored by session/IP hash rather than user ID,
	 * we look up the user's chat log sessions first, then export associated consent.
	 *
	 * @param string $email_address User email.
	 * @param int    $page          Page number.
	 * @return array {
	 *     @type array $data Export data items.
	 *     @type bool  $done Whether all data has been exported.
	 * }
	 */
	public function export_consent_records( string $email_address, int $page = 1 ): array {
		$user = get_user_by( 'email', $email_address );

		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		// Get session IDs associated with this user from chat logs.
		$session_ids = $this->get_user_session_ids( $user->ID );

		if ( empty( $session_ids ) ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		global $wpdb;
		$table    = $wpdb->prefix . 'kivor_consent_log';
		$per_page = 100;
		$offset   = ( $page - 1 ) * $per_page;

		// Build placeholders for IN clause.
		$placeholders = implode( ',', array_fill( 0, count( $session_ids ), '%s' ) );

		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE session_id IN ({$placeholders}) ORDER BY created_at ASC LIMIT %d OFFSET %d",
				array_merge( $session_ids, array( $per_page + 1, $offset ) )
			),
			ARRAY_A
		);

		$has_more = count( $rows ) > $per_page;
		if ( $has_more ) {
			array_pop( $rows );
		}

		$export_items = array();

		foreach ( $rows as $row ) {
			$data = array(
				array(
					'name'  => __( 'Session ID', 'kivor-chat-agent' ),
					'value' => $row['session_id'],
				),
				array(
					'name'  => __( 'Consent Type', 'kivor-chat-agent' ),
					'value' => $row['consent_type'],
				),
				array(
					'name'  => __( 'Consented', 'kivor-chat-agent' ),
					'value' => $row['consented'] ? __( 'Yes', 'kivor-chat-agent' ) : __( 'No', 'kivor-chat-agent' ),
				),
				array(
					'name'  => __( 'Date', 'kivor-chat-agent' ),
					'value' => $row['created_at'],
				),
			);

			$export_items[] = array(
				'group_id'          => 'kivor-chat-agent-consent',
				'group_label'       => __( 'Kivor Chat Agent Consent Records', 'kivor-chat-agent' ),
				'group_description' => __( 'Consent records for Kivor Chat Agent chatbot data processing.', 'kivor-chat-agent' ),
				'item_id'           => 'kivor-chat-agent-consent-' . $row['id'],
				'data'              => $data,
			);
		}

		return array(
			'data' => $export_items,
			'done' => ! $has_more,
		);
	}

	// =========================================================================
	// Data Erasers
	// =========================================================================

	/**
	 * Register personal data erasers.
	 *
	 * @param array $erasers Existing erasers.
	 * @return array Modified erasers.
	 */
	public function register_erasers( array $erasers ): array {
		$erasers['kivor-chat-agent-chat-logs'] = array(
			'eraser_friendly_name' => __( 'Kivor Chat Agent Chat Logs', 'kivor-chat-agent' ),
			'callback'             => array( $this, 'erase_chat_logs' ),
		);

		$erasers['kivor-chat-agent-consent'] = array(
			'eraser_friendly_name' => __( 'Kivor Chat Agent Consent Records', 'kivor-chat-agent' ),
			'callback'             => array( $this, 'erase_consent_records' ),
		);

		return $erasers;
	}

	/**
	 * Erase chat logs for a user.
	 *
	 * @param string $email_address User email.
	 * @param int    $page          Page number.
	 * @return array {
	 *     @type int    $items_removed  Number of items removed.
	 *     @type int    $items_retained Number of items retained.
	 *     @type array  $messages       Messages to show.
	 *     @type bool   $done           Whether all data has been erased.
	 * }
	 */
	public function erase_chat_logs( string $email_address, int $page = 1 ): array {
		$user = get_user_by( 'email', $email_address );

		if ( ! $user ) {
			return array(
				'items_removed'  => 0,
				'items_retained' => 0,
				'messages'       => array(),
				'done'           => true,
			);
		}

		global $wpdb;
		$table = $wpdb->prefix . 'kivor_chat_logs';

		// Delete in batches to avoid timeout on large datasets.
		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE user_id = %d LIMIT 500",
				$user->ID
			)
		);

		$items_removed = max( 0, (int) $deleted );
		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$remaining     = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $user->ID )
		);

		return array(
			'items_removed'  => $items_removed,
			'items_retained' => 0,
			'messages'       => array(),
			'done'           => 0 === $remaining,
		);
	}

	/**
	 * Erase consent records for a user.
	 *
	 * @param string $email_address User email.
	 * @param int    $page          Page number.
	 * @return array {
	 *     @type int    $items_removed  Number of items removed.
	 *     @type int    $items_retained Number of items retained.
	 *     @type array  $messages       Messages to show.
	 *     @type bool   $done           Whether all data has been erased.
	 * }
	 */
	public function erase_consent_records( string $email_address, int $page = 1 ): array {
		$user = get_user_by( 'email', $email_address );

		if ( ! $user ) {
			return array(
				'items_removed'  => 0,
				'items_retained' => 0,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$session_ids = $this->get_user_session_ids( $user->ID );

		if ( empty( $session_ids ) ) {
			return array(
				'items_removed'  => 0,
				'items_retained' => 0,
				'messages'       => array(),
				'done'           => true,
			);
		}

		global $wpdb;
		$table        = $wpdb->prefix . 'kivor_consent_log';
		$placeholders = implode( ',', array_fill( 0, count( $session_ids ), '%s' ) );

		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE session_id IN ({$placeholders}) LIMIT 500",
				$session_ids
			)
		);

		$items_removed = max( 0, (int) $deleted );

		// Check if there are remaining records.
		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$remaining = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE session_id IN ({$placeholders})",
				$session_ids
			)
		);

		return array(
			'items_removed'  => $items_removed,
			'items_retained' => 0,
			'messages'       => array(),
			'done'           => 0 === $remaining,
		);
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Get all distinct session IDs for a user from chat logs.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array Array of session ID strings.
	 */
	private function get_user_session_ids( int $user_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'kivor_chat_logs';

		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$results = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT session_id FROM {$table} WHERE user_id = %d",
				$user_id
			)
		);

		return $results ?: array();
	}
}
