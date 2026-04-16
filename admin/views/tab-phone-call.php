<?php
/**
 * Phone Call tab.
 *
 * @package KivorAgent
 * @since   1.1.0
 * @var array $settings All plugin settings.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$phone = $settings['phone_call'] ?? array();
$phone_locked = Kivor_Feature_Gates::is_feature_available( 'phone_call' ) ? false : true;
?>

<?php if ( $phone_locked ) : ?>
	<?php
	Kivor_Feature_Gates::render_lock_notice(
		__( 'Phone Call Is Available in Pro', 'admin' ),
		''
	);
	?>
<?php endif; ?>

<form method="post" action="">
	<?php wp_nonce_field( 'kivor_chat_agent_save_phone_call', '_kivor_chat_agent_nonce' ); ?>
	<input type="hidden" name="kivor_chat_agent_save_settings" value="phone_call">

	<h2><?php esc_html_e( 'Phone Call', 'admin' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Show a floating call icon stacked above the chat icon on mobile.', 'admin' ); ?></p>

	<fieldset <?php disabled( $phone_locked ); ?>>
	<table class="form-table">
		<tr>
			<th scope="row"><?php esc_html_e( 'Enable Call Icon', 'admin' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="phone_call_enabled" value="1" <?php checked( ! empty( $phone['enabled'] ) ); ?>>
					<?php esc_html_e( 'Show call icon on frontend', 'admin' ); ?>
				</label>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Mobile Only', 'admin' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="phone_call_mobile_only" value="1" <?php checked( ! empty( $phone['mobile_only'] ) ); ?>>
					<?php esc_html_e( 'Display call icon only on mobile (<= 480px)', 'admin' ); ?>
				</label>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="phone_call_number"><?php esc_html_e( 'Phone Number', 'admin' ); ?></label>
			</th>
			<td>
				<input type="text" id="phone_call_number" name="phone_call_number" value="<?php echo esc_attr( $phone['number'] ?? '' ); ?>" class="regular-text" placeholder="+1234567890">
				<p class="description"><?php esc_html_e( 'Use international format. This opens tel: link on mobile.', 'admin' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="phone_call_button_label"><?php esc_html_e( 'Button Label', 'admin' ); ?></label>
			</th>
			<td>
				<input type="text" id="phone_call_button_label" name="phone_call_button_label" value="<?php echo esc_attr( $phone['button_label'] ?? 'Call Support' ); ?>" class="regular-text" maxlength="30">
			</td>
		</tr>
	</table>

	<?php submit_button( __( 'Save Phone Call Settings', 'admin' ) ); ?>
	</fieldset>
</form>
