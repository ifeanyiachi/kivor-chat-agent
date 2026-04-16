<?php
/**
 * Knowledge Base tab.
 *
 * @package KivorAgent
 * @since   1.0.0
 * @var array $settings All plugin settings.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

// Load list table class.
if ( ! class_exists( 'Kivor_KB_List_Table' ) ) {
	require_once KIVOR_AGENT_PATH . 'admin/class-kivor-kb-list-table.php';
}

$kb_table = new Kivor_KB_List_Table();
$kb_table->prepare_items();

$ext_settings = $settings['external_platforms'] ?? array();
$zendesk_cfg = $ext_settings['zendesk'] ?? array();
$notion_cfg  = $ext_settings['notion'] ?? array();
$zendesk_locked = Kivor_Feature_Gates::is_feature_available( 'knowledge_zendesk' ) ? false : true;
$notion_locked  = Kivor_Feature_Gates::is_feature_available( 'knowledge_notion' ) ? false : true;
$webscan_count  = Kivor_Feature_Gates::get_webpage_scan_count();
$webscan_limit  = Kivor_Feature_Gates::get_webpage_scan_limit();
$webscan_locked = ! Kivor_Feature_Gates::is_feature_available( 'knowledge_webpage_scan' );

$default_source_type = 'manual';
$form_source_type    = 'manual';
$source_type_options = array(
	'manual'          => __( 'Manual', 'admin' ),
	'wordpress_posts' => __( 'WordPress Posts', 'admin' ),
	'wordpress_pages' => __( 'WordPress Pages', 'admin' ),
	'zendesk'         => __( 'Zendesk', 'admin' ),
	'notion'          => __( 'Notion', 'admin' ),

);
?>

<p class="description"><?php esc_html_e( 'Add articles and import content from URLs. The AI will use this knowledge to answer questions beyond product data.', 'admin' ); ?></p>
<?php if ( $zendesk_locked || $notion_locked ) : ?>
	<?php
	Kivor_Feature_Gates::render_lock_notice(
		__( 'Premium Knowledge Integrations', 'admin' ),
		__( 'Zendesk and Notion sources are available in Pro. WordPress posts/pages and webpage scans are available in free.', 'admin' )
	);
	?>
<?php endif; ?>
<p class="description">
	<?php
	// translators: 1: number of scans used, 2: total free scan limit.
	printf(
		/* translators: %s: maximum upload file size */
		esc_html__( 'Webpage scans used: %1$d/%2$d (free lifetime limit per site).', 'kivor-chat-agent' ),
		(int) $webscan_count,
		(int) $webscan_limit
	);
	?>
</p>

<div class="kivor-chat-agent-kb-new-knowledge-head">
	<button type="button" class="button button-primary" id="kivor-chat-agent-new-knowledge-toggle" aria-expanded="false">
		<?php esc_html_e( 'New Knowledge', 'admin' ); ?>
	</button>
</div>


<div id="kivor-chat-agent-kb-modal" class="kivor-chat-agent-kb-modal" style="display:none;">
	<div class="kivor-chat-agent-kb-modal__backdrop"></div>
	<div class="kivor-chat-agent-kb-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="kivor-chat-agent-kb-modal-title">
		<div class="kivor-chat-agent-kb-modal__header">
			<h3 id="kivor-chat-agent-kb-modal-title"><?php esc_html_e( 'New Knowledge', 'admin' ); ?></h3>
			<button type="button" class="button-link" id="kivor-chat-agent-kb-modal-close" aria-label="<?php esc_attr_e( 'Close', 'admin' ); ?>">&times;</button>
		</div>

		<div class="kivor-chat-agent-kb-modal__body">
			<div class="kivor-chat-agent-kb-composer is-open" id="kivor-chat-agent-kb-composer">
				<div class="kivor-chat-agent-kb-source-picker">
					<label for="kivor-chat-agent-source-selector"><strong><?php esc_html_e( 'Select source', 'admin' ); ?></strong></label>
					<select id="kivor-chat-agent-source-selector" class="regular-text">
						<?php foreach ( $source_type_options as $source_key => $source_label ) : ?>
							<?php $source_locked = ( 'zendesk' === $source_key && $zendesk_locked ) || ( 'notion' === $source_key && $notion_locked ); ?>
							<option value="<?php echo esc_attr( $source_key ); ?>" <?php selected( $default_source_type, $source_key ); ?> <?php disabled( $source_locked ); ?>><?php echo esc_html( $source_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>

				<div id="kivor-chat-agent-manual-pane" class="kivor-chat-agent-kb-pane" data-source-pane="manual">
					<div class="kivor-chat-agent-url-scraper">
						<input type="url" id="kivor-chat-agent-scrape-url" placeholder="<?php esc_attr_e( 'https://example.com/page-to-import', 'admin' ); ?>" class="regular-text">
						<button type="button" id="kivor-chat-agent-scrape-btn" class="button" <?php disabled( $webscan_locked ); ?>>
							<?php esc_html_e( 'Scan Webpage', 'admin' ); ?>
						</button>
					</div>
					<?php if ( $webscan_locked ) : ?>
						<p class="description"><?php esc_html_e( 'You have reached 5/5 free webpage scans for this site. Upgrade to continue scanning webpages.', 'admin' ); ?></p>
					<?php endif; ?>
					<div id="kivor-chat-agent-scrape-result" class="kivor-chat-agent-test-result"></div>

					<div class="kivor-chat-agent-kb-editor">
						<h3 id="kivor-chat-agent-kb-editor-title"><?php esc_html_e( 'Manual Knowledge', 'admin' ); ?></h3>

						<form method="post" action="<?php echo esc_url( rest_url( 'kivor-chat-agent/v1/admin/knowledge-base' ) ); ?>" id="kivor-chat-agent-kb-form">
							<input type="hidden" id="kb_id" name="id" value="">
							<input type="hidden" id="kb_source_type" name="source_type" value="<?php echo esc_attr( $form_source_type ); ?>">
							<input type="hidden" id="kb_source_id" name="source_id" value="">
							<input type="hidden" id="kb_source_url" name="source_url" value="">
							<input type="hidden" id="kb_import_method" name="import_method" value="manual">
							<input type="hidden" id="kb_sync_interval" name="sync_interval" value="manual">

							<table class="form-table">
								<tr>
									<th scope="row">
										<label for="kb_title"><?php esc_html_e( 'Title', 'admin' ); ?></label>
									</th>
									<td>
										<input type="text" id="kb_title" name="title" value="" class="regular-text" required>
									</td>
								</tr>
								<tr>
									<th scope="row">
										<label for="kb_content"><?php esc_html_e( 'Content', 'admin' ); ?></label>
									</th>
									<td>
										<textarea id="kb_content" name="content" rows="10" class="large-text" data-kivor-chat-agent-maxchars="5000" required></textarea>
										<span id="kb_content-counter" class="kivor-chat-agent-char-counter"></span>
									</td>
								</tr>
							</table>

							<p>
								<button type="submit" class="button button-primary" id="kivor-chat-agent-kb-save">
									<?php esc_html_e( 'Save Knowledge', 'admin' ); ?>
								</button>
								<button type="button" class="button" id="kivor-chat-agent-kb-modal-cancel"><?php esc_html_e( 'Cancel', 'admin' ); ?></button>
							</p>
						</form>
					</div>
				</div>

				<div id="kivor-chat-agent-external-pane" class="kivor-chat-agent-kb-pane" data-source-pane="external" style="display:none;">
					<div class="kivor-chat-agent-kb-source-credentials" data-source-credentials="zendesk" style="display:none;">
						<h3><?php esc_html_e( 'Zendesk Connection', 'admin' ); ?></h3>
						<p>
							<label>
								<input type="checkbox" id="kb_zendesk_override_credentials" value="1">
								<?php esc_html_e( 'Override saved credentials for this scan', 'admin' ); ?>
							</label>
						</p>
						<div class="kivor-chat-agent-kb-source-override-fields" data-source-override-fields="zendesk" style="display:none;">
							<table class="form-table">
								<tr>
									<th scope="row"><label for="kb_zendesk_subdomain"><?php esc_html_e( 'Subdomain or host', 'admin' ); ?></label></th>
									<td><input type="text" id="kb_zendesk_subdomain" class="regular-text" value="<?php echo esc_attr( (string) ( $zendesk_cfg['subdomain'] ?? '' ) ); ?>"></td>
								</tr>
								<tr>
									<th scope="row"><label for="kb_zendesk_email"><?php esc_html_e( 'Email', 'admin' ); ?></label></th>
									<td><input type="email" id="kb_zendesk_email" class="regular-text" value="<?php echo esc_attr( (string) ( $zendesk_cfg['email'] ?? '' ) ); ?>"></td>
								</tr>
								<tr>
									<th scope="row"><label for="kb_zendesk_api_token"><?php esc_html_e( 'API token', 'admin' ); ?></label></th>
									<td><input type="password" id="kb_zendesk_api_token" class="regular-text" placeholder="<?php esc_attr_e( 'Leave blank to keep saved token', 'admin' ); ?>"></td>
								</tr>
							</table>
						</div>
					</div>

					<div class="kivor-chat-agent-kb-source-credentials" data-source-credentials="notion" style="display:none;">
						<h3><?php esc_html_e( 'Notion Connection', 'admin' ); ?></h3>
						<p>
							<label>
								<input type="checkbox" id="kb_notion_override_credentials" value="1">
								<?php esc_html_e( 'Override saved credentials for this scan', 'admin' ); ?>
							</label>
						</p>
						<div class="kivor-chat-agent-kb-source-override-fields" data-source-override-fields="notion" style="display:none;">
							<table class="form-table">
								<tr>
									<th scope="row"><label for="kb_notion_api_key"><?php esc_html_e( 'API key', 'admin' ); ?></label></th>
									<td><input type="password" id="kb_notion_api_key" class="regular-text" placeholder="<?php esc_attr_e( 'Leave blank to keep saved API key', 'admin' ); ?>"></td>
								</tr>
								<tr>
									<th scope="row"><label for="kb_notion_database_id"><?php esc_html_e( 'Database ID', 'admin' ); ?></label></th>
									<td><input type="text" id="kb_notion_database_id" class="regular-text" value="<?php echo esc_attr( (string) ( $notion_cfg['database_id'] ?? '' ) ); ?>"></td>
								</tr>
							</table>
						</div>
					</div>


					<div class="kivor-chat-agent-actions-row">
						<button type="button" class="button" id="kivor-chat-agent-source-scan-btn"><?php esc_html_e( 'Scan Source', 'admin' ); ?></button>
						<select id="kivor-chat-agent-source-sync-interval">
							<option value="manual"><?php esc_html_e( 'Sync: Manual', 'admin' ); ?></option>
							<option value="hourly"><?php esc_html_e( 'Sync: Hourly', 'admin' ); ?></option>
							<option value="daily"><?php esc_html_e( 'Sync: Daily', 'admin' ); ?></option>
							<option value="weekly"><?php esc_html_e( 'Sync: Weekly', 'admin' ); ?></option>
						</select>
						<button type="button" class="button" id="kivor-chat-agent-source-import-selected-btn" disabled><?php esc_html_e( 'Import Selected', 'admin' ); ?></button>
						<button type="button" class="button button-primary" id="kivor-chat-agent-source-import-all-btn" disabled><?php esc_html_e( 'Import All', 'admin' ); ?></button>
					</div>
					<div id="kivor-chat-agent-source-result" class="kivor-chat-agent-test-result"></div>
					<div id="kivor-chat-agent-source-job-status" class="kivor-chat-agent-test-result"></div>

					<div id="kivor-chat-agent-scan-list-wrap" class="kivor-chat-agent-scan-list-wrap" style="display:none;">
						<table class="widefat fixed striped" id="kivor-chat-agent-scan-table">
							<thead>
								<tr>
									<td class="manage-column check-column"><input type="checkbox" id="kivor-chat-agent-scan-check-all"></td>
									<th><?php esc_html_e( 'Title', 'admin' ); ?></th>
									<th><?php esc_html_e( 'Status', 'admin' ); ?></th>
									<th><?php esc_html_e( 'Source', 'admin' ); ?></th>
								</tr>
							</thead>
							<tbody id="kivor-chat-agent-scan-list-body"></tbody>
						</table>
					</div>

					<div id="kivor-chat-agent-manual-review-wrap" class="kivor-chat-agent-manual-review-wrap" style="display:none;">
						<h3><?php esc_html_e( 'Manual Review Queue', 'admin' ); ?></h3>
						<div id="kivor-chat-agent-manual-review-list"></div>
					</div>
				</div>
			</div>
	</div>
</div>
</div>

<script>
// Handle KB form submit via REST API.
(function() {
	var form = document.getElementById('kivor-chat-agent-kb-form');
	if (!form) return;

	form.addEventListener('submit', function(e) {
		e.preventDefault();
		var btn = document.getElementById('kivor-chat-agent-kb-save');
		var origText = btn.textContent;
		btn.disabled = true;
		btn.innerHTML = origText + ' <span class="kivor-chat-agent-spinner"></span>';

		var data = {
			title: document.getElementById('kb_title').value,
			content: document.getElementById('kb_content').value,
			source_type: document.getElementById('kb_source_type') ? document.getElementById('kb_source_type').value : 'manual',
			source_id: document.getElementById('kb_source_id') ? document.getElementById('kb_source_id').value : '',
			source_url: document.getElementById('kb_source_url') ? document.getElementById('kb_source_url').value : '',
			import_method: document.getElementById('kb_import_method') ? document.getElementById('kb_import_method').value : 'manual',
			sync_interval: document.getElementById('kb_sync_interval') ? document.getElementById('kb_sync_interval').value : 'manual'
		};

		var idField = form.querySelector('[name="id"]');
		if (idField && idField.value) {
			var parsedId = parseInt(idField.value, 10);
			if (!isNaN(parsedId) && parsedId > 0) {
				data.id = parsedId;
			}
		}

		fetch(kivorChatAgentAdmin.restUrl + 'knowledge-base', {
			method: 'POST',
			headers: {
				'X-WP-Nonce': kivorChatAgentAdmin.nonce,
				'Content-Type': 'application/json',
			},
			credentials: 'same-origin',
			body: JSON.stringify(data),
		})
		.then(function(res) { return res.json(); })
		.then(function(resp) {
			btn.disabled = false;
			btn.textContent = origText;
			if (resp.success) {
				window.location.reload();
			} else {
				alert(resp.message || 'Failed to save article.');
			}
		})
		.catch(function(err) {
			btn.disabled = false;
			btn.textContent = origText;
			alert('Request failed: ' + err.message);
		});
	});
})();
</script>

<!-- Articles List -->
<hr>
<h3><?php esc_html_e( 'Articles', 'admin' ); ?></h3>
<?php $kb_table->display(); ?>
