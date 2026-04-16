<?php
/**
 * Voice tab.
 *
 * @package KivorAgent
 * @since   1.1.0
 * @var array $settings All plugin settings.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$voice = $settings['voice'] ?? array();

$providers = $voice['providers'] ?? array();
$openai    = $providers['openai'] ?? array();
$cartesia  = $providers['cartesia'] ?? array();
$deepgram  = $providers['deepgram'] ?? array();
$limits    = $voice['limits'] ?? array();

$masked_openai   = Kivor_Admin::mask_key( (string) ( $openai['api_key'] ?? '' ) );
$masked_cartesia = Kivor_Admin::mask_key( (string) ( $cartesia['api_key'] ?? '' ) );
$masked_deepgram = Kivor_Admin::mask_key( (string) ( $deepgram['api_key'] ?? '' ) );
$voice_locked = Kivor_Feature_Gates::is_feature_available( 'voice' ) ? false : true;
?>

<?php if ( $voice_locked ) : ?>
	<?php
	Kivor_Feature_Gates::render_lock_notice(
		__( 'Voice Is Available in Pro', 'admin' ),
		''
	);
	?>
<?php endif; ?>

<form method="post" action="">
	<?php wp_nonce_field( 'kivor_chat_agent_save_voice', '_kivor_chat_agent_nonce' ); ?>
	<input type="hidden" name="kivor_chat_agent_save_settings" value="voice">

	<h2><?php esc_html_e( 'Voice', 'admin' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Configure voice settings for the Chat tab only.', 'admin' ); ?></p>
	<?php
	$provider_labels = array(
		'webspeech' => __( 'Web Speech API', 'admin' ),
		'openai'    => 'OpenAI',
		'cartesia'  => 'Cartesia',
		'deepgram'  => 'Deepgram',
	);
	$current_stt = (string) ( $voice['stt_provider'] ?? 'webspeech' );
	$current_stt_label = $provider_labels[ $current_stt ] ?? $current_stt;
	?>
	<fieldset <?php disabled( $voice_locked ); ?>>

	<div class="kivor-chat-agent-voice-summary">
		<p><strong><?php esc_html_e( 'Quick setup', 'admin' ); ?></strong></p>
		<ul>
			<li><?php esc_html_e( '1) Enable voice input.', 'admin' ); ?></li>
			<li><?php esc_html_e( '2) Pick STT provider.', 'admin' ); ?></li>
			<li><?php esc_html_e( '3) Open the matching provider card below and complete required fields.', 'admin' ); ?></li>
		</ul>
		<p class="kivor-chat-agent-voice-summary__chips">
			<?php /* translators: %s: current speech-to-text provider label. */ ?>
			<span><?php echo esc_html( sprintf( __( 'STT: %s', 'admin' ), $current_stt_label ) ); ?></span>
		</p>
	</div>

	<table class="form-table">
		<tr>
			<th scope="row"><?php esc_html_e( 'Voice Input', 'admin' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="voice_input_enabled" value="1" <?php checked( ! empty( $voice['input_enabled'] ) ); ?>>
					<?php esc_html_e( 'Allow microphone input (STT)', 'admin' ); ?>
				</label>
			</td>
		</tr>
	</table>

	<div id="kivor-chat-agent-voice-fields" <?php echo empty( $voice['input_enabled'] ) ? 'style="display:none;"' : ''; ?>>
		<h3><?php esc_html_e( 'Interaction', 'admin' ); ?></h3>
		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="voice_interaction_mode"><?php esc_html_e( 'Interaction Mode', 'admin' ); ?></label>
				</th>
				<td>
					<select id="voice_interaction_mode" name="voice_interaction_mode">
						<option value="push_to_talk" <?php selected( $voice['interaction_mode'] ?? 'push_to_talk', 'push_to_talk' ); ?>><?php esc_html_e( 'Push to talk', 'admin' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="voice_auto_send_mode"><?php esc_html_e( 'Auto Send', 'admin' ); ?></label>
				</th>
				<td>
					<select id="voice_auto_send_mode" name="voice_auto_send_mode">
						<option value="silence" <?php selected( $voice['auto_send_mode'] ?? 'silence', 'silence' ); ?>><?php esc_html_e( 'Send after silence', 'admin' ); ?></option>
						<option value="manual" <?php selected( $voice['auto_send_mode'] ?? 'silence', 'manual' ); ?>><?php esc_html_e( 'Manual send only', 'admin' ); ?></option>
					</select>
				</td>
			</tr>
			<tr id="kivor-chat-agent-voice-delay-row">
				<th scope="row">
					<label for="voice_auto_send_delay_ms"><?php esc_html_e( 'Silence Delay (ms)', 'admin' ); ?></label>
				</th>
				<td>
					<input type="number" id="voice_auto_send_delay_ms" name="voice_auto_send_delay_ms" value="<?php echo esc_attr( $voice['auto_send_delay_ms'] ?? 800 ); ?>" min="200" max="5000" step="50" style="width:120px;">
					<span class="description"><?php esc_html_e( 'Default 800ms', 'admin' ); ?></span>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="voice_confidence_threshold"><?php esc_html_e( 'STT Confidence Threshold', 'admin' ); ?></label>
				</th>
				<td>
					<input type="number" id="voice_confidence_threshold" name="voice_confidence_threshold" value="<?php echo esc_attr( $voice['confidence_threshold'] ?? 0.65 ); ?>" min="0" max="1" step="0.01" style="width:120px;">
					<p class="description"><?php esc_html_e( 'If transcript confidence is below threshold, text stays in input for manual send.', 'admin' ); ?></p>
				</td>
			</tr>
		</table>

		<h3><?php esc_html_e( 'Language', 'admin' ); ?></h3>
		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="voice_default_language"><?php esc_html_e( 'Default Language', 'admin' ); ?></label>
				</th>
				<td>
					<input type="text" id="voice_default_language" name="voice_default_language" value="<?php echo esc_attr( $voice['default_language'] ?? 'en-US' ); ?>" class="regular-text" placeholder="en-US">
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Auto-detect Language', 'admin' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="voice_auto_detect_language" value="1" <?php checked( ! empty( $voice['auto_detect_language'] ) ); ?>>
						<?php esc_html_e( 'Try browser language first when available', 'admin' ); ?>
					</label>
				</td>
			</tr>
		</table>

		<h3><?php esc_html_e( 'Speech to Text (STT)', 'admin' ); ?></h3>
		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="voice_stt_provider"><?php esc_html_e( 'STT Provider', 'admin' ); ?></label>
				</th>
				<td>
					<select id="voice_stt_provider" name="voice_stt_provider">
						<option value="webspeech" <?php selected( $voice['stt_provider'] ?? 'webspeech', 'webspeech' ); ?>><?php esc_html_e( 'Web Speech API', 'admin' ); ?></option>
						<option value="openai" <?php selected( $voice['stt_provider'] ?? 'webspeech', 'openai' ); ?>>OpenAI</option>
						<option value="cartesia" <?php selected( $voice['stt_provider'] ?? 'webspeech', 'cartesia' ); ?>>Cartesia</option>
						<option value="deepgram" <?php selected( $voice['stt_provider'] ?? 'webspeech', 'deepgram' ); ?>>Deepgram</option>					</select>
					<p id="kivor-chat-agent-stt-provider-hint" class="kivor-chat-agent-provider-hint" aria-live="polite"></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="voice_stt_model"><?php esc_html_e( 'STT Model (Fallback)', 'admin' ); ?></label>
				</th>
				<td>
					<input type="text" id="voice_stt_model" name="voice_stt_model" value="<?php echo esc_attr( $voice['stt_model'] ?? '' ); ?>" class="regular-text" placeholder="auto / whisper-1 / ink-whisper / nova-2 / chirp">
					<p class="description"><?php esc_html_e( 'Used only if provider-specific STT model is not set below.', 'admin' ); ?></p>
				</td>
			</tr>
		</table>


		<h3><?php esc_html_e( 'Provider Setup (Required Fields)', 'admin' ); ?></h3>
		<p class="description"><?php esc_html_e( 'Each provider is grouped below with required fields for STT.', 'admin' ); ?></p>

		<div class="kivor-chat-agent-voice-accordion">
			<details class="kivor-chat-agent-voice-provider <?php echo ( 'webspeech' === $current_stt ) ? 'is-active' : ''; ?>" data-provider="webspeech">
				<summary>
					<span><?php esc_html_e( 'Web Speech API (Browser)', 'admin' ); ?></span>
					<span class="kivor-chat-agent-voice-provider__state"></span>
				</summary>
				<div class="kivor-chat-agent-voice-provider__body">
					<p><?php esc_html_e( 'Required fields: none. Uses the browser and OS speech engines directly.', 'admin' ); ?></p>
				</div>
			</details>

			<details class="kivor-chat-agent-voice-provider <?php echo ( 'openai' === $current_stt ) ? 'is-active' : ''; ?>" data-provider="openai">
				<summary>
					<span><?php esc_html_e( 'OpenAI', 'admin' ); ?></span>
					<span class="kivor-chat-agent-voice-provider__state"></span>
				</summary>
				<div class="kivor-chat-agent-voice-provider__body">
					<table class="form-table">
						<tr>
							<th scope="row"><label for="voice_openai_api_key"><?php esc_html_e( 'API Key (required)', 'admin' ); ?></label></th>
							<td><input type="password" id="voice_openai_api_key" name="voice_openai_api_key" value="<?php echo esc_attr( $masked_openai ); ?>" autocomplete="off" class="regular-text"></td>
						</tr>
						<tr>
							<th scope="row"><label for="voice_openai_stt_model"><?php esc_html_e( 'STT Model', 'admin' ); ?></label></th>
							<td><input type="text" id="voice_openai_stt_model" name="voice_openai_stt_model" value="<?php echo esc_attr( $openai['stt_model'] ?? 'gpt-4o-mini-transcribe' ); ?>" class="regular-text"></td>
						</tr>
					</table>
				</div>
			</details>

			<details class="kivor-chat-agent-voice-provider <?php echo ( 'cartesia' === $current_stt ) ? 'is-active' : ''; ?>" data-provider="cartesia">
				<summary>
					<span><?php esc_html_e( 'Cartesia', 'admin' ); ?></span>
					<span class="kivor-chat-agent-voice-provider__state"></span>
				</summary>
				<div class="kivor-chat-agent-voice-provider__body">
					<table class="form-table">
						<tr>
							<th scope="row"><label for="voice_cartesia_api_key"><?php esc_html_e( 'API Key (required)', 'admin' ); ?></label></th>
							<td><input type="password" id="voice_cartesia_api_key" name="voice_cartesia_api_key" value="<?php echo esc_attr( $masked_cartesia ); ?>" autocomplete="off" class="regular-text"></td>
						</tr>
						<tr>
							<th scope="row"><label for="voice_cartesia_version"><?php esc_html_e( 'Cartesia Version (required)', 'admin' ); ?></label></th>
							<td><input type="text" id="voice_cartesia_version" name="voice_cartesia_version" value="<?php echo esc_attr( $cartesia['version'] ?? '2025-04-16' ); ?>" class="regular-text" placeholder="2025-04-16"></td>
						</tr>
						<tr>
							<th scope="row"><label for="voice_cartesia_stt_model"><?php esc_html_e( 'STT Model', 'admin' ); ?></label></th>
							<td><input type="text" id="voice_cartesia_stt_model" name="voice_cartesia_stt_model" value="<?php echo esc_attr( $cartesia['stt_model'] ?? 'ink-whisper' ); ?>" class="regular-text"></td>
						</tr>
					</table>
				</div>
			</details>

			<details class="kivor-chat-agent-voice-provider <?php echo ( 'deepgram' === $current_stt ) ? 'is-active' : ''; ?>" data-provider="deepgram">
				<summary>
					<span><?php esc_html_e( 'Deepgram', 'admin' ); ?></span>
					<span class="kivor-chat-agent-voice-provider__state"></span>
				</summary>
				<div class="kivor-chat-agent-voice-provider__body">
					<table class="form-table">
						<tr>
							<th scope="row"><label for="voice_deepgram_api_key"><?php esc_html_e( 'API Key (required)', 'admin' ); ?></label></th>
							<td><input type="password" id="voice_deepgram_api_key" name="voice_deepgram_api_key" value="<?php echo esc_attr( $masked_deepgram ); ?>" autocomplete="off" class="regular-text"></td>
						</tr>
						<tr>
							<th scope="row"><label for="voice_deepgram_stt_model"><?php esc_html_e( 'STT Model', 'admin' ); ?></label></th>
							<td><input type="text" id="voice_deepgram_stt_model" name="voice_deepgram_stt_model" value="<?php echo esc_attr( $deepgram['stt_model'] ?? 'nova-2' ); ?>" class="regular-text"></td>
						</tr>
					</table>
				</div>
			</details>

		</div>

		<h3><?php esc_html_e( 'Cost Guardrails', 'admin' ); ?></h3>
		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="voice_max_stt_seconds"><?php esc_html_e( 'Max STT Seconds / Turn', 'admin' ); ?></label>
				</th>
				<td>
					<input type="number" id="voice_max_stt_seconds" name="voice_max_stt_seconds" value="<?php echo esc_attr( $limits['max_stt_seconds'] ?? 20 ); ?>" min="5" max="120" step="1" style="width:120px;">
				</td>
			</tr>
		</table>
	</div>

	<?php submit_button( __( 'Save Voice Settings', 'admin' ) ); ?>
	</fieldset>
</form>
