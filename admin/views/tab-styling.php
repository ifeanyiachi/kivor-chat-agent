<?php
/**
 * Chatbot styling tab.
 *
 * @package KivorAgent
 * @since   1.0.0
 * @var array $settings All plugin settings.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$appearance = $settings['appearance'];
$styling_locked = Kivor_Feature_Gates::is_feature_available( 'advanced_styling' ) ? false : true;

$default_colors = array(
	'widget_primary_color'         => '#10b981',
	'widget_primary_hover_color'   => '#059669',
	'widget_primary_text_color'    => '#ffffff',
	'widget_background_color'      => '#ffffff',
	'widget_background_alt_color'  => '#f3f4f6',
	'widget_text_color'            => '#1f2937',
	'widget_text_muted_color'      => '#6b7280',
	'widget_border_color'          => '#e5e7eb',
	'widget_user_bubble_color'     => '#10b981',
	'widget_user_text_color'       => '#ffffff',
	'widget_bot_bubble_color'      => '#f3f4f6',
	'widget_bot_text_color'        => '#1f2937',
	'widget_tab_background_color'  => '#ffffff',
	'widget_tab_text_color'        => '#374151',
	'widget_tab_active_color'      => '#10b981',
	'widget_tab_active_text_color' => '#10b981',
);

foreach ( $default_colors as $key => $value ) {
	if ( empty( $appearance[ $key ] ) ) {
		$appearance[ $key ] = $value;
	}
}

$color_fields = array(
	'widget_primary_color'         => __( 'Primary Color', 'admin' ),
	'widget_primary_hover_color'   => __( 'Primary Hover Color', 'admin' ),
	'widget_primary_text_color'    => __( 'Primary Text Color', 'admin' ),
	'widget_background_color'      => __( 'Widget Background', 'admin' ),
	'widget_background_alt_color'  => __( 'Secondary Background', 'admin' ),
	'widget_text_color'            => __( 'Text Color', 'admin' ),
	'widget_text_muted_color'      => __( 'Muted Text Color', 'admin' ),
	'widget_border_color'          => __( 'Border Color', 'admin' ),
	'widget_user_bubble_color'     => __( 'User Bubble Color', 'admin' ),
	'widget_user_text_color'       => __( 'User Bubble Text', 'admin' ),
	'widget_bot_bubble_color'      => __( 'Bot Bubble Color', 'admin' ),
	'widget_bot_text_color'        => __( 'Bot Bubble Text', 'admin' ),
	'widget_tab_background_color'  => __( 'Bottom Tabs Background', 'admin' ),
	'widget_tab_text_color'        => __( 'Bottom Tabs Text', 'admin' ),
	'widget_tab_active_color'      => __( 'Bottom Tabs Active Color', 'admin' ),
	'widget_tab_active_text_color' => __( 'Bottom Tabs Active Text', 'admin' ),
);
?>

<form method="post" action="">
	<?php wp_nonce_field( 'kivor_chat_agent_save_styling', '_kivor_chat_agent_nonce' ); ?>
	<input type="hidden" name="kivor_chat_agent_save_settings" value="styling">

	<h2><?php esc_html_e( 'Chatbot Styling', 'admin' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Let users control brand colors while keeping sensible defaults and automatic readability checks.', 'admin' ); ?></p>

	<?php if ( $styling_locked ) : ?>
		<?php
		Kivor_Feature_Gates::render_lock_notice(
			__( 'Advanced Styling Is Available in Pro', 'admin' ),
			''
		);
		?>
	<?php endif; ?>

	<fieldset <?php disabled( $styling_locked ); ?>>

	<div class="kivor-chat-agent-style-presets">
		<span class="kivor-chat-agent-style-presets__label"><?php esc_html_e( 'Quick Presets:', 'admin' ); ?></span>
		<button type="button" class="button" data-kivor-preset="emerald"><?php esc_html_e( 'Emerald', 'admin' ); ?></button>
		<button type="button" class="button" data-kivor-preset="ocean"><?php esc_html_e( 'Ocean', 'admin' ); ?></button>
		<button type="button" class="button" data-kivor-preset="sunset"><?php esc_html_e( 'Sunset', 'admin' ); ?></button>
		<button type="button" class="button" data-kivor-preset="slate"><?php esc_html_e( 'Slate', 'admin' ); ?></button>
		<button type="button" class="button button-secondary" data-kivor-preset="default"><?php esc_html_e( 'Reset to Default', 'admin' ); ?></button>
	</div>

	<div class="kivor-chat-agent-style-preview-wrap">
		<div id="kivor-chat-agent-style-preview" class="kivor-chat-agent-style-preview">
			<div class="kivor-chat-agent-style-preview__header">Chatbot</div>
			<div class="kivor-chat-agent-style-preview__intro">
				<div class="kivor-chat-agent-style-preview__title">We typically reply in a few minutes</div>
				<div class="kivor-chat-agent-style-preview__desc">We help your business grow by connecting you to your customers.</div>
			</div>
			<div class="kivor-chat-agent-style-preview__messages">
				<div class="kivor-chat-agent-style-preview__msg kivor-chat-agent-style-preview__msg--bot">Hi, welcome! You&rsquo;re speaking with AI Agent.</div>
				<div class="kivor-chat-agent-style-preview__msg kivor-chat-agent-style-preview__msg--user">Great, thanks!</div>
			</div>
			<div class="kivor-chat-agent-style-preview__footer">
				<span><?php esc_html_e( 'Type a message...', 'admin' ); ?></span>
				<span class="kivor-chat-agent-style-preview__send">&#9658;</span>
			</div>
			<div class="kivor-chat-agent-style-preview__tabs">
				<div class="kivor-chat-agent-style-preview__tab kivor-chat-agent-style-preview__tab--active">Chatbot</div>
				<div class="kivor-chat-agent-style-preview__tab">WhatsApp</div>
			</div>
		</div>

		<div class="kivor-chat-agent-style-contrast" id="kivor-chat-agent-style-contrast">
			<h3><?php esc_html_e( 'Accessibility Checks', 'admin' ); ?></h3>
			<ul>
				<li data-kivor-contrast-check="primary_text"><?php esc_html_e( 'Primary text on primary background', 'admin' ); ?></li>
				<li data-kivor-contrast-check="body_text"><?php esc_html_e( 'Body text on widget background', 'admin' ); ?></li>
				<li data-kivor-contrast-check="muted_text"><?php esc_html_e( 'Muted text on secondary background', 'admin' ); ?></li>
				<li data-kivor-contrast-check="user_bubble"><?php esc_html_e( 'User bubble text contrast', 'admin' ); ?></li>
				<li data-kivor-contrast-check="bot_bubble"><?php esc_html_e( 'Bot bubble text contrast', 'admin' ); ?></li>
			</ul>
			<p class="description"><?php esc_html_e( 'Target is WCAG AA (4.5:1) for normal-size text.', 'admin' ); ?></p>
		</div>
	</div>

	<table class="form-table">
		<?php foreach ( $color_fields as $key => $label ) : ?>
			<tr>
				<th scope="row">
					<label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
				</th>
				<td>
					<input type="color" id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $appearance[ $key ] ?? '' ); ?>" data-kivor-style-color="<?php echo esc_attr( $key ); ?>" data-kivor-default-color="<?php echo esc_attr( $default_colors[ $key ] ); ?>">
					<input type="text" class="regular-text" value="<?php echo esc_attr( $appearance[ $key ] ?? '' ); ?>" readonly>
				</td>
			</tr>
		<?php endforeach; ?>
	</table>

	<?php submit_button( __( 'Save Chatbot Styling', 'admin' ) ); ?>
	</fieldset>
</form>
