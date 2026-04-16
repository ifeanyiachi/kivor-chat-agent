<?php
/**
 * External platform sync service.
 *
 * @package KivorAgent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class Kivor_External_Platform_Sync {

	/**
	 * Settings.
	 *
	 * @var Kivor_Settings
	 */
	private Kivor_Settings $settings;

	/**
	 * KB service.
	 *
	 * @var Kivor_Knowledge_Base
	 */
	private Kivor_Knowledge_Base $kb;

	/**
	 * Constructor.
	 *
	 * @param Kivor_Settings       $settings Settings.
	 * @param Kivor_Knowledge_Base $kb       Knowledge base service.
	 */
	public function __construct( Kivor_Settings $settings, Kivor_Knowledge_Base $kb ) {
		$this->settings = $settings;
		$this->kb       = $kb;
	}

	/**
	 * Register cron hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'kivor_chat_agent_external_sync_hourly', array( $this, 'run_hourly_sync' ) );
		add_action( 'kivor_chat_agent_external_sync_daily', array( $this, 'run_daily_sync' ) );

		$this->schedule_cron_events();
	}

	/**
	 * Run hourly sync.
	 *
	 * @return void
	 */
	public function run_hourly_sync(): void {
		foreach ( $this->get_enabled_platforms_by_trigger( 'hourly' ) as $platform ) {
			$this->sync_platform( $platform, null, false );
		}
	}

	/**
	 * Run daily sync.
	 *
	 * @return void
	 */
	public function run_daily_sync(): void {
		foreach ( $this->get_enabled_platforms_by_trigger( 'daily' ) as $platform ) {
			$this->sync_platform( $platform, null, false );
		}
	}

	/**
	 * Sync a platform now.
	 *
	 * @param string      $platform   Platform key.
	 * @param string|null $sync_mode  Optional explicit mode.
	 * @param bool        $allow_full Whether to allow full cleanup mode.
	 * @return array<string, mixed>|WP_Error
	 */
	public function sync_platform( string $platform, ?string $sync_mode = null, bool $allow_full = true ) {
		$platform = sanitize_key( $platform );
		$articles = $this->fetch_platform_articles( $platform );

		if ( is_wp_error( $articles ) ) {
			$this->set_platform_sync_status( $platform, false, $articles->get_error_message(), array() );
			return $articles;
		}

		$cfg = $this->settings->get( 'external_platforms.' . $platform, array() );
		$mode = $sync_mode ? sanitize_key( $sync_mode ) : sanitize_key( (string) ( $cfg['sync_mode'] ?? 'incremental' ) );
		if ( ! in_array( $mode, array( 'full', 'incremental' ), true ) ) {
			$mode = 'incremental';
		}

		$source_types = $this->get_platform_source_types( $platform );

		if ( 'full' === $mode && $allow_full ) {
			$this->kb->delete_articles_by_source_types( $source_types );
		}

		$result = $this->kb->upsert_external_articles( $articles, 'incremental' === $mode );

		if ( is_wp_error( $result ) ) {
			$this->set_platform_sync_status( $platform, false, $result->get_error_message(), array() );
			return $result;
		}

		$deleted = 0;
		if ( 'incremental' === $mode ) {
			$deleted = (int) $this->kb->delete_missing_external_articles( $source_types, $articles );
		}

		$counts = array(
			'created' => (int) ( $result['created'] ?? 0 ),
			'updated' => (int) ( $result['updated'] ?? 0 ),
			'skipped' => (int) ( $result['skipped'] ?? 0 ),
			'errors'  => (int) ( $result['errors'] ?? 0 ),
			'deleted' => $deleted,
			'fetched' => count( $articles ),
		);

		$message = sprintf(
			/* translators: 1: created, 2: updated, 3: skipped, 4: errors */
			__( 'Created %1$d, updated %2$d, skipped %3$d, errors %4$d.', 'kivor-chat-agent' ),
			$counts['created'],
			$counts['updated'],
			$counts['skipped'],
			$counts['errors']
		);

		$this->set_platform_sync_status( $platform, true, $message, $counts );

		return array(
			'platform' => $platform,
			'mode'     => $mode,
			'count'    => count( $articles ),
			'result'   => array_merge( $result, array( 'deleted' => $deleted ) ),
		);
	}

	/**
	 * Test a platform connection.
	 *
	 * @param string $platform Platform key.
	 * @param array  $config   Source config.
	 * @return true|WP_Error
	 */
	public function test_platform_connection( string $platform, array $config ) {
		$source = $this->create_source( sanitize_key( $platform ), $config );

		if ( is_wp_error( $source ) ) {
			$this->set_platform_test_status( $platform, false, $source->get_error_message() );
			return $source;
		}

		$result = $source->test_connection();

		if ( is_wp_error( $result ) ) {
			$this->set_platform_test_status( $platform, false, $result->get_error_message() );
			return $result;
		}

		$this->set_platform_test_status( $platform, true, __( 'Connection successful.', 'kivor-chat-agent' ) );

		return $result;
	}

	/**
	 * Persist sync status for one platform.
	 *
	 * @param string $platform Platform key.
	 * @param bool   $ok       Success.
	 * @param string $message  Message.
	 * @param array  $counts   Count details.
	 * @return void
	 */
	private function set_platform_sync_status( string $platform, bool $ok, string $message, array $counts ): void {
		$all = $this->settings->get_all();
		if ( empty( $all['external_platforms'][ $platform ] ) || ! is_array( $all['external_platforms'][ $platform ] ) ) {
			return;
		}

		$safe_counts = array();
		foreach ( $counts as $key => $value ) {
			$safe_counts[ sanitize_key( (string) $key ) ] = (int) $value;
		}

		$all['external_platforms'][ $platform ]['last_sync_at']      = gmdate( 'c' );
		$all['external_platforms'][ $platform ]['last_sync_ok']      = $ok;
		$all['external_platforms'][ $platform ]['last_sync_message'] = sanitize_text_field( $message );
		$all['external_platforms'][ $platform ]['last_sync_counts']  = $safe_counts;

		$this->settings->update_all( $all );
	}

	/**
	 * Persist test status for one platform.
	 *
	 * @param string $platform Platform key.
	 * @param bool   $ok       Success.
	 * @param string $message  Message.
	 * @return void
	 */
	private function set_platform_test_status( string $platform, bool $ok, string $message ): void {
		$all = $this->settings->get_all();
		if ( empty( $all['external_platforms'][ $platform ] ) || ! is_array( $all['external_platforms'][ $platform ] ) ) {
			return;
		}

		$all['external_platforms'][ $platform ]['last_test_at']      = gmdate( 'c' );
		$all['external_platforms'][ $platform ]['last_test_ok']      = $ok;
		$all['external_platforms'][ $platform ]['last_test_message'] = sanitize_text_field( $message );

		$this->settings->update_all( $all );
	}

	/**
	 * Get enabled platforms for a trigger.
	 *
	 * @param string $trigger Trigger.
	 * @return array<int, string>
	 */
	private function get_enabled_platforms_by_trigger( string $trigger ): array {
		$all = $this->settings->get( 'external_platforms', array() );
		$platforms = array( 'wordpress', 'zendesk', 'notion' );
		$enabled = array();

		foreach ( $platforms as $platform ) {
			$cfg = isset( $all[ $platform ] ) && is_array( $all[ $platform ] ) ? $all[ $platform ] : array();
			if ( empty( $cfg['enabled'] ) ) {
				continue;
			}
			if ( sanitize_key( (string) ( $cfg['trigger'] ?? 'manual' ) ) !== $trigger ) {
				continue;
			}

			$enabled[] = $platform;
		}

		return $enabled;
	}

	/**
	 * Fetch articles for one platform.
	 *
	 * @param string $platform Platform.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	private function fetch_platform_articles( string $platform ) {
		if ( ! in_array( $platform, array( 'wordpress', 'zendesk', 'notion' ), true ) ) {
			return new \WP_Error(
				'kivor_chat_agent_unknown_external_platform',
				sprintf(
					/* translators: %s: platform key */
					__( 'Unknown external platform: %s', 'kivor-chat-agent' ),
					$platform
				)
			);
		}

		if ( 'wordpress' === $platform ) {
			$wp_sync = new Kivor_WP_Content_Sync( $this->settings );
			return $wp_sync->fetch_all_articles();
		}

		$cfg = $this->settings->get( 'external_platforms.' . $platform, array() );
		$cfg = is_array( $cfg ) ? $cfg : array();

		$source = $this->create_source( $platform, $cfg );

		if ( is_wp_error( $source ) ) {
			return $source;
		}

		return $source->fetch_articles();
	}

	/**
	 * Create source instance.
	 *
	 * @param string $platform Platform.
	 * @param array  $config   Config.
	 * @return Kivor_Knowledge_Source_Interface|WP_Error
	 */
	private function create_source( string $platform, array $config ) {
			switch ( $platform ) {
			case 'zendesk':
				return new Kivor_Zendesk_Source( $config );
			case 'notion':
				return new Kivor_Notion_Source( $config );
		}

		return new \WP_Error(
			'kivor_chat_agent_unknown_external_platform',
			sprintf(
				/* translators: %s: platform key */
				__( 'Unknown external platform: %s', 'kivor-chat-agent' ),
				$platform
			)
		);
	}

	/**
	 * Source types for platform.
	 *
	 * @param string $platform Platform.
	 * @return array<int, string>
	 */
	private function get_platform_source_types( string $platform ): array {
			switch ( $platform ) {
			case 'wordpress':
				return array( 'wp_post', 'wp_page' );
			case 'zendesk':
				return array( 'zendesk' );
			case 'notion':
				return array( 'notion' );
		}

		return array();
	}

	/**
	 * Schedule cron events if missing.
	 *
	 * @return void
	 */
	private function schedule_cron_events(): void {
		if ( ! wp_next_scheduled( 'kivor_chat_agent_external_sync_hourly' ) ) {
			wp_schedule_event( time(), 'hourly', 'kivor_chat_agent_external_sync_hourly' );
		}

		if ( ! wp_next_scheduled( 'kivor_chat_agent_external_sync_daily' ) ) {
			wp_schedule_event( time(), 'daily', 'kivor_chat_agent_external_sync_daily' );
		}
	}
}
