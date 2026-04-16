<?php
/**
 * Analytics and insights service.
 *
 * Aggregates dashboard metrics and alert checks.
 *
 * @package KivorAgent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class Kivor_Analytics {

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
	 * Get metrics summary for dashboard.
	 *
	 * @return array
	 */
	public function get_summary(): array {
		global $wpdb;
		$logs_table = $wpdb->prefix . 'kivor_chat_logs';
		$conv_table = $wpdb->prefix . 'kivor_conversion_events';

		if ( ! $this->table_exists( $logs_table ) ) {
			return $this->empty_summary();
		}

		$has_conversion_table = $this->table_exists( $conv_table );

		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$total_conversations = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT session_id) FROM {$logs_table}" );
		$total_messages      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$logs_table}" );
		$avg_messages        = $total_conversations > 0 ? round( $total_messages / $total_conversations, 2 ) : 0;

		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$sentiments = $wpdb->get_results(
			"SELECT sentiment, COUNT(*) AS cnt FROM {$logs_table} WHERE role = 'user' AND sentiment IS NOT NULL AND sentiment <> '' GROUP BY sentiment",
			ARRAY_A
		);

		$sentiment_counts = array(
			'positive' => 0,
			'neutral'  => 0,
			'negative' => 0,
		);
		$sentiment_total = 0;
		foreach ( $sentiments as $row ) {
			$key = (string) ( $row['sentiment'] ?? '' );
			$cnt = (int) ( $row['cnt'] ?? 0 );
			if ( isset( $sentiment_counts[ $key ] ) ) {
				$sentiment_counts[ $key ] = $cnt;
				$sentiment_total         += $cnt;
			}
		}

		$events = array();
		if ( $has_conversion_table ) {
			// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
			$events = $wpdb->get_results(
				"SELECT event_type, COUNT(*) AS cnt, COALESCE(SUM(revenue),0) AS revenue FROM {$conv_table} GROUP BY event_type",
				ARRAY_A
			);
		}

		$event_counts = array(
			'recommended'   => 0,
			'clicked'       => 0,
			'added_to_cart' => 0,
			'purchased'     => 0,
			'revenue'       => 0.0,
		);
		foreach ( $events as $row ) {
			$type = (string) ( $row['event_type'] ?? '' );
			if ( isset( $event_counts[ $type ] ) ) {
				$event_counts[ $type ] = (int) ( $row['cnt'] ?? 0 );
			}
			if ( 'purchased' === $type ) {
				$event_counts['revenue'] = (float) ( $row['revenue'] ?? 0 );
			}
		}

		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$top_keywords = $wpdb->get_results(
			"SELECT LOWER(TRIM(SUBSTRING_INDEX(message, ' ', 1))) AS keyword, COUNT(*) AS cnt FROM {$logs_table} WHERE role = 'user' AND message <> '' GROUP BY keyword ORDER BY cnt DESC LIMIT 10",
			ARRAY_A
		);

		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$negative_topics = $wpdb->get_results(
			"SELECT LOWER(TRIM(SUBSTRING_INDEX(message, ' ', 1))) AS keyword, COUNT(*) AS cnt FROM {$logs_table} WHERE role = 'user' AND sentiment = 'negative' AND message <> '' GROUP BY keyword ORDER BY cnt DESC LIMIT 10",
			ARRAY_A
		);

		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$peak_hours = $wpdb->get_results(
			"SELECT HOUR(created_at) AS hr, COUNT(*) AS cnt FROM {$logs_table} GROUP BY hr ORDER BY hr ASC",
			ARRAY_A
		);

		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$bounce_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM (SELECT session_id FROM {$logs_table} GROUP BY session_id HAVING COUNT(*) = 1) t"
		);
		$bounce_rate = $total_conversations > 0 ? round( ( $bounce_count / $total_conversations ) * 100, 2 ) : 0;

		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$avg_session_duration = (float) $wpdb->get_var(
			"SELECT AVG(TIMESTAMPDIFF(SECOND, first_at, last_at)) FROM (SELECT MIN(created_at) AS first_at, MAX(created_at) AS last_at FROM {$logs_table} GROUP BY session_id) t"
		);

		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$response_rows = $wpdb->get_results(
			"SELECT session_id, role, created_at FROM {$logs_table} ORDER BY session_id ASC, created_at ASC, id ASC",
			ARRAY_A
		);
		$avg_response_time = $this->calculate_average_response_time( $response_rows );

		$recommendations = max( 1, $event_counts['recommended'] );
		$ctr             = round( ( $event_counts['clicked'] / $recommendations ) * 100, 2 );
		$cart_rate       = round( ( $event_counts['added_to_cart'] / $recommendations ) * 100, 2 );
		$purchase_rate   = round( ( $event_counts['purchased'] / $recommendations ) * 100, 2 );

		return array(
			'total_conversations' => $total_conversations,
			'total_messages'      => $total_messages,
			'avg_messages'        => $avg_messages,
			'sentiment'           => array(
				'counts' => $sentiment_counts,
				'total'  => $sentiment_total,
			),
			'conversion'          => array(
				'recommended' => $event_counts['recommended'],
				'clicked'     => $event_counts['clicked'],
				'added'       => $event_counts['added_to_cart'],
				'purchased'   => $event_counts['purchased'],
				'revenue'     => round( $event_counts['revenue'], 2 ),
				'ctr'         => $ctr,
				'cart_rate'   => $cart_rate,
				'purchase_rate' => $purchase_rate,
			),
			'top_keywords'        => $top_keywords,
			'negative_topics'     => $negative_topics,
			'peak_hours'          => $peak_hours,
			'bounce_rate'         => $bounce_rate,
			'avg_response_time_seconds' => $avg_response_time,
			'avg_session_duration_seconds' => round( $avg_session_duration, 2 ),
		);
	}

	/**
	 * Get an empty summary payload.
	 *
	 * @return array
	 */
	private function empty_summary(): array {
		return array(
			'total_conversations' => 0,
			'total_messages'      => 0,
			'avg_messages'        => 0,
			'sentiment'           => array(
				'counts' => array(
					'positive' => 0,
					'neutral'  => 0,
					'negative' => 0,
				),
				'total' => 0,
			),
			'conversion'          => array(
				'recommended' => 0,
				'clicked' => 0,
				'added' => 0,
				'purchased' => 0,
				'revenue' => 0,
				'ctr' => 0,
				'cart_rate' => 0,
				'purchase_rate' => 0,
			),
			'top_keywords'        => array(),
			'negative_topics'     => array(),
			'peak_hours'          => array(),
			'bounce_rate'         => 0,
			'avg_response_time_seconds' => 0,
			'avg_session_duration_seconds' => 0,
		);
	}

	/**
	 * Check if a database table exists.
	 *
	 * @param string $table_name Table name.
	 * @return bool
	 */
	private function table_exists( string $table_name ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		return is_string( $found ) && $found === $table_name;
	}

	/**
	 * Calculate average response time from ordered chat rows.
	 *
	 * @param array $rows Ordered rows with session_id, role, created_at.
	 * @return float
	 */
	private function calculate_average_response_time( array $rows ): float {
		$last_user_at = array();
		$total        = 0.0;
		$count        = 0;

		foreach ( $rows as $row ) {
			$session_id = (string) ( $row['session_id'] ?? '' );
			$role       = (string) ( $row['role'] ?? '' );
			$created_at = (string) ( $row['created_at'] ?? '' );
			$ts         = strtotime( $created_at );

			if ( '' === $session_id || ! $ts ) {
				continue;
			}

			if ( 'user' === $role ) {
				$last_user_at[ $session_id ] = $ts;
				continue;
			}

			if ( 'assistant' === $role && isset( $last_user_at[ $session_id ] ) ) {
				$diff = $ts - $last_user_at[ $session_id ];
				if ( $diff >= 0 && $diff <= 3600 ) {
					$total += $diff;
					$count++;
				}
				unset( $last_user_at[ $session_id ] );
			}
		}

		if ( $count <= 0 ) {
			return 0;
		}

		return round( $total / $count, 2 );
	}

	/**
	 * Trigger negative sentiment alerts.
	 *
	 * @return void
	 */
	public function maybe_alert_negative_sentiment(): void {
		$enabled = (bool) $this->settings->get( 'analytics.enabled', false );
		if ( ! $enabled ) {
			return;
		}

		if ( ! is_admin() && ! wp_doing_cron() ) {
			return;
		}

		$summary = $this->get_summary();
		$total   = (int) ( $summary['sentiment']['total'] ?? 0 );
		$neg     = (int) ( $summary['sentiment']['counts']['negative'] ?? 0 );

		if ( $total <= 0 ) {
			return;
		}

		$ratio     = ( $neg / $total ) * 100;
		$threshold = (int) $this->settings->get( 'analytics.alert_threshold', 30 );
		if ( $ratio < $threshold ) {
			return;
		}

		$today_key = 'kivor_chat_agent_negative_alert_' . gmdate( 'Ymd' );
		if ( get_transient( $today_key ) ) {
			return;
		}

		set_transient( 'kivor_chat_agent_negative_alert_notice', array(
			'ratio'     => round( $ratio, 2 ),
			'threshold' => $threshold,
			'time'      => current_time( 'mysql', true ),
		), DAY_IN_SECONDS );

		$email = (string) $this->settings->get( 'analytics.alert_email', '' );
		if ( '' === $email ) {
			$email = (string) get_option( 'admin_email', '' );
		}

		if ( is_email( $email ) ) {
			$subject = __( 'Kivor Chat Agent: High negative sentiment alert', 'kivor-chat-agent' );
			$message = sprintf(
				/* translators: 1: negative ratio, 2: threshold */
				__( 'Negative sentiment ratio reached %1$s%% (threshold %2$s%%). Please review recent conversations in Insights.', 'kivor-chat-agent' ),
				round( $ratio, 2 ),
				$threshold
			);
			wp_mail( $email, $subject, $message );
		}

		set_transient( $today_key, 1, DAY_IN_SECONDS );
	}
}
