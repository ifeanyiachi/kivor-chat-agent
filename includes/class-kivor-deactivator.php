<?php
/**
 * Plugin deactivator.
 *
 * Handles cleanup on plugin deactivation. Does NOT remove data
 * (that happens in uninstall.php).
 *
 * @package KivorAgent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class Kivor_Deactivator {

    /**
     * Run deactivation tasks.
     */
    public static function deactivate(): void {
        self::clear_cron_schedules();
        self::flush_transients();
    }

    /**
     * Clear all scheduled cron events.
     */
    private static function clear_cron_schedules(): void {
        // Remove daily cleanup cron.
        $timestamp = wp_next_scheduled( 'kivor_chat_agent_daily_cleanup' );
        if ( false !== $timestamp ) {
            wp_unschedule_event( $timestamp, 'kivor_chat_agent_daily_cleanup' );
        }

		// Remove any pending single-event syncs.
		wp_unschedule_hook( 'kivor_chat_agent_sync_product' );
		wp_unschedule_hook( 'kivor_chat_agent_delete_embedding' );
		wp_unschedule_hook( 'kivor_chat_agent_external_sync_hourly' );
		wp_unschedule_hook( 'kivor_chat_agent_external_sync_daily' );
	}

    /**
     * Flush plugin transients.
     */
    private static function flush_transients(): void {
        delete_transient( 'kivor_chat_agent_sync_progress' );
        delete_transient( 'kivor_chat_agent_sync_status' );
    }
}
