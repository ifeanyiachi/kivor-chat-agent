<?php
/**
 * External platforms tab.
 *
 * @package KivorAgent
 * @since   1.0.0
 * @var array $settings All plugin settings.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$ext = $settings['external_platforms'] ?? array();

$wp_cfg       = $ext['wordpress'] ?? array();
$zendesk_cfg  = $ext['zendesk'] ?? array();
$notion_cfg   = $ext['notion'] ?? array();
$content_opts = $ext['content_options'] ?? array();
$zendesk_locked = Kivor_Feature_Gates::is_feature_available( 'knowledge_zendesk' ) ? false : true;
$notion_locked  = Kivor_Feature_Gates::is_feature_available( 'knowledge_notion' ) ? false : true;

$sync_modes = array(
	'incremental' => __( 'Incremental', 'admin' ),
	'full'        => __( 'Full', 'admin' ),
);

$triggers = array(
	'on_save' => __( 'On save', 'admin' ),
	'hourly'  => __( 'Hourly', 'admin' ),
	'daily'   => __( 'Daily', 'admin' ),
	'manual'  => __( 'Manual', 'admin' ),
);

$render_platform_status = static function ( array $cfg ) {
	$last_sync_at      = (string) ( $cfg['last_sync_at'] ?? '' );
	$last_sync_ok      = ! empty( $cfg['last_sync_ok'] );
	$last_sync_message = (string) ( $cfg['last_sync_message'] ?? '' );
	$last_sync_counts  = isset( $cfg['last_sync_counts'] ) && is_array( $cfg['last_sync_counts'] ) ? $cfg['last_sync_counts'] : array();

	$last_test_at      = (string) ( $cfg['last_test_at'] ?? '' );
	$last_test_ok      = ! empty( $cfg['last_test_ok'] );
	$last_test_message = (string) ( $cfg['last_test_message'] ?? '' );

	echo '<div class="kivor-chat-agent-external-status">';

	if ( '' !== $last_test_at || '' !== $last_sync_at ) {
		echo '<p class="description"><strong>' . esc_html__( 'Last Test:', 'admin' ) . '</strong> ';
		if ( '' !== $last_test_at ) {
			echo esc_html( gmdate( 'Y-m-d H:i:s', strtotime( $last_test_at ) ) . ' UTC' ) . ' - ';
			echo '<span class="' . esc_attr( $last_test_ok ? 'kivor-chat-agent-status-ok' : 'kivor-chat-agent-status-fail' ) . '">' . esc_html( $last_test_ok ? __( 'Success', 'admin' ) : __( 'Failed', 'admin' ) ) . '</span>';
			if ( '' !== $last_test_message ) {
				echo ' - ' . esc_html( $last_test_message );
			}
		} else {
			echo esc_html__( 'Never tested.', 'admin' );
		}
		echo '</p>';

		echo '<p class="description"><strong>' . esc_html__( 'Last Sync:', 'admin' ) . '</strong> ';
		if ( '' !== $last_sync_at ) {
			echo esc_html( gmdate( 'Y-m-d H:i:s', strtotime( $last_sync_at ) ) . ' UTC' ) . ' - ';
			echo '<span class="' . esc_attr( $last_sync_ok ? 'kivor-chat-agent-status-ok' : 'kivor-chat-agent-status-fail' ) . '">' . esc_html( $last_sync_ok ? __( 'Success', 'admin' ) : __( 'Failed', 'admin' ) ) . '</span>';
			if ( '' !== $last_sync_message ) {
				echo ' - ' . esc_html( $last_sync_message );
			}
			if ( ! empty( $last_sync_counts ) ) {
				echo '<br><span class="description">' . esc_html(
					sprintf(
						/* translators: 1: fetched, 2: created, 3: updated, 4: skipped, 5: deleted, 6: errors */
						__( 'Fetched: %1$d, Created: %2$d, Updated: %3$d, Skipped: %4$d, Deleted: %5$d, Errors: %6$d', 'admin' ),
						(int) ( $last_sync_counts['fetched'] ?? 0 ),
						(int) ( $last_sync_counts['created'] ?? 0 ),
						(int) ( $last_sync_counts['updated'] ?? 0 ),
						(int) ( $last_sync_counts['skipped'] ?? 0 ),
						(int) ( $last_sync_counts['deleted'] ?? 0 ),
						(int) ( $last_sync_counts['errors'] ?? 0 )
					)
				) . '</span>';
			}
		} else {
			echo esc_html__( 'Never synced.', 'admin' );
		}
		echo '</p>';
	}

	echo '</div>';
};
?>

<form method="post" action="">
	<?php wp_nonce_field( 'kivor_chat_agent_save_external_platforms', '_kivor_chat_agent_nonce' ); ?>
	<input type="hidden" name="kivor_chat_agent_save_settings" value="external_platforms">

	<h2><?php esc_html_e( 'External Platforms', 'admin' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Sync knowledge base content from WordPress and third-party platforms.', 'admin' ); ?></p>

	<?php if ( $zendesk_locked || $notion_locked ) : ?>
		<?php
		Kivor_Feature_Gates::render_lock_notice(
			__( 'Premium Knowledge Sources', 'admin' ),
			__( 'Zendesk and Notion integrations are visible but locked on the free plan. WordPress posts and pages remain available.', 'admin' )
		);
		?>
	<?php endif; ?>

	<details class="kivor-chat-agent-external-platform" open>
		<summary><?php esc_html_e( 'WordPress Content', 'admin' ); ?></summary>
			<div class="kivor-chat-agent-external-platform__body">
				<?php $render_platform_status( $wp_cfg ); ?>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable', 'admin' ); ?></th>
					<td>
						<label><input type="checkbox" name="ext_wp_enabled" value="1" <?php checked( ! empty( $wp_cfg['enabled'] ) ); ?>> <?php esc_html_e( 'Enable WordPress sync', 'admin' ); ?></label><br>
						<label><input type="checkbox" name="ext_wp_posts_enabled" value="1" <?php checked( ! empty( $wp_cfg['posts_enabled'] ) ); ?>> <?php esc_html_e( 'Posts', 'admin' ); ?></label>
						<label style="margin-left:12px;"><input type="checkbox" name="ext_wp_pages_enabled" value="1" <?php checked( ! empty( $wp_cfg['pages_enabled'] ) ); ?>> <?php esc_html_e( 'Pages', 'admin' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ext_wp_sync_mode"><?php esc_html_e( 'Sync Mode', 'admin' ); ?></label></th>
					<td>
						<select id="ext_wp_sync_mode" name="ext_wp_sync_mode">
							<?php foreach ( $sync_modes as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $wp_cfg['sync_mode'] ?? 'incremental', $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ext_wp_trigger"><?php esc_html_e( 'Trigger', 'admin' ); ?></label></th>
					<td>
						<select id="ext_wp_trigger" name="ext_wp_trigger">
							<?php foreach ( $triggers as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $wp_cfg['trigger'] ?? 'daily', $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			</table>

			<div class="kivor-chat-agent-actions-row">
				<div id="kivor-chat-agent-external-result-wordpress" class="kivor-chat-agent-test-result"></div>
			</div>
		</div>
	</details>

	<?php
	$cards = array(
		'zendesk' => array(
			'label' => __( 'Zendesk Help Center', 'admin' ),
			'fields' => array(
				array( 'name' => 'ext_zendesk_subdomain', 'label' => __( 'Subdomain or Host', 'admin' ), 'type' => 'text', 'value' => $zendesk_cfg['subdomain'] ?? '' ),
				array( 'name' => 'ext_zendesk_email', 'label' => __( 'Email', 'admin' ), 'type' => 'email', 'value' => $zendesk_cfg['email'] ?? '' ),
				array( 'name' => 'ext_zendesk_api_token', 'label' => __( 'API Token', 'admin' ), 'type' => 'password', 'value' => Kivor_Admin::mask_key( (string) ( $zendesk_cfg['api_token'] ?? '' ) ) ),
			),
			'cfg' => $zendesk_cfg,
		),

		'notion' => array(
			'label' => __( 'Notion', 'admin' ),
			'fields' => array(
				array( 'name' => 'ext_notion_api_key', 'label' => __( 'API Key', 'admin' ), 'type' => 'password', 'value' => Kivor_Admin::mask_key( (string) ( $notion_cfg['api_key'] ?? '' ) ) ),
				array( 'name' => 'ext_notion_database_id', 'label' => __( 'Database ID', 'admin' ), 'type' => 'text', 'value' => $notion_cfg['database_id'] ?? '' ),
			),
			'cfg' => $notion_cfg,
		),

	);
	?>

	<?php foreach ( $cards as $slug => $card ) : ?>
		<?php $card_locked = ( 'zendesk' === $slug && $zendesk_locked ) || ( 'notion' === $slug && $notion_locked ); ?>
		<details class="kivor-chat-agent-external-platform">
			<summary><?php echo esc_html( $card['label'] ); ?></summary>
			<div class="kivor-chat-agent-external-platform__body">
				<fieldset <?php disabled( $card_locked ); ?>>
				<?php $render_platform_status( $card['cfg'] ); ?>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable', 'admin' ); ?></th>
						<td><label><input type="checkbox" name="ext_<?php echo esc_attr( $slug ); ?>_enabled" value="1" <?php checked( ! empty( $card['cfg']['enabled'] ) ); ?>> <?php esc_html_e( 'Enable sync', 'admin' ); ?></label></td>
					</tr>
					<?php foreach ( $card['fields'] as $field ) : ?>
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( $field['name'] ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
							<td><input type="<?php echo esc_attr( $field['type'] ); ?>" id="<?php echo esc_attr( $field['name'] ); ?>" name="<?php echo esc_attr( $field['name'] ); ?>" value="<?php echo esc_attr( (string) $field['value'] ); ?>" class="regular-text" <?php echo ! empty( $field['readonly'] ) ? 'readonly' : ''; ?>></td>
						</tr>
					<?php endforeach; ?>

					<tr>
						<th scope="row"><label for="ext_<?php echo esc_attr( $slug ); ?>_sync_mode"><?php esc_html_e( 'Sync Mode', 'admin' ); ?></label></th>
						<td>
							<select id="ext_<?php echo esc_attr( $slug ); ?>_sync_mode" name="ext_<?php echo esc_attr( $slug ); ?>_sync_mode">
								<?php foreach ( $sync_modes as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $card['cfg']['sync_mode'] ?? 'incremental', $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ext_<?php echo esc_attr( $slug ); ?>_trigger"><?php esc_html_e( 'Trigger', 'admin' ); ?></label></th>
						<td>
							<select id="ext_<?php echo esc_attr( $slug ); ?>_trigger" name="ext_<?php echo esc_attr( $slug ); ?>_trigger">
								<?php foreach ( $triggers as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $card['cfg']['trigger'] ?? 'manual', $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				</table>

				<div class="kivor-chat-agent-actions-row">
					<button type="button" class="button kivor-chat-agent-external-test" data-platform="<?php echo esc_attr( $slug ); ?>" <?php disabled( $card_locked ); ?>><?php esc_html_e( 'Test Connection', 'admin' ); ?></button>
					<div id="kivor-chat-agent-external-result-<?php echo esc_attr( $slug ); ?>" class="kivor-chat-agent-test-result"></div>
				</div>
				</fieldset>
			</div>
		</details>
	<?php endforeach; ?>

	<details class="kivor-chat-agent-external-platform">
		<summary><?php esc_html_e( 'Content Options', 'admin' ); ?></summary>
		<div class="kivor-chat-agent-external-platform__body">
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Split long articles by headers', 'admin' ); ?></th>
					<td><label><input type="checkbox" name="ext_content_split_by_headers" value="1" <?php checked( ! empty( $content_opts['split_by_headers'] ) ); ?>> <?php esc_html_e( 'Enable', 'admin' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Add read more link for truncated content', 'admin' ); ?></th>
					<td><label><input type="checkbox" name="ext_content_add_read_more" value="1" <?php checked( ! empty( $content_opts['add_read_more'] ) ); ?>> <?php esc_html_e( 'Enable', 'admin' ); ?></label></td>
				</tr>
			</table>
		</div>
	</details>

	<?php submit_button( __( 'Save Integration Settings', 'admin' ) ); ?>
</form>
