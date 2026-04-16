<?php

/**
 * Kivor Chat Agent
 *
 * @package           KivorAgent
 * @author            ifeanyiachi
 * @copyright         2025 Kivor
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       Kivor Chat Agent
 * Plugin URI:        https://kivorsuite.com/kivor-chat-agent
 * Description:       Kivor Chat Agent brings AI-powered support and discovery to WordPress websites
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Tested up to:      6.9
 * Author:            ifeanyiachi
 * Author URI:        https://ifeanyiachi.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       kivor-chat-agent
 * Domain Path:       /languages
 * WC requires at least: 7.1
 * WC tested up to:   9.6
 */
// Prevent direct access.
defined( 'ABSPATH' ) || exit;
if ( !function_exists( 'kca_fs' ) ) {
    // Create a helper function for easy SDK access.
    function kca_fs() {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
        global $kca_fs;
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
        if ( !isset( $kca_fs ) ) {
            // Include Freemius SDK.
            require_once dirname( __FILE__ ) . '/vendor/freemius/start.php';
            $kca_fs = fs_dynamic_init( array(
                'id'               => '27620',
                'slug'             => 'kivor-chat-agent',
                'premium_slug'     => 'ksoc-pro',
                'type'             => 'plugin',
                'public_key'       => 'pk_7822fa73becea6adb859e58a770b1',
                'is_premium'       => false,
                'premium_suffix'   => 'Pro',
                'has_addons'       => false,
                'has_paid_plans'   => true,
                'is_org_compliant' => true,
                'menu'             => array(
                    'slug'    => 'kivor-chat-agent',
                    'contact' => false,
                    'support' => false,
                ),
                'is_live'          => true,
            ) );
        }
        return $kca_fs;
    }

    // Init Freemius.
    kca_fs();
    // Signal that SDK was initiated.
    do_action( 'kca_fs_loaded' );
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
}
/**
 * Prevent loading multiple copies of Kivor Chat Agent.
 *
 * If another plugin already defined our bootstrap constants from a different
 * path, this copy must bail to avoid constant/class collisions.
 */
if ( defined( 'KIVOR_AGENT_FILE' ) && realpath( (string) KIVOR_AGENT_FILE ) !== realpath( __FILE__ ) ) {
    if ( is_admin() ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__( 'Kivor Chat Agent detected another active copy of the plugin. Please deactivate the duplicate plugin before activating this one.', 'kivor-chat-agent' );
            echo '</p></div>';
        } );
    }
    return;
}
/**
 * Plugin constants.
 */
if ( !defined( 'KIVOR_AGENT_VERSION' ) ) {
    define( 'KIVOR_AGENT_VERSION', '1.0.0' );
}
if ( !defined( 'KIVOR_AGENT_DB_VERSION' ) ) {
    define( 'KIVOR_AGENT_DB_VERSION', '1.4.1' );
}
if ( !defined( 'KIVOR_AGENT_FILE' ) ) {
    define( 'KIVOR_AGENT_FILE', __FILE__ );
}
if ( !defined( 'KIVOR_AGENT_PATH' ) ) {
    define( 'KIVOR_AGENT_PATH', plugin_dir_path( __FILE__ ) );
}
if ( !defined( 'KIVOR_AGENT_URL' ) ) {
    define( 'KIVOR_AGENT_URL', plugin_dir_url( __FILE__ ) );
}
if ( !defined( 'KIVOR_AGENT_BASENAME' ) ) {
    define( 'KIVOR_AGENT_BASENAME', plugin_basename( __FILE__ ) );
}
/**
 * Autoloader for plugin classes.
 *
 * Maps class names to file paths using the following convention:
 * - Class: Kivor_Agent        -> includes/class-kivor-chat-agent.php
 * - Class: Kivor_AI_Provider  -> includes/ai/class-kivor-ai-provider.php
 */
spl_autoload_register( function ( $class_name ) {
    // Only handle our plugin classes.
    if ( 0 !== strpos( $class_name, 'Kivor_' ) ) {
        return;
    }
    // Convert class name to file path.
    $file_name = 'class-' . str_replace( '_', '-', strtolower( $class_name ) ) . '.php';
    // Define subdirectory mappings based on class prefix.
    $subdirectories = array(
        'Kivor_AI_'         => 'ai',
        'Kivor_Embedding_'  => 'embeddings',
        'Kivor_Knowledge_'  => 'knowledge',
        'Kivor_Search_'     => 'search',
        'Kivor_Hybrid_'     => 'search',
        'Kivor_Product_'    => 'search',
        'Kivor_GDPR'        => 'gdpr',
        'Kivor_Consent_'    => 'gdpr',
        'Kivor_Data_'       => 'gdpr',
        'Kivor_Admin'       => 'admin',
        'Kivor_Rest_'       => 'api',
        'Kivor_Voice_'      => 'voice',
        'Kivor_Rate_'       => 'utils',
        'Kivor_Logger'      => 'utils',
        'Kivor_Sanitizer'   => 'utils',
        'Kivor_Local_'      => 'embeddings',
        'Kivor_Pinecone_'   => 'embeddings',
        'Kivor_Qdrant_'     => 'embeddings',
        'Kivor_Vector_'     => 'embeddings',
        'Kivor_Sync_'       => 'embeddings',
        'Kivor_Semantic_'   => 'embeddings',
        'Kivor_Web_'        => 'knowledge',
        'Kivor_Form_'       => 'forms',
        'Kivor_External_'   => 'knowledge',
        'Kivor_WP_'         => 'knowledge',
        'Kivor_Zendesk_'    => 'knowledge/sources',
        'Kivor_Notion_'     => 'knowledge/sources',
        'Kivor_Analytics'   => 'analytics',
        'Kivor_Sentiment_'  => 'analytics',
        'Kivor_Conversion_' => 'analytics',
        'Kivor_Frontend'    => '',
    );
    $subdir = '';
    foreach ( $subdirectories as $prefix => $dir ) {
        if ( 0 === strpos( $class_name, $prefix ) ) {
            $subdir = $dir;
            break;
        }
    }
    if ( !empty( $subdir ) ) {
        $file_path = KIVOR_AGENT_PATH . 'includes/' . $subdir . '/' . $file_name;
    } else {
        $file_path = KIVOR_AGENT_PATH . 'includes/' . $file_name;
    }
    if ( file_exists( $file_path ) ) {
        require_once $file_path;
    }
} );
/**
 * Declare HPOS (High-Performance Order Storage) compatibility.
 *
 * Kivor Chat Agent only interacts with WooCommerce products (not orders),
 * so it is fully compatible with HPOS.
 */
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );
/**
 * Run activation hook.
 */
register_activation_hook( __FILE__, function () {
    require_once __DIR__ . '/includes/class-kivor-activator.php';
    Kivor_Activator::activate();
} );
/**
 * Run deactivation hook.
 */
register_deactivation_hook( __FILE__, function () {
    require_once __DIR__ . '/includes/class-kivor-deactivator.php';
    Kivor_Deactivator::deactivate();
} );
/**
 * Cleanup data after Freemius reports plugin uninstall.
 */
function kca_after_uninstall_cleanup() {
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
    global $wpdb;
    $settings = get_option( 'kivor_chat_agent_settings', array() );
    $total_uninstall_enabled = !empty( $settings['general']['total_uninstall'] );
    if ( !$total_uninstall_enabled ) {
        return;
    }
    $tables = array(
        $wpdb->prefix . 'kivor_chat_logs',
        $wpdb->prefix . 'kivor_conversion_events',
        $wpdb->prefix . 'kivor_embeddings',
        $wpdb->prefix . 'kivor_knowledge_base',
        $wpdb->prefix . 'kivor_consent_log',
        $wpdb->prefix . 'kivor_forms',
        $wpdb->prefix . 'kivor_form_submissions'
    );
    foreach ( $tables as $table ) {
        $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }
    delete_option( 'kivor_chat_agent_settings' );
    delete_option( 'kivor_chat_agent_db_version' );
    delete_transient( 'kivor_chat_agent_sync_progress' );
    delete_transient( 'kivor_chat_agent_sync_status' );
    wp_unschedule_hook( 'kivor_chat_agent_daily_cleanup' );
    wp_unschedule_hook( 'kivor_chat_agent_sync_product' );
    wp_unschedule_hook( 'kivor_chat_agent_delete_embedding' );
    wp_unschedule_hook( 'kivor_chat_agent_external_sync_hourly' );
    wp_unschedule_hook( 'kivor_chat_agent_external_sync_daily' );
    $wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'kivor_chat_agent_%'" );
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

kca_fs()->add_action( 'after_uninstall', 'kca_after_uninstall_cleanup' );
/**
 * Load translations.
 *
 * Must run on init or later to avoid early textdomain notices in WP 6.7+.
 */
add_action( 'init', function () {
    load_plugin_textdomain( 'kivor-chat-agent', false, dirname( KIVOR_AGENT_BASENAME ) . '/languages' );
    // phpcs:ignore WordPress.WP.DeprecatedFunctions.load_plugin_textdomainFound -- Needed for WP < 6.7 compat
}, 1 );
/**
 * Check database schema upgrades once plugins are loaded.
 */
add_action( 'plugins_loaded', function () {
    $installed_db_version = get_option( 'kivor_chat_agent_db_version', '0' );
    if ( version_compare( $installed_db_version, KIVOR_AGENT_DB_VERSION, '<' ) ) {
        require_once __DIR__ . '/includes/class-kivor-activator.php';
        Kivor_Activator::create_tables();
        update_option( 'kivor_chat_agent_db_version', KIVOR_AGENT_DB_VERSION );
    }
} );
/**
 * Begin plugin execution.
 *
 * WooCommerce is optional — product features activate only when WC is detected.
 * The plugin works as a general WordPress chatbot without WooCommerce.
 */
add_action( 'init', function () {
    Kivor_Agent::instance();
}, 20 );