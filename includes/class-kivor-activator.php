<?php
/**
 * Plugin activator.
 *
 * Handles everything that runs on plugin activation: creating database tables,
 * setting default options, and scheduling cron jobs.
 *
 * @package KivorAgent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class Kivor_Activator {

    /**
     * Run activation tasks.
     */
    public static function activate(): void {
        self::check_requirements();
        self::create_tables();
        self::set_defaults();
        self::schedule_cron();

        // Store DB version for future migrations.
        update_option( 'kivor_chat_agent_db_version', KIVOR_AGENT_DB_VERSION );

        // Flush rewrite rules for any custom endpoints.
        flush_rewrite_rules();
    }

    /**
     * Check minimum requirements before activation.
     */
    private static function check_requirements(): void {
        if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
            deactivate_plugins( KIVOR_AGENT_BASENAME );
			wp_die(
				esc_html__( 'Kivor Chat Agent requires PHP 7.4 or higher.', 'kivor-chat-agent' ),
				'Plugin Activation Error',
				array( 'back_link' => true )
			);
        }

        if ( version_compare( get_bloginfo( 'version' ), '5.8', '<' ) ) {
            deactivate_plugins( KIVOR_AGENT_BASENAME );
			wp_die(
				esc_html__( 'Kivor Chat Agent requires WordPress 5.8 or higher.', 'kivor-chat-agent' ),
				'Plugin Activation Error',
				array( 'back_link' => true )
			);
        }
    }

    /**
     * Create all plugin database tables.
     *
     * Uses dbDelta for safe table creation and schema updates.
     */
    public static function create_tables(): void {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Chat logs table.
        $table_chat_logs = $wpdb->prefix . 'kivor_chat_logs';
        $sql_chat_logs   = "CREATE TABLE {$table_chat_logs} (
            id bigint(20) unsigned NOT NULL auto_increment,
            session_id varchar(64) NOT NULL default '',
            user_ip_hash varchar(64) NOT NULL default '',
            user_id bigint(20) unsigned default 0,
            role varchar(16) NOT NULL default '',
            message longtext NOT NULL,
            metadata longtext,
            sentiment varchar(16) default NULL,
            created_at datetime NOT NULL default CURRENT_TIMESTAMP,
            consent_given tinyint(1) NOT NULL default 0,
            PRIMARY KEY  (id),
            KEY session_id (session_id),
            KEY created_at (created_at),
            KEY user_id (user_id),
            KEY sentiment (sentiment)
        ) {$charset_collate};";
        dbDelta( $sql_chat_logs );

		// Conversion events table.
		$table_conversion_events = $wpdb->prefix . 'kivor_conversion_events';
		$sql_conversion_events   = "CREATE TABLE {$table_conversion_events} (
			id bigint(20) unsigned NOT NULL auto_increment,
			session_id varchar(64) NOT NULL default '',
			product_id bigint(20) unsigned NOT NULL default 0,
			event_type varchar(32) NOT NULL default '',
			source_url varchar(500) default '',
			revenue decimal(12,2) NOT NULL default 0.00,
			created_at datetime NOT NULL default CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY session_id (session_id),
			KEY product_id (product_id),
			KEY event_type (event_type),
			KEY created_at (created_at)
		) {$charset_collate};";
		dbDelta( $sql_conversion_events );

        // Embeddings table (local vector store).
        $table_embeddings = $wpdb->prefix . 'kivor_embeddings';
        $sql_embeddings   = "CREATE TABLE {$table_embeddings} (
            id bigint(20) unsigned NOT NULL auto_increment,
            object_type varchar(32) NOT NULL default '',
            object_id bigint(20) unsigned NOT NULL default 0,
            content_hash varchar(64) NOT NULL default '',
            embedding longblob,
            metadata longtext,
            updated_at datetime NOT NULL default CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY object_type (object_type),
            KEY object_id (object_id),
            UNIQUE KEY object_type_id (object_type,object_id)
        ) {$charset_collate};";
        dbDelta( $sql_embeddings );

        // Knowledge base table.
        $table_kb = $wpdb->prefix . 'kivor_knowledge_base';
		$sql_kb   = "CREATE TABLE {$table_kb} (
			id bigint(20) unsigned NOT NULL auto_increment,
			title varchar(255) NOT NULL default '',
			content text NOT NULL,
			source_type varchar(32) NOT NULL default 'manual',
			source_id varchar(191) NOT NULL default '',
			source_url varchar(500) default '',
			imported_at datetime NULL,
			last_synced_at datetime NULL,
			sync_status varchar(32) NOT NULL default 'synced',
			import_method varchar(32) NOT NULL default 'manual',
			sync_interval varchar(32) NOT NULL default 'manual',
			retry_count int(11) NOT NULL default 0,
			created_at datetime NOT NULL default CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL default CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY source_type (source_type),
			KEY source_id (source_id),
			KEY sync_status (sync_status)
		) {$charset_collate};";
		dbDelta( $sql_kb );

        // GDPR consent log table.
        $table_consent = $wpdb->prefix . 'kivor_consent_log';
        $sql_consent   = "CREATE TABLE {$table_consent} (
            id bigint(20) unsigned NOT NULL auto_increment,
            session_id varchar(64) NOT NULL default '',
            consent_type varchar(32) NOT NULL default '',
            consented tinyint(1) NOT NULL default 0,
            ip_hash varchar(64) NOT NULL default '',
            created_at datetime NOT NULL default CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY session_id (session_id)
        ) {$charset_collate};";
        dbDelta( $sql_consent );

		// Forms table.
		$table_forms = $wpdb->prefix . 'kivor_forms';
		$sql_forms   = "CREATE TABLE {$table_forms} (
			id bigint(20) unsigned NOT NULL auto_increment,
			name varchar(255) NOT NULL default '',
			fields longtext NOT NULL,
			trigger_instructions text,
			is_ai_eligible tinyint(1) NOT NULL default 1,
			is_primary tinyint(1) NOT NULL default 0,
			created_at datetime NOT NULL default CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL default CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY is_primary (is_primary)
		) {$charset_collate};";
		dbDelta( $sql_forms );

		// Form submissions table.
		$table_form_submissions = $wpdb->prefix . 'kivor_form_submissions';
		$sql_form_submissions   = "CREATE TABLE {$table_form_submissions} (
			id bigint(20) unsigned NOT NULL auto_increment,
			form_id bigint(20) unsigned NOT NULL,
			session_id varchar(255) NOT NULL default '',
			data longtext NOT NULL,
			created_at datetime NOT NULL default CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY form_id (form_id),
			KEY session_id (session_id),
			KEY created_at (created_at)
		) {$charset_collate};";
		dbDelta( $sql_form_submissions );
    }

    /**
     * Set default plugin options.
     */
    private static function set_defaults(): void {
        // Only set defaults if settings don't already exist (preserve on re-activation).
        if ( false === get_option( Kivor_Settings::OPTION_KEY ) ) {
            require_once KIVOR_AGENT_PATH . 'includes/class-kivor-settings.php';
            $settings = new Kivor_Settings();
            add_option( Kivor_Settings::OPTION_KEY, $settings->get_defaults(), '', true );
        }
    }

    /**
     * Schedule cron events.
     */
    private static function schedule_cron(): void {
        if ( ! wp_next_scheduled( 'kivor_chat_agent_daily_cleanup' ) ) {
            wp_schedule_event( time(), 'daily', 'kivor_chat_agent_daily_cleanup' );
        }
    }
}
