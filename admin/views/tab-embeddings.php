<?php
/**
 * Embeddings settings tab.
 *
 * @package KivorAgent
 * @since   1.0.0
 * @var array $settings All plugin settings.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$emb    = $settings['embedding'];
$has_wc = class_exists( 'WooCommerce' );

$providers = $emb['providers'] ?? array();
$active    = $emb['active_provider'] ?? 'openai';
$fallback  = $emb['fallback_provider'] ?? 'local';
$embeddings_provider_locked = Kivor_Feature_Gates::is_feature_available( 'embeddings_providers' ) ? false : true;
$vector_store_locked        = ( Kivor_Feature_Gates::is_feature_available( 'vector_store_pinecone' ) || Kivor_Feature_Gates::is_feature_available( 'vector_store_qdrant' ) ) ? false : true;

$provider_labels = array(
	'openai'       => 'OpenAI',
	'gemini'       => 'Google Gemini',
	'openrouter'   => 'OpenRouter',
	'cohere'       => 'Cohere',
);

$models = array(
	'openai'       => array(
		'text-embedding-3-small' => 'text-embedding-3-small (Recommended)',
		'text-embedding-3-large' => 'text-embedding-3-large',
		'text-embedding-ada-002' => 'text-embedding-ada-002 (Legacy)',
	),
	'gemini'       => array(
		'gemini-embedding-001'    => 'gemini-embedding-001',
		'gemini-embedding-001-v1' => 'gemini-embedding-001-v1',
	),
	'openrouter'   => array(
		'openai/text-embedding-3-small'         => 'openai/text-embedding-3-small',
		'openai/text-embedding-3-large'         => 'openai/text-embedding-3-large',
		'cohere/cohere-embed-english-v3.0'      => 'cohere/cohere-embed-english-v3.0',
		'cohere/cohere-embed-multilingual-v3.0' => 'cohere/cohere-embed-multilingual-v3.0',
	),
	'cohere'       => array(
		'embed-english-v3.0'            => 'embed-english-v3.0',
		'embed-english-light-v3.0'      => 'embed-english-light-v3.0',
		'embed-multilingual-v3.0'       => 'embed-multilingual-v3.0',
		'embed-multilingual-light-v3.0' => 'embed-multilingual-light-v3.0',
	),
);
?>

<form method="post" action="">
	<?php wp_nonce_field( 'kivor_chat_agent_save_embedding', '_kivor_chat_agent_nonce' ); ?>
	<input type="hidden" name="kivor_chat_agent_save_settings" value="embedding">

	<h2><?php esc_html_e( 'Embedding Settings', 'admin' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Configure multiple embedding providers, choose a primary provider, and optionally set a fallback provider for resilience.', 'admin' ); ?></p>

	<?php if ( $embeddings_provider_locked || $vector_store_locked ) : ?>
		<?php
		Kivor_Feature_Gates::render_lock_notice(
			__( 'Advanced Embeddings Are Available in Pro', 'admin' ),
			''
		);
		?>
	<?php endif; ?>

	<table class="form-table">
		<tr>
			<th scope="row"><label for="embedding_active_provider"><?php esc_html_e( 'Active Provider', 'admin' ); ?></label></th>
			<td>
				<select id="embedding_active_provider" name="embedding_active_provider" <?php disabled( $embeddings_provider_locked ); ?>>
					<?php foreach ( $provider_labels as $provider_key => $provider_label ) : ?>
						<option value="<?php echo esc_attr( $provider_key ); ?>" <?php selected( $active, $provider_key ); ?>><?php echo esc_html( $provider_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="embedding_fallback_provider"><?php esc_html_e( 'Fallback Provider', 'admin' ); ?></label></th>
			<td>
				<select id="embedding_fallback_provider" name="embedding_fallback_provider">
					<option value="none" <?php selected( $fallback, 'none' ); ?>><?php esc_html_e( 'Do not use fallback', 'admin' ); ?></option>
					<option value="local" <?php selected( $fallback, 'local' ); ?>><?php esc_html_e( 'Local (WordPress DB)', 'admin' ); ?></option>
					<?php foreach ( $provider_labels as $provider_key => $provider_label ) : ?>
						<option value="<?php echo esc_attr( $provider_key ); ?>" <?php selected( $fallback, $provider_key ); ?> <?php disabled( $embeddings_provider_locked ); ?>><?php echo esc_html( $provider_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
	</table>

	<hr>
	<h3><?php esc_html_e( 'Provider Settings', 'admin' ); ?></h3>
	<div class="kivor-chat-agent-provider-cards">
		<?php foreach ( $provider_labels as $provider_key => $provider_label ) : ?>
			<?php $provider = $providers[ $provider_key ] ?? array(); ?>
			<?php $provider_enabled = ! empty( $provider['enabled'] ); ?>
			<details class="kivor-chat-agent-provider-card kivor-chat-agent-provider-accordion <?php echo $active === $provider_key ? 'is-active' : ''; ?>" <?php echo $active === $provider_key ? 'open' : ''; ?>>
				<summary>
					<span><?php echo esc_html( $provider_label ); ?></span>
					<?php if ( $active === $provider_key ) : ?>
						<span class="dashicons dashicons-yes-alt" style="color:#2271b1;" title="<?php esc_attr_e( 'Active', 'admin' ); ?>"></span>
					<?php endif; ?>
				</summary>

				<div class="kivor-chat-agent-provider-card__body">
					<fieldset <?php disabled( $embeddings_provider_locked ); ?>>
					<table class="form-table">
						<tr>
							<th scope="row"><?php esc_html_e( 'Enabled', 'admin' ); ?></th>
							<td><input type="checkbox" name="embedding_provider_<?php echo esc_attr( $provider_key ); ?>_enabled" value="1" <?php checked( ! empty( $provider['enabled'] ) ); ?>></td>
						</tr>
						<tr>
							<th scope="row"><label for="embedding_provider_<?php echo esc_attr( $provider_key ); ?>_api_key"><?php esc_html_e( 'API Key', 'admin' ); ?></label></th>
							<td><input type="password" id="embedding_provider_<?php echo esc_attr( $provider_key ); ?>_api_key" name="embedding_provider_<?php echo esc_attr( $provider_key ); ?>_api_key" value="<?php echo esc_attr( Kivor_Admin::mask_key( (string) ( $provider['api_key'] ?? '' ) ) ); ?>" class="regular-text" autocomplete="off"></td>
						</tr>
						<tr>
							<th scope="row"><label for="embedding_provider_<?php echo esc_attr( $provider_key ); ?>_model"><?php esc_html_e( 'Model', 'admin' ); ?></label></th>
							<td>
								<select id="embedding_provider_<?php echo esc_attr( $provider_key ); ?>_model" name="embedding_provider_<?php echo esc_attr( $provider_key ); ?>_model">
									<?php foreach ( $models[ $provider_key ] as $model_value => $model_label ) : ?>
										<option value="<?php echo esc_attr( $model_value ); ?>" <?php selected( (string) ( $provider['model'] ?? '' ), $model_value ); ?>><?php echo esc_html( $model_label ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>

					</table>
					<p>
						<button type="button" class="button kivor-chat-agent-test-embedding-provider" data-provider="<?php echo esc_attr( $provider_key ); ?>" <?php disabled( ! $provider_enabled ); ?>>
							<?php esc_html_e( 'Test Connection', 'admin' ); ?>
						</button>
					</p>
					</fieldset>
					<p class="description"><?php esc_html_e( 'Tests using current form values, including unsaved changes.', 'admin' ); ?></p>
					<div id="kivor-chat-agent-test-embedding-result-<?php echo esc_attr( $provider_key ); ?>" class="kivor-chat-agent-test-result"></div>
				</div>
			</details>
		<?php endforeach; ?>
	</div>

	<hr>
	<h3><?php esc_html_e( 'Vector Store', 'admin' ); ?></h3>
	<table class="form-table">
		<tr>
			<th scope="row"><label for="vector_store"><?php esc_html_e( 'Storage Backend', 'admin' ); ?></label></th>
			<td>
				<select id="vector_store" name="vector_store">
					<option value="local" <?php selected( $emb['vector_store'], 'local' ); ?>><?php esc_html_e( 'Local (WordPress Database)', 'admin' ); ?></option>
					<option value="pinecone" <?php selected( $emb['vector_store'], 'pinecone' ); ?> <?php disabled( $vector_store_locked ); ?>>Pinecone</option>
					<option value="qdrant" <?php selected( $emb['vector_store'], 'qdrant' ); ?> <?php disabled( $vector_store_locked ); ?>>Qdrant</option>
				</select>
			</td>
		</tr>
	</table>

	<div id="kivor-chat-agent-pinecone-section" class="kivor-chat-agent-conditional-section <?php echo 'pinecone' !== $emb['vector_store'] ? 'is-hidden' : ''; ?>">
		<h3><?php esc_html_e( 'Pinecone Configuration', 'admin' ); ?></h3>
		<fieldset <?php disabled( $vector_store_locked ); ?>>
		<table class="form-table">
			<tr>
				<th scope="row"><label for="pinecone_api_key"><?php esc_html_e( 'API Key', 'admin' ); ?></label></th>
				<td><input type="password" id="pinecone_api_key" name="pinecone_api_key" value="<?php echo esc_attr( Kivor_Admin::mask_key( $emb['pinecone']['api_key'] ?? '' ) ); ?>" class="regular-text" autocomplete="off"></td>
			</tr>
			<tr>
				<th scope="row"><label for="pinecone_index_name"><?php esc_html_e( 'Index Name', 'admin' ); ?></label></th>
				<td><input type="text" id="pinecone_index_name" name="pinecone_index_name" value="<?php echo esc_attr( $emb['pinecone']['index_name'] ?? '' ); ?>" class="regular-text"></td>
			</tr>
			<tr>
				<th scope="row"><label for="pinecone_environment"><?php esc_html_e( 'Environment', 'admin' ); ?></label></th>
				<td><input type="text" id="pinecone_environment" name="pinecone_environment" value="<?php echo esc_attr( $emb['pinecone']['environment'] ?? '' ); ?>" class="regular-text" placeholder="us-east-1-aws"></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Connection Test', 'admin' ); ?></th>
				<td>
					<button type="button" class="button kivor-chat-agent-test-vector-store" data-store="pinecone"><?php esc_html_e( 'Test Pinecone Connection', 'admin' ); ?></button>
					<div id="kivor-chat-agent-test-vector-result-pinecone" class="kivor-chat-agent-test-result"></div>
				</td>
			</tr>
		</table>
		</fieldset>
	</div>

	<div id="kivor-chat-agent-qdrant-section" class="kivor-chat-agent-conditional-section <?php echo 'qdrant' !== $emb['vector_store'] ? 'is-hidden' : ''; ?>">
		<h3><?php esc_html_e( 'Qdrant Configuration', 'admin' ); ?></h3>
		<fieldset <?php disabled( $vector_store_locked ); ?>>
		<table class="form-table">
			<tr>
				<th scope="row"><label for="qdrant_endpoint_url"><?php esc_html_e( 'Endpoint URL', 'admin' ); ?></label></th>
				<td><input type="url" id="qdrant_endpoint_url" name="qdrant_endpoint_url" value="<?php echo esc_attr( $emb['qdrant']['endpoint_url'] ?? '' ); ?>" class="regular-text" placeholder="https://your-cluster.qdrant.io:6333"></td>
			</tr>
			<tr>
				<th scope="row"><label for="qdrant_api_key"><?php esc_html_e( 'API Key', 'admin' ); ?></label></th>
				<td><input type="password" id="qdrant_api_key" name="qdrant_api_key" value="<?php echo esc_attr( Kivor_Admin::mask_key( $emb['qdrant']['api_key'] ?? '' ) ); ?>" class="regular-text" autocomplete="off"></td>
			</tr>
			<tr>
				<th scope="row"><label for="qdrant_collection_name"><?php esc_html_e( 'Collection Name', 'admin' ); ?></label></th>
				<td><input type="text" id="qdrant_collection_name" name="qdrant_collection_name" value="<?php echo esc_attr( $emb['qdrant']['collection_name'] ?? 'kivor_chat_agent_products' ); ?>" class="regular-text"></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Connection Test', 'admin' ); ?></th>
				<td>
					<button type="button" class="button kivor-chat-agent-test-vector-store" data-store="qdrant"><?php esc_html_e( 'Test Qdrant Connection', 'admin' ); ?></button>
					<div id="kivor-chat-agent-test-vector-result-qdrant" class="kivor-chat-agent-test-result"></div>
				</td>
			</tr>
		</table>
		</fieldset>
	</div>

	<hr>
	<h3><?php esc_html_e( 'Sync Settings', 'admin' ); ?></h3>
	<table class="form-table">
		<tr>
			<th scope="row"><?php esc_html_e( 'Auto-Sync', 'admin' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="sync_on_product_save" value="1" <?php checked( ! empty( $emb['sync_on_product_save'] ) ); ?>>
					<?php esc_html_e( 'Automatically update embeddings when products are saved', 'admin' ); ?>
				</label>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Manual Sync', 'admin' ); ?></th>
			<td>
				<button type="button" id="kivor-chat-agent-sync-embeddings" class="button" <?php echo ! $has_wc ? 'disabled' : ''; ?>>
					<?php esc_html_e( 'Sync All Products Now', 'admin' ); ?>
				</button>
				<div id="kivor-chat-agent-sync-result" class="kivor-chat-agent-test-result"></div>
			</td>
		</tr>
	</table>

	<?php submit_button( __( 'Save Embedding Settings', 'admin' ) ); ?>
</form>
