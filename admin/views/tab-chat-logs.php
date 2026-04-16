<?php
/**
 * Chat Logs tab.
 *
 * Displays chat log entries with session/date filters,
 * Export CSV and Clear All action buttons.
 *
 * @package KivorAgent
 * @since   1.0.0
 * @var array $settings All plugin settings.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

// Load list table class.
if ( ! class_exists( 'Kivor_Logs_List_Table' ) ) {
	require_once KIVOR_AGENT_PATH . 'admin/class-kivor-logs-list-table.php';
}

$logs_table = new Kivor_Logs_List_Table();
$logs_table->prepare_items();

$chat_logs_settings = $settings['chat_logs'];
$insights_locked = Kivor_Feature_Gates::is_feature_available( 'analytics_insights' ) ? false : true;
?>

<p class="description"><?php esc_html_e( 'View and manage conversation insights. Data is stored in the database and can be exported as CSV.', 'admin' ); ?></p>

<?php if ( $insights_locked ) : ?>
	<?php
	Kivor_Feature_Gates::render_lock_notice(
		__( 'Insights Are Available in Pro', 'admin' ),
		''
	);
	?>
<?php endif; ?>

<!-- Chat Log Settings -->
<form method="post" action="">
	<?php wp_nonce_field( 'kivor_chat_agent_save_chat_logs', '_kivor_chat_agent_nonce' ); ?>
	<input type="hidden" name="kivor_chat_agent_save_settings" value="chat_logs">

	<fieldset <?php disabled( $insights_locked ); ?>>
	<table class="form-table">
		<tr>
			<th scope="row"><?php esc_html_e( 'Enable Logging', 'admin' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="logging_enabled" value="1" <?php checked( $chat_logs_settings['logging_enabled'] ); ?>>
					<?php esc_html_e( 'Log chat conversations to the database', 'admin' ); ?>
				</label>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="auto_cleanup_days"><?php esc_html_e( 'Auto Cleanup', 'admin' ); ?></label>
			</th>
			<td>
				<input type="number" id="auto_cleanup_days" name="auto_cleanup_days" value="<?php echo esc_attr( $chat_logs_settings['auto_cleanup_days'] ); ?>" min="1" max="365" style="width:100px;">
				<span class="description"><?php esc_html_e( 'days (auto-delete logs older than this)', 'admin' ); ?></span>
			</td>
		</tr>
	</table>

	<?php submit_button( __( 'Save Log Settings', 'admin' ) ); ?>
	</fieldset>
</form>

<hr>

<!-- Action Buttons -->
<div class="kivor-chat-agent-actions-row">
	<button type="button" id="kivor-chat-agent-export-csv" class="button" <?php disabled( $insights_locked ); ?>>
		<?php esc_html_e( 'Export CSV', 'admin' ); ?>
	</button>
	<span id="kivor-chat-agent-clear-logs-wrap">
		<button type="button" id="kivor-chat-agent-clear-logs" class="button button-link-delete" <?php disabled( $insights_locked ); ?>>
			<?php esc_html_e( 'Clear All Logs', 'admin' ); ?>
		</button>
	</span>
</div>

<!-- Logs Table (includes filter bar via extra_tablenav) -->
<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
	<input type="hidden" name="page" value="kivor-chat-agent-insights">
	<input type="hidden" name="insights_tab" value="logs">
	<?php $logs_table->display(); ?>
</form>
