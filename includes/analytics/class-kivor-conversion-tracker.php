<?php
/**
 * Conversion tracker service.
 *
 * Stores recommendation and conversion funnel events.
 *
 * @package KivorAgent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class Kivor_Conversion_Tracker {

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
	 * Check if analytics tracking is enabled.
	 *
	 * @return bool
	 */
	private function is_enabled(): bool {
		return (bool) $this->settings->get( 'analytics.enabled', false );
	}

	/**
	 * Track one conversion event.
	 *
	 * @param string $session_id Session ID.
	 * @param int    $product_id Product ID.
	 * @param string $event_type Event type.
	 * @param string $source_url Source URL.
	 * @param float  $revenue    Revenue amount.
	 * @return void
	 */
	public function track_event( string $session_id, int $product_id, string $event_type, string $source_url = '', float $revenue = 0.0 ): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		if ( '' === trim( $session_id ) || $product_id <= 0 ) {
			return;
		}

		if ( ! in_array( $event_type, array( 'recommended', 'clicked', 'added_to_cart', 'purchased' ), true ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'kivor_conversion_events';

		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$wpdb->insert(
			$table,
			array(
				'session_id' => Kivor_Sanitizer::sanitize_session_id( $session_id ),
				'product_id' => $product_id,
				'event_type' => $event_type,
				'source_url' => esc_url_raw( $source_url ),
				'revenue'    => max( 0, $revenue ),
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%s', '%s', '%f', '%s' )
		);
	}

	/**
	 * Track product recommendations from chat response.
	 *
	 * @param string $session_id Session ID.
	 * @param array  $products   Product cards.
	 * @return void
	 */
	public function track_recommendations( string $session_id, array $products ): void {
		foreach ( $products as $product ) {
			$product_id = absint( $product['id'] ?? 0 );
			if ( $product_id <= 0 ) {
				continue;
			}

			$this->track_event( $session_id, $product_id, 'recommended' );
		}
	}

	/**
	 * Track product recommendations once per session/response set.
	 *
	 * Prevents duplicate recommendation rows for the same product in the
	 * same session when multiple code paths render products.
	 *
	 * @param string $session_id Session ID.
	 * @param array  $products   Product cards.
	 * @return void
	 */
	public function track_recommendations_once( string $session_id, array $products ): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$ids = array();
		foreach ( $products as $product ) {
			$product_id = absint( $product['id'] ?? 0 );
			if ( $product_id > 0 ) {
				$ids[] = $product_id;
			}
		}

		$ids = array_values( array_unique( $ids ) );
		if ( empty( $ids ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'kivor_conversion_events';

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$params       = array_merge( array( $session_id, 'recommended' ), $ids );

		$sql = "SELECT product_id FROM {$table} WHERE session_id = %s AND event_type = %s AND product_id IN ({$placeholders})";
		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$existing = $wpdb->get_col( $wpdb->prepare( $sql, ...$params ) );
		$existing = array_map( 'intval', is_array( $existing ) ? $existing : array() );

		foreach ( $ids as $product_id ) {
			if ( in_array( $product_id, $existing, true ) ) {
				continue;
			}
			$this->track_event( $session_id, $product_id, 'recommended' );
		}
	}

	/**
	 * Attribute purchase to prior recommendation for a session.
	 *
	 * @param string $session_id Session ID.
	 * @param int    $product_id Product ID.
	 * @param float  $revenue    Revenue amount.
	 * @return void
	 */
	public function track_purchase_if_attributed( string $session_id, int $product_id, float $revenue ): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		if ( '' === trim( $session_id ) || $product_id <= 0 ) {
			return;
		}

		$days = (int) $this->settings->get( 'analytics.attribution_days', 14 );
		$days = min( max( $days, 7 ), 30 );

		global $wpdb;
		$table = $wpdb->prefix . 'kivor_conversion_events';

		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$found = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE session_id = %s AND product_id = %d AND event_type = %s AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
				$session_id,
				$product_id,
				'recommended',
				$days
			)
		);

		if ( $found > 0 ) {
			$this->track_event( $session_id, $product_id, 'purchased', '', $revenue );
		}
	}
}
