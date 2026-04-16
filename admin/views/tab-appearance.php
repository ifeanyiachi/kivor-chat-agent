<?php
/**
 * Appearance tab.
 *
 * @package KivorAgent
 * @since   1.0.0
 * @var array $settings All plugin settings.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$app = $settings['appearance'];
$general = $settings['general'];
$has_wc = class_exists( 'WooCommerce' );
?>

<?php if ( ! $has_wc ) : ?>
<div class="kivor-chat-agent-wc-notice">
	<?php esc_html_e( 'WooCommerce is not active. Product card appearance settings apply only when WooCommerce product recommendations are available.', 'admin' ); ?>
</div>
<?php endif; ?>

<form method="post" action="">
	<?php wp_nonce_field( 'kivor_chat_agent_save_appearance', '_kivor_chat_agent_nonce' ); ?>
	<input type="hidden" name="kivor_chat_agent_save_settings" value="appearance">

	<h2><?php esc_html_e( 'Product Card Appearance', 'admin' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Configure how product cards appear in the chat widget when products are recommended.', 'admin' ); ?></p>

	<table class="form-table">
		<tr>
			<th scope="row">
				<label for="product_card_layout"><?php esc_html_e( 'Card Layout', 'admin' ); ?></label>
			</th>
			<td>
				<select id="product_card_layout" name="product_card_layout">
					<option value="carousel" <?php selected( $app['product_card_layout'], 'carousel' ); ?>><?php esc_html_e( 'Carousel (horizontal scroll)', 'admin' ); ?></option>
					<option value="list" <?php selected( $app['product_card_layout'], 'list' ); ?>><?php esc_html_e( 'List (vertical stack)', 'admin' ); ?></option>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Show Elements', 'admin' ); ?></th>
			<td>
				<fieldset>
					<label>
						<input type="checkbox" name="product_card_show_image" value="1" <?php checked( $app['product_card_show_image'] ); ?>>
						<?php esc_html_e( 'Product Image', 'admin' ); ?>
					</label><br>
					<label>
						<input type="checkbox" name="product_card_show_price" value="1" <?php checked( $app['product_card_show_price'] ); ?>>
						<?php esc_html_e( 'Price', 'admin' ); ?>
					</label><br>
					<label>
						<input type="checkbox" name="product_card_show_link" value="1" <?php checked( $app['product_card_show_link'] ); ?>>
						<?php esc_html_e( 'View Product Link', 'admin' ); ?>
					</label><br>
					<label>
						<input type="checkbox" name="product_card_show_add_to_cart" value="1" <?php checked( $app['product_card_show_add_to_cart'] ); ?>>
						<?php esc_html_e( 'Add to Cart Button', 'admin' ); ?>
					</label>
				</fieldset>
			</td>
		</tr>
	</table>

	<hr>
	<h3><?php esc_html_e( 'Custom CSS', 'admin' ); ?></h3>
	<p class="description"><?php esc_html_e( 'Add custom CSS to style the chat widget. This CSS is injected on pages where the widget loads.', 'admin' ); ?></p>

	<table class="form-table">
		<tr>
			<th scope="row">
				<label for="custom_css"><?php esc_html_e( 'CSS Code', 'admin' ); ?></label>
			</th>
			<td>
				<textarea id="custom_css" name="custom_css" rows="10" class="large-text code"><?php echo esc_textarea( $general['custom_css'] ); ?></textarea>
			</td>
		</tr>
	</table>

	<?php submit_button( __( 'Save Appearance Settings', 'admin' ) ); ?>
</form>
