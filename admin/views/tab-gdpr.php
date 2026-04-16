<?php
/**
 * GDPR tab — includes GDPR settings + Rate Limiting.
 *
 * @package KivorAgent
 * @since   1.0.0
 * @var array $settings All plugin settings.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$gdpr = $settings['gdpr'];
?>

<!-- GDPR Settings -->
<form method="post" action="">
	<?php wp_nonce_field( 'kivor_chat_agent_save_gdpr', '_kivor_chat_agent_nonce' ); ?>
	<input type="hidden" name="kivor_chat_agent_save_settings" value="gdpr">

	<h2><?php esc_html_e( 'GDPR & Privacy', 'admin' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Configure consent and privacy features. All features can be individually toggled for different jurisdictions.', 'admin' ); ?></p>

	<table class="form-table">
		<tr>
			<th scope="row"><?php esc_html_e( 'Enable GDPR', 'admin' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="gdpr_enabled" value="1" <?php checked( $gdpr['enabled'] ); ?>>
					<?php esc_html_e( 'Enable GDPR compliance features', 'admin' ); ?>
				</label>
			</td>
		</tr>
	</table>

	<div id="kivor-chat-agent-gdpr-fields" <?php echo ! $gdpr['enabled'] ? 'style="display:none;"' : ''; ?>>
		<table class="form-table">
			<tr>
				<th scope="row"><?php esc_html_e( 'Consent Required', 'admin' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="gdpr_consent_required" value="1" <?php checked( $gdpr['consent_required'] ); ?>>
						<?php esc_html_e( 'Require user consent before starting a chat session', 'admin' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="gdpr_consent_message"><?php esc_html_e( 'Consent Message', 'admin' ); ?></label>
				</th>
				<td>
					<textarea id="gdpr_consent_message" name="gdpr_consent_message" rows="3" class="large-text"><?php echo esc_textarea( $gdpr['consent_message'] ); ?></textarea>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Privacy Link', 'admin' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="gdpr_show_privacy_link" value="1" <?php checked( $gdpr['show_privacy_link'] ); ?>>
						<?php esc_html_e( 'Show link to privacy policy page', 'admin' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="gdpr_privacy_page_id"><?php esc_html_e( 'Privacy Page ID', 'admin' ); ?></label>
				</th>
				<td>
					<input type="number" id="gdpr_privacy_page_id" name="gdpr_privacy_page_id" value="<?php echo esc_attr( $gdpr['privacy_page_id'] ); ?>" min="0" style="width:100px;">
					<span class="description"><?php esc_html_e( '0 = use WordPress default privacy page', 'admin' ); ?></span>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="gdpr_data_retention_days"><?php esc_html_e( 'Data Retention', 'admin' ); ?></label>
				</th>
				<td>
					<input type="number" id="gdpr_data_retention_days" name="gdpr_data_retention_days" value="<?php echo esc_attr( $gdpr['data_retention_days'] ); ?>" min="1" max="365" style="width:100px;">
					<span class="description"><?php esc_html_e( 'days (auto-delete chat logs after this period)', 'admin' ); ?></span>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Anonymize IPs', 'admin' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="gdpr_anonymize_ips" value="1" <?php checked( $gdpr['anonymize_ips'] ); ?>>
						<?php esc_html_e( 'Anonymize IP addresses in chat logs (last octet zeroed)', 'admin' ); ?>
					</label>
				</td>
			</tr>
		</table>
	</div>

	<?php submit_button( __( 'Save GDPR Settings', 'admin' ) ); ?>
</form>
