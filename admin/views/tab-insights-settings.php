<?php
/**
 * Insights analytics settings tab.
 *
 * @package KivorAgent
 * @since   1.0.0
 * @var array $settings All plugin settings.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$analytics = $settings['analytics'] ?? array();
$ai        = $settings['ai_provider'] ?? array();
$providers = $ai['providers'] ?? array();
$is_wc     = class_exists( 'WooCommerce' );
$insights_locked = Kivor_Feature_Gates::is_feature_available( 'analytics_insights' ) ? false : true;
?>

<?php if ( $insights_locked ) : ?>
	<?php
	Kivor_Feature_Gates::render_lock_notice(
		__( 'Insights Are Available in Pro', 'admin' ),
		__( 'Analytics configuration and insights are read-only on the free plan.', 'admin' )
	);
	?>
<?php endif; ?>

<form method="post" action="">
	<?php wp_nonce_field( 'kivor_chat_agent_save_analytics', '_kivor_chat_agent_nonce' ); ?>
	<input type="hidden" name="kivor_chat_agent_save_settings" value="analytics">

	<h2><?php esc_html_e( 'Analytics Configuration', 'admin' ); ?></h2>

	<fieldset <?php disabled( $insights_locked ); ?>>
	<table class="form-table">
		<tr>
			<th scope="row"><?php esc_html_e( 'Enable Analytics', 'admin' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="analytics_enabled" value="1" <?php checked( ! empty( $analytics['enabled'] ) ); ?>>
					<?php esc_html_e( 'Enable sentiment and conversion analytics', 'admin' ); ?>
				</label>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="analytics_provider"><?php esc_html_e( 'Sentiment Provider', 'admin' ); ?></label></th>
			<td>
				<?php
				$analytics_provider = (string) ( $analytics['provider'] ?? 'openai' );
				$enabled_options    = array();
				foreach ( array( 'openai', 'gemini', 'openrouter' ) as $provider_key ) {
					if ( ! empty( $providers[ $provider_key ]['enabled'] ) ) {
						$enabled_options[] = $provider_key;
					}
				}
				if ( ! in_array( $analytics_provider, $enabled_options, true ) ) {
					$analytics_provider = ! empty( $enabled_options ) ? $enabled_options[0] : 'openai';
				}
				?>
				<select id="analytics_provider" name="analytics_provider">
					<option value="openai" <?php selected( $analytics_provider, 'openai' ); ?> <?php disabled( empty( $providers['openai']['enabled'] ) ); ?>><?php esc_html_e( 'OpenAI', 'admin' ); ?></option>
					<option value="gemini" <?php selected( $analytics_provider, 'gemini' ); ?> <?php disabled( empty( $providers['gemini']['enabled'] ) ); ?>><?php esc_html_e( 'Gemini', 'admin' ); ?></option>
					<option value="openrouter" <?php selected( $analytics_provider, 'openrouter' ); ?> <?php disabled( empty( $providers['openrouter']['enabled'] ) ); ?>><?php esc_html_e( 'OpenRouter', 'admin' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'Provider used only for sentiment classification.', 'admin' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="analytics_analyze_mode"><?php esc_html_e( 'Analyze Mode', 'admin' ); ?></label></th>
			<td>
				<select id="analytics_analyze_mode" name="analytics_analyze_mode">
					<option value="first_message" <?php selected( $analytics['analyze_mode'] ?? 'first_message', 'first_message' ); ?>><?php esc_html_e( 'First message per session', 'admin' ); ?></option>
					<option value="every_message" <?php selected( $analytics['analyze_mode'] ?? 'first_message', 'every_message' ); ?>><?php esc_html_e( 'Every user message', 'admin' ); ?></option>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="analytics_attribution_days"><?php esc_html_e( 'Attribution Window', 'admin' ); ?></label></th>
			<td>
				<input type="number" id="analytics_attribution_days" name="analytics_attribution_days" min="7" max="30" value="<?php echo esc_attr( (string) ( $analytics['attribution_days'] ?? 14 ) ); ?>" style="width:90px;">
				<span class="description"><?php esc_html_e( 'days (7-30)', 'admin' ); ?></span>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="analytics_alert_threshold"><?php esc_html_e( 'Negative Alert Threshold', 'admin' ); ?></label></th>
			<td>
				<input type="number" id="analytics_alert_threshold" name="analytics_alert_threshold" min="1" max="100" value="<?php echo esc_attr( (string) ( $analytics['alert_threshold'] ?? 30 ) ); ?>" style="width:90px;">
				<span class="description">%</span>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="analytics_alert_email"><?php esc_html_e( 'Alert Email', 'admin' ); ?></label></th>
			<td>
				<input type="email" id="analytics_alert_email" name="analytics_alert_email" value="<?php echo esc_attr( (string) ( $analytics['alert_email'] ?? '' ) ); ?>" class="regular-text">
				<p class="description"><?php esc_html_e( 'Leave blank to use WordPress admin email.', 'admin' ); ?></p>
			</td>
		</tr>
	</table>

	<?php if ( ! $is_wc ) : ?>
		<div class="kivor-chat-agent-wc-notice">
			<?php esc_html_e( 'WooCommerce is not active. Purchase conversion tracking will activate automatically when WooCommerce is installed.', 'admin' ); ?>
		</div>
	<?php endif; ?>

	<?php submit_button( __( 'Save Analytics Settings', 'admin' ) ); ?>
	</fieldset>
</form>
