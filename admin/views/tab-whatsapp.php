<?php
/**
 * WhatsApp tab.
 *
 * @package KivorAgent
 * @since   1.0.0
 * @var array $settings All plugin settings.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$wa = $settings['whatsapp'];
?>

<form method="post" action="">
	<?php wp_nonce_field( 'kivor_chat_agent_save_whatsapp', '_kivor_chat_agent_nonce' ); ?>
	<input type="hidden" name="kivor_chat_agent_save_settings" value="whatsapp">

	<h2><?php esc_html_e( 'WhatsApp Chat Redirect', 'admin' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Enable a WhatsApp tab in the chat widget. Users type a message, then clicking Send opens WhatsApp with the pre-filled message.', 'admin' ); ?></p>

	<table class="form-table">
		<tr>
			<th scope="row"><?php esc_html_e( 'Enable WhatsApp', 'admin' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="whatsapp_enabled" value="1" <?php checked( $wa['enabled'] ); ?>>
					<?php esc_html_e( 'Show WhatsApp tab in the chat widget', 'admin' ); ?>
				</label>
			</td>
		</tr>
	</table>

	<div id="kivor-chat-agent-whatsapp-fields" <?php echo ! $wa['enabled'] ? 'style="display:none;"' : ''; ?>>
		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="whatsapp_name"><?php esc_html_e( 'Agent Name', 'admin' ); ?></label>
				</th>
				<td>
					<input type="text" id="whatsapp_name" name="whatsapp_name" value="<?php echo esc_attr( $wa['name'] ); ?>" class="regular-text">
					<p class="description"><?php esc_html_e( 'Name displayed in the WhatsApp tab header.', 'admin' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="whatsapp_number"><?php esc_html_e( 'Phone Number', 'admin' ); ?></label>
				</th>
				<td>
					<input type="text" id="whatsapp_number" name="whatsapp_number" value="<?php echo esc_attr( $wa['number'] ); ?>" class="regular-text" placeholder="+1234567890">
					<p class="description"><?php esc_html_e( 'Full international phone number with country code (e.g., +1234567890).', 'admin' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="whatsapp_prefilled_message"><?php esc_html_e( 'Pre-filled Message', 'admin' ); ?></label>
				</th>
				<td>
					<textarea id="whatsapp_prefilled_message" name="whatsapp_prefilled_message" rows="3" class="large-text"><?php echo esc_textarea( $wa['prefilled_message'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Default message shown in the text input.', 'admin' ); ?></p>
				</td>
			</tr>
		</table>
	</div>

	<?php submit_button( __( 'Save WhatsApp Settings', 'admin' ) ); ?>
</form>
