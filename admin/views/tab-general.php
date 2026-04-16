<?php
/**
 * General Settings tab.
 *
 * @package KivorAgent
 * @since   1.0.0
 * @var array $settings All plugin settings.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$general = $settings['general'];
?>

<form method="post" action="">
	<?php wp_nonce_field( 'kivor_chat_agent_save_general', '_kivor_chat_agent_nonce' ); ?>
	<input type="hidden" name="kivor_chat_agent_save_settings" value="general">

	<h2><?php esc_html_e( 'General Settings', 'admin' ); ?></h2>

	<table class="form-table">
		<tr>
			<th scope="row">
				<label for="bot_name"><?php esc_html_e( 'Bot Name', 'admin' ); ?></label>
			</th>
			<td>
				<input type="text" id="bot_name" name="bot_name" value="<?php echo esc_attr( $general['bot_name'] ); ?>" class="regular-text">
				<p class="description"><?php esc_html_e( 'The name displayed in the chat widget header.', 'admin' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Use In-App Title & Description', 'admin' ); ?></th>
			<td>
				<label>
					<input type="checkbox" id="use_in_app_intro" name="use_in_app_intro" value="1" <?php checked( ! empty( $general['use_in_app_intro'] ) ); ?>>
					<?php esc_html_e( 'Show title and description inside the chat content area.', 'admin' ); ?>
				</label>
			</td>
		</tr>
		<tr id="kivor-chat-agent-chatbot-title-row">
			<th scope="row">
				<label for="chatbot_title"><?php esc_html_e( 'Chatbot Title', 'admin' ); ?></label>
			</th>
			<td>
				<input type="text" id="chatbot_title" name="chatbot_title" value="<?php echo esc_attr( $general['chatbot_title'] ?? '' ); ?>" class="regular-text" maxlength="100" placeholder="We typically reply in a few minutes">
				<p class="description"><?php esc_html_e( 'Optional headline text shown at the top of the chatbot conversation area.', 'admin' ); ?></p>
			</td>
		</tr>
		<tr id="kivor-chat-agent-chatbot-description-row">
			<th scope="row">
				<label for="chatbot_description"><?php esc_html_e( 'Chatbot Description', 'admin' ); ?></label>
			</th>
			<td>
				<textarea id="chatbot_description" name="chatbot_description" rows="3" class="large-text"><?php echo esc_textarea( $general['chatbot_description'] ?? '' ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Optional supporting text shown below the chatbot title.', 'admin' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="first_greeting_message"><?php esc_html_e( 'First Greeting Message', 'admin' ); ?></label>
			</th>
			<td>
				<textarea id="first_greeting_message" name="first_greeting_message" rows="4" class="large-text"><?php echo esc_textarea( $general['first_greeting_message'] ?? 'Hi, welcome! You\'re speaking with AI Agent. I\'m here to answer your questions and help you out.' ); ?></textarea>
				<p class="description"><?php esc_html_e( 'The first message shown from the bot before the visitor sends anything.', 'admin' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="bot_avatar"><?php esc_html_e( 'Bot Avatar', 'admin' ); ?></label>
			</th>
			<td>
				<input type="hidden" id="bot_avatar" name="bot_avatar" value="<?php echo esc_attr( $general['bot_avatar'] ); ?>">
				<div class="kivor-chat-agent-avatar-controls">
					<img
						id="kivor-chat-agent-bot-avatar-preview"
						class="kivor-chat-agent-avatar-preview"
						src="<?php echo esc_url( $general['bot_avatar'] ); ?>"
						alt="<?php esc_attr_e( 'Bot avatar preview', 'admin' ); ?>"
						<?php echo empty( $general['bot_avatar'] ) ? 'style="display:none;"' : ''; ?>
					>
					<button type="button" class="button" id="kivor-chat-agent-avatar-upload"><?php esc_html_e( 'Select Image', 'admin' ); ?></button>
					<button
						type="button"
						class="button"
						id="kivor-chat-agent-avatar-remove"
						<?php echo empty( $general['bot_avatar'] ) ? 'style="display:none;"' : ''; ?>
					>
						<?php esc_html_e( 'Remove', 'admin' ); ?>
					</button>
				</div>
				<p class="description"><?php esc_html_e( 'Upload or select an image from the media library. Leave empty for default icon.', 'admin' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="widget_logo_id"><?php esc_html_e( 'Custom Widget Logo', 'admin' ); ?></label>
			</th>
			<td>
				<input type="hidden" id="widget_logo_id" name="widget_logo_id" value="<?php echo esc_attr( (string) ( $general['widget_logo_id'] ?? 0 ) ); ?>">
				<div class="kivor-chat-agent-widget-logo-controls">
					<img
						id="kivor-chat-agent-widget-logo-preview"
						class="kivor-chat-agent-avatar-preview"
						src="<?php echo esc_url( $general['widget_logo'] ?? '' ); ?>"
						alt="<?php esc_attr_e( 'Widget logo preview', 'admin' ); ?>"
						<?php echo empty( $general['widget_logo'] ) ? 'style="display:none;"' : ''; ?>
					>
					<button type="button" class="button" id="kivor-chat-agent-widget-logo-upload"><?php esc_html_e( 'Select Logo', 'admin' ); ?></button>
					<button
						type="button"
						class="button"
						id="kivor-chat-agent-widget-logo-remove"
						<?php echo empty( $general['widget_logo'] ) ? 'style="display:none;"' : ''; ?>
					>
						<?php esc_html_e( 'Remove', 'admin' ); ?>
					</button>
				</div>
				<p class="description"><?php esc_html_e( 'Upload from Media Library (PNG or SVG). If empty, the default logo icon is used.', 'admin' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="chat_tab_label"><?php esc_html_e( 'Chat Tab Name', 'admin' ); ?></label>
			</th>
			<td>
				<input type="text" id="chat_tab_label" name="chat_tab_label" value="<?php echo esc_attr( $general['chat_tab_label'] ?? 'Chat' ); ?>" class="regular-text" maxlength="30" placeholder="Chat">
				<p class="description"><?php esc_html_e( 'Customize the label of the chat tab (e.g., Chat, Support, Ask Us).', 'admin' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="chat_position"><?php esc_html_e( 'Chat Position', 'admin' ); ?></label>
			</th>
			<td>
				<select id="chat_position" name="chat_position">
					<option value="bottom-right" <?php selected( $general['chat_position'], 'bottom-right' ); ?>><?php esc_html_e( 'Bottom Right', 'admin' ); ?></option>
					<option value="bottom-left" <?php selected( $general['chat_position'], 'bottom-left' ); ?>><?php esc_html_e( 'Bottom Left', 'admin' ); ?></option>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'End Session Button', 'admin' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="show_end_session_button" value="1" <?php checked( ! empty( $general['show_end_session_button'] ) ); ?>>
					<?php esc_html_e( 'Show an "End session" button in the chat header to clear current chat and start fresh.', 'admin' ); ?>
				</label>
			</td>
		</tr>
	</table>

	<hr>
	<h3><?php esc_html_e( 'Custom Instructions', 'admin' ); ?></h3>

	<table class="form-table">
		<tr>
			<th scope="row">
				<label for="custom_instructions"><?php esc_html_e( 'Instructions', 'admin' ); ?></label>
			</th>
			<td>
				<textarea id="custom_instructions" name="custom_instructions" rows="6" class="large-text"><?php echo esc_textarea( $general['custom_instructions'] ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Additional instructions appended to the AI system prompt. Use this to customize behavior, tone, or restrict topics.', 'admin' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Override Mode', 'admin' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="override_system_instructions" value="1" <?php checked( $general['override_system_instructions'] ); ?>>
					<?php esc_html_e( 'Replace the default system prompt entirely with custom instructions above', 'admin' ); ?>
				</label>
				<p class="description"><?php esc_html_e( 'Warning: This removes all built-in behavior including product search capabilities.', 'admin' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Total Uninstall', 'admin' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="total_uninstall" value="1" <?php checked( ! empty( $general['total_uninstall'] ) ); ?>>
					<?php esc_html_e( 'Delete all plugin data when uninstalling the plugin.', 'admin' ); ?>
				</label>
				<p class="description"><?php esc_html_e( 'If disabled, uninstall keeps plugin settings, logs, forms, and knowledge data in the database.', 'admin' ); ?></p>
			</td>
		</tr>
	</table>

	<?php submit_button( __( 'Save General Settings', 'admin' ) ); ?>
</form>