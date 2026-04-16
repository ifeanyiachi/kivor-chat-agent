<?php
/**
 * Forms tab.
 *
 * @package KivorAgent
 * @since   1.1.0
 * @var array $settings All plugin settings.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$forms_settings = $settings['forms'] ?? array();
$form_manager   = Kivor_Form_Manager::instance();
$forms          = $form_manager->get_forms();

$forms_tab = isset( $forms_tab ) ? (string) $forms_tab : 'manage';

$primary_form_id = absint( $forms_settings['primary_form_id'] ?? 0 );
$tab_form_id     = absint( $forms_settings['tab_form_id'] ?? 0 );
$forms_locked    = Kivor_Feature_Gates::is_feature_available( 'forms' ) ? false : true;
?>

<?php if ( 'manage' === $forms_tab ) : ?>
<p class="description"><?php esc_html_e( 'Create forms for lead capture, support requests, and in-chat workflows.', 'admin' ); ?></p>

<?php if ( $forms_locked ) : ?>
	<?php
	Kivor_Feature_Gates::render_lock_notice(
		__( 'Forms Are Available in Pro', 'admin' ),
		''
	);
	?>
<?php endif; ?>

<form method="post" action="">
	<?php wp_nonce_field( 'kivor_chat_agent_save_forms', '_kivor_chat_agent_nonce' ); ?>
	<input type="hidden" name="kivor_chat_agent_save_settings" value="forms">

	<fieldset <?php disabled( $forms_locked ); ?>>
	<table class="form-table kivor-chat-agent-forms-settings-table">
		<tr>
			<th scope="row"><?php esc_html_e( 'Enable Forms', 'admin' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="forms_enabled" value="1" <?php checked( ! empty( $forms_settings['enabled'] ) ); ?>>
					<?php esc_html_e( 'Enable forms in chat widget.', 'admin' ); ?>
				</label>
			</td>
		</tr>

		<tr>
			<th scope="row">
				<label for="forms_primary_form_id"><?php esc_html_e( 'Primary Form', 'admin' ); ?></label>
			</th>
			<td>
				<select id="forms_primary_form_id" name="forms_primary_form_id">
					<option value="0"><?php esc_html_e( 'None', 'admin' ); ?></option>
					<?php foreach ( $forms as $form ) : ?>
						<option value="<?php echo esc_attr( $form['id'] ); ?>" <?php selected( $primary_form_id, (int) $form['id'] ); ?>>
							<?php echo esc_html( $form['name'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'Shown before chat starts when forms are enabled.', 'admin' ); ?></p>
				<div id="kivor-chat-agent-primary-submit-message-wrap" class="kivor-chat-agent-forms-inline-card" <?php echo $primary_form_id > 0 ? '' : 'style="display:none;"'; ?>>
					<label for="forms_primary_submit_message"><strong><?php esc_html_e( 'After Submit Message', 'admin' ); ?></strong></label>
					<input
						type="text"
						id="forms_primary_submit_message"
						name="forms_primary_submit_message"
						class="regular-text"
						value="<?php echo esc_attr( $forms_settings['primary_submit_message'] ?? 'Thanks. What can I help you with today?' ); ?>"
						placeholder="<?php esc_attr_e( 'Thanks. What can I help you with today?', 'admin' ); ?>"
					>
					<p class="description"><?php esc_html_e( 'Message shown immediately after users submit the primary form.', 'admin' ); ?></p>
				</div>
			</td>
		</tr>

		<tr>
			<th scope="row">
				<label for="forms_tab_form_id"><?php esc_html_e( 'Tab Form', 'admin' ); ?></label>
			</th>
			<td>
				<select id="forms_tab_form_id" name="forms_tab_form_id">
					<option value="0"><?php esc_html_e( 'None', 'admin' ); ?></option>
					<?php foreach ( $forms as $form ) : ?>
						<option value="<?php echo esc_attr( $form['id'] ); ?>" <?php selected( $tab_form_id, (int) $form['id'] ); ?>>
							<?php echo esc_html( $form['name'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'Choose a form to show as its own bottom tab in the widget.', 'admin' ); ?></p>
				<p>
					<label for="forms_tab_label"><strong><?php esc_html_e( 'Tab Name', 'admin' ); ?></strong></label><br>
					<input type="text" id="forms_tab_label" name="forms_tab_label" class="regular-text" value="<?php echo esc_attr( $forms_settings['tab_label'] ?? 'Form' ); ?>" placeholder="Form" maxlength="40">
				</p>
			</td>
		</tr>

		<tr>
			<th scope="row"><?php esc_html_e( 'Primary Form Blocking', 'admin' ); ?></th>
			<td>
				<label>
					<input id="forms_primary_block_input" type="checkbox" name="forms_primary_block_input" value="1" <?php checked( ! empty( $forms_settings['primary_block_input'] ) ); ?>>
					<?php esc_html_e( 'Block chat input until primary form is submitted.', 'admin' ); ?>
				</label>
			</td>
		</tr>

		<tr>
			<th scope="row"><?php esc_html_e( 'Allow Skip', 'admin' ); ?></th>
			<td>
				<label id="kivor-chat-agent-forms-primary-allow-skip-label">
					<input id="forms_primary_allow_skip" type="checkbox" name="forms_primary_allow_skip" value="1" <?php checked( ! empty( $forms_settings['primary_allow_skip'] ) ); ?>>
					<?php esc_html_e( 'Allow users to skip the primary form.', 'admin' ); ?>
				</label>
				<p class="description" id="kivor-chat-agent-forms-primary-allow-skip-note"><?php esc_html_e( 'Automatically disabled when blocking is enabled.', 'admin' ); ?></p>
			</td>
		</tr>

		<tr>
			<th scope="row"><?php esc_html_e( 'Show Field Titles', 'admin' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="forms_show_field_titles" value="1" <?php checked( ! array_key_exists( 'show_field_titles', $forms_settings ) || ! empty( $forms_settings['show_field_titles'] ) ); ?>>
					<?php esc_html_e( 'Show field labels above inputs in the chat form.', 'admin' ); ?>
				</label>
			</td>
		</tr>

		<tr>
			<th scope="row"><?php esc_html_e( 'Email Notifications', 'admin' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="forms_notify_email_enabled" value="1" <?php checked( ! empty( $forms_settings['notify_email_enabled'] ) ); ?>>
					<?php esc_html_e( 'Email admin on each submission.', 'admin' ); ?>
				</label>
				<p>
					<input type="text" class="regular-text" name="forms_notify_email_to" value="<?php echo esc_attr( $forms_settings['notify_email_to'] ?? '' ); ?>" placeholder="admin@example.com, support@example.com">
				</p>
				<p class="description"><?php esc_html_e( 'Comma-separated recipient emails. Defaults to site admin email when empty.', 'admin' ); ?></p>
			</td>
		</tr>
	</table>

	<?php submit_button( __( 'Save Form Settings', 'admin' ) ); ?>
	</fieldset>
</form>

<hr>

<div class="kivor-chat-agent-forms-toolbar">
	<h3><?php esc_html_e( 'Manage Forms', 'admin' ); ?></h3>
	<button type="button" class="button button-primary" id="kivor-chat-agent-create-form" <?php disabled( $forms_locked ); ?>><?php esc_html_e( 'Create Form', 'admin' ); ?></button>
</div>

<div id="kivor-chat-agent-forms-empty" <?php echo ! empty( $forms ) ? 'style="display:none;"' : ''; ?>>
	<p class="description"><?php esc_html_e( 'No forms yet. Create your first form to start collecting data in chat.', 'admin' ); ?></p>
</div>

<table class="widefat striped kivor-chat-agent-forms-table" id="kivor-chat-agent-forms-table" <?php echo empty( $forms ) ? 'style="display:none;"' : ''; ?>>
	<thead>
		<tr>
			<th><?php esc_html_e( 'Name', 'admin' ); ?></th>
			<th><?php esc_html_e( 'Fields', 'admin' ); ?></th>
			<th><?php esc_html_e( 'Trigger Instructions', 'admin' ); ?></th>
			<th><?php esc_html_e( 'AI Eligible', 'admin' ); ?></th>
			<th><?php esc_html_e( 'Actions', 'admin' ); ?></th>
		</tr>
	</thead>
	<tbody id="kivor-chat-agent-forms-rows">
		<?php foreach ( $forms as $form ) : ?>
			<tr data-form-id="<?php echo esc_attr( $form['id'] ); ?>" data-form="<?php echo esc_attr( wp_json_encode( $form ) ); ?>">
				<td><?php echo esc_html( $form['name'] ); ?></td>
				<td><?php echo esc_html( count( $form['fields'] ?? array() ) ); ?></td>
				<td>
					<?php if ( ! empty( $form['trigger_instructions'] ) ) : ?>
						<span class="kivor-chat-agent-trigger-instructions-preview"><?php echo esc_html( $form['trigger_instructions'] ); ?></span>
					<?php else : ?>
						<span class="description"><?php esc_html_e( 'No trigger instructions', 'admin' ); ?></span>
					<?php endif; ?>
				</td>
				<td><?php echo ! empty( $form['is_ai_eligible'] ) ? esc_html__( 'Yes', 'admin' ) : esc_html__( 'No', 'admin' ); ?></td>
				<td>
					<button type="button" class="button kivor-chat-agent-edit-form" <?php disabled( $forms_locked ); ?>><?php esc_html_e( 'Edit', 'admin' ); ?></button>
					<button type="button" class="button button-link-delete kivor-chat-agent-delete-form" <?php disabled( $forms_locked ); ?>><?php esc_html_e( 'Delete', 'admin' ); ?></button>
				</td>
			</tr>
		<?php endforeach; ?>
</tbody>
</table>

<?php endif; ?>

<?php if ( 'submissions' === $forms_tab ) : ?>
<h3><?php esc_html_e( 'Submissions', 'admin' ); ?></h3>
<p class="description"><?php esc_html_e( 'Recent form submissions. Use export to download all entries.', 'admin' ); ?></p>

<div class="kivor-chat-agent-actions-row">
	<button type="button" class="button" id="kivor-chat-agent-export-form-submissions" <?php disabled( $forms_locked ); ?>><?php esc_html_e( 'Export Submissions CSV', 'admin' ); ?></button>
</div>

<div id="kivor-chat-agent-form-submissions-wrap" class="kivor-chat-agent-form-submissions-wrap">
	<table class="widefat striped" id="kivor-chat-agent-form-submissions-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Date', 'admin' ); ?></th>
				<th><?php esc_html_e( 'Form', 'admin' ); ?></th>
				<th><?php esc_html_e( 'Session', 'admin' ); ?></th>
				<th><?php esc_html_e( 'Data', 'admin' ); ?></th>
			</tr>
		</thead>
		<tbody id="kivor-chat-agent-form-submissions-rows">
			<tr>
				<td colspan="4"><?php esc_html_e( 'Loading submissions...', 'admin' ); ?></td>
			</tr>
		</tbody>
	</table>
</div>
<?php endif; ?>

<?php if ( 'manage' === $forms_tab ) : ?>
<div id="kivor-chat-agent-form-modal" class="kivor-chat-agent-form-modal" style="display:none;">
	<div class="kivor-chat-agent-form-modal__backdrop"></div>
	<div class="kivor-chat-agent-form-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="kivor-chat-agent-form-modal-title">
		<div class="kivor-chat-agent-form-modal__header">
			<h3 id="kivor-chat-agent-form-modal-title"><?php esc_html_e( 'Create Form', 'admin' ); ?></h3>
			<button type="button" class="button-link" id="kivor-chat-agent-form-modal-close" aria-label="<?php esc_attr_e( 'Close', 'admin' ); ?>">&times;</button>
		</div>

		<div class="kivor-chat-agent-form-modal__body">
		<div class="kivor-chat-agent-form-grid">
			<p class="kivor-chat-agent-form-grid__item kivor-chat-agent-form-grid__item--half">
				<label for="kivor_form_name"><strong><?php esc_html_e( 'Form Name', 'admin' ); ?></strong></label><br>
				<input type="text" id="kivor_form_name" class="regular-text">
			</p>

			<p class="kivor-chat-agent-form-grid__item kivor-chat-agent-form-grid__item--full">
				<label>
					<input type="checkbox" id="kivor_form_is_ai_eligible" checked>
					<?php esc_html_e( 'AI eligible (can be triggered by AI tool calls)', 'admin' ); ?>
				</label>
				<span class="description"><?php esc_html_e( 'Enable this to allow the AI to show this form based on trigger instructions.', 'admin' ); ?></span>
			</p>

			<div class="kivor-chat-agent-form-grid__item kivor-chat-agent-form-grid__item--full kivor-chat-agent-trigger-instructions-panel" id="kivor-chat-agent-trigger-instructions-panel">
				<p>
					<label for="kivor_form_trigger_instructions"><strong><?php esc_html_e( 'Trigger Instructions', 'admin' ); ?></strong></label><br>
					<textarea id="kivor_form_trigger_instructions" class="large-text" rows="5" placeholder="Describe when AI should show this form. Be explicit about user intent, edge cases, and when it must not show."></textarea>
					<span class="description"><?php esc_html_e( 'Primary source of truth for AI form routing. Example: show only when user asks to cancel, return, or refund an order. Do not show for order tracking or product questions.', 'admin' ); ?></span>
				</p>
				<div class="kivor-chat-agent-trigger-template-buttons" role="group" aria-label="<?php esc_attr_e( 'Trigger instruction templates', 'admin' ); ?>">
					<button type="button" class="button" data-trigger-template="refund"><?php esc_html_e( 'Use Refund Template', 'admin' ); ?></button>
					<button type="button" class="button" data-trigger-template="support"><?php esc_html_e( 'Use Support Template', 'admin' ); ?></button>
					<button type="button" class="button" data-trigger-template="lead"><?php esc_html_e( 'Use Lead Template', 'admin' ); ?></button>
				</div>
				<div class="kivor-chat-agent-form-builder-note">
					<strong><?php esc_html_e( 'Writing Effective Trigger Instructions', 'admin' ); ?></strong>
					<p><?php esc_html_e( 'Include positive triggers and explicit exclusions. Mention similar intents that should not trigger this form to reduce false matches.', 'admin' ); ?></p>
				</div>
			</div>
			</div>

			<h4><?php esc_html_e( 'Fields', 'admin' ); ?></h4>
			<div id="kivor_form_fields"></div>

			<p>
				<button type="button" class="button" id="kivor_form_add_field"><?php esc_html_e( 'Add Field', 'admin' ); ?></button>
			</p>
		</div>

		<div class="kivor-chat-agent-form-modal__footer">
			<button type="button" class="button" id="kivor-chat-agent-form-modal-cancel"><?php esc_html_e( 'Cancel', 'admin' ); ?></button>
			<button type="button" class="button button-primary" id="kivor-chat-agent-form-modal-save" <?php disabled( $forms_locked ); ?>><?php esc_html_e( 'Save Form', 'admin' ); ?></button>
		</div>
	</div>
</div>
<?php endif; ?>
