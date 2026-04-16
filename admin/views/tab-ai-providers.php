<?php
/**
 * AI Providers tab.
 *
 * @package KivorAgent
 * @since   1.0.0
 * @var array $settings All plugin settings.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$ai = $settings['ai_provider'];
$active = $ai['active_provider'];
$providers_data = $ai['providers'];

$model_options = array(
	'openai' => array(
		'gpt-4o-mini'    => 'GPT-4o Mini',
		'gpt-4o'         => 'GPT-4o',
		'gpt-4-turbo'    => 'GPT-4 Turbo',
		'gpt-3.5-turbo'  => 'GPT-3.5 Turbo',
	),
	'gemini' => array(
		'gemini-2.5-flash'      => 'Gemini 2.5 Flash',
		'gemini-2.5-flash-lite' => 'Gemini 2.5 Flash Lite',
		'gemini-2.5-pro'        => 'Gemini 2.5 Pro',
	),
);
?>

<form method="post" action="">
	<?php wp_nonce_field( 'kivor_chat_agent_save_ai_provider', '_kivor_chat_agent_nonce' ); ?>
	<input type="hidden" name="kivor_chat_agent_save_settings" value="ai_provider">

	<h2><?php esc_html_e( 'AI Provider Configuration', 'admin' ); ?></h2>

	<table class="form-table">
		<tr>
			<th scope="row">
				<label for="active_provider"><?php esc_html_e( 'Active Provider', 'admin' ); ?></label>
			</th>
			<td>
				<select id="active_provider" name="active_provider">
					<option value="openai" <?php selected( $active, 'openai' ); ?>>OpenAI</option>
					<option value="gemini" <?php selected( $active, 'gemini' ); ?>>Gemini</option>
					<option value="openrouter" <?php selected( $active, 'openrouter' ); ?>>OpenRouter</option>
				</select>
				<p class="description"><?php esc_html_e( 'The AI provider used for chat responses.', 'admin' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="conversation_memory_size"><?php esc_html_e( 'Conversation Memory', 'admin' ); ?></label>
			</th>
			<td>
				<input type="number" id="conversation_memory_size" name="conversation_memory_size" value="<?php echo esc_attr( $ai['conversation_memory_size'] ); ?>" min="0" max="50" step="1" style="width:80px;">
				<span class="description"><?php esc_html_e( 'messages (0 = no memory, max 50)', 'admin' ); ?></span>
			</td>
		</tr>
	</table>

	<hr>
	<h3><?php esc_html_e( 'Provider Settings', 'admin' ); ?></h3>
	<p class="description"><?php esc_html_e( 'Configure API keys and models for each provider. You can enable multiple providers and switch the active one above.', 'admin' ); ?></p>

	<div class="kivor-chat-agent-provider-cards">
		<?php
		$provider_labels = array(
			'openai'     => 'OpenAI',
			'gemini'     => 'Google Gemini',
			'openrouter' => 'OpenRouter',
		);

		foreach ( $provider_labels as $key => $label ) :
			$p = $providers_data[ $key ];
			$is_active = ( $active === $key );
			$masked_key = Kivor_Admin::mask_key( $p['api_key'] );
		?>
		<details class="kivor-chat-agent-provider-card kivor-chat-agent-provider-accordion <?php echo $is_active ? 'is-active' : ''; ?>" <?php echo $is_active ? 'open' : ''; ?>>
			<summary>
				<span><?php echo esc_html( $label ); ?></span>
				<?php if ( $is_active ) : ?>
					<span class="dashicons dashicons-yes-alt" style="color:#2271b1;" title="<?php esc_attr_e( 'Active', 'admin' ); ?>"></span>
				<?php endif; ?>
			</summary>

			<div class="kivor-chat-agent-provider-card__body">
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enabled', 'admin' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="provider_<?php echo esc_attr( $key ); ?>_enabled" value="1" <?php checked( $p['enabled'] ); ?>>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="provider_<?php echo esc_attr( $key ); ?>_api_key"><?php esc_html_e( 'API Key', 'admin' ); ?></label>
					</th>
					<td>
						<input type="password" id="provider_<?php echo esc_attr( $key ); ?>_api_key" name="provider_<?php echo esc_attr( $key ); ?>_api_key" value="<?php echo esc_attr( $masked_key ); ?>" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Clear and type a new key to change.', 'admin' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="provider_<?php echo esc_attr( $key ); ?>_model"><?php esc_html_e( 'Model', 'admin' ); ?></label>
					</th>
					<td>
						<?php if ( 'openrouter' === $key ) : ?>
							<input type="text" id="provider_<?php echo esc_attr( $key ); ?>_model" name="provider_<?php echo esc_attr( $key ); ?>_model" value="<?php echo esc_attr( $p['model'] ); ?>" placeholder="e.g. meta-llama/llama-3-8b-instruct:free">
						<?php else : ?>
							<select id="provider_<?php echo esc_attr( $key ); ?>_model" name="provider_<?php echo esc_attr( $key ); ?>_model">
								<?php foreach ( $model_options[ $key ] as $model_val => $model_label ) : ?>
									<option value="<?php echo esc_attr( $model_val ); ?>" <?php selected( $p['model'], $model_val ); ?>><?php echo esc_html( $model_label ); ?></option>
								<?php endforeach; ?>
							</select>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<p>
				<button type="button" class="button kivor-chat-agent-test-connection" data-provider="<?php echo esc_attr( $key ); ?>">
					<?php esc_html_e( 'Test Connection', 'admin' ); ?>
				</button>
			</p>
			<div id="kivor-chat-agent-test-result-<?php echo esc_attr( $key ); ?>" class="kivor-chat-agent-test-result"></div>
			</div>
		</details>
		<?php endforeach; ?>
	</div>

	<?php submit_button( __( 'Save AI Provider Settings', 'admin' ) ); ?>
</form>
