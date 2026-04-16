<?php
/**
 * Embeddings management page.
 *
 * @package KivorAgent
 * @since   1.0.0
 * @var array $settings All plugin settings.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( ! class_exists( 'Kivor_Embeddings_List_Table' ) ) {
	require_once KIVOR_AGENT_PATH . 'admin/class-kivor-embeddings-list-table.php';
}

$table = new Kivor_Embeddings_List_Table( $settings['embedding'] ?? array() );
$table->prepare_items();
$embeddings_submenu_locked = Kivor_Feature_Gates::is_feature_available( 'embeddings_submenu' ) ? false : true;
?>

<p class="description"><?php esc_html_e( 'View and manage embeddings currently stored in your configured vector backend.', 'admin' ); ?></p>

<?php if ( $embeddings_submenu_locked ) : ?>
	<?php
	Kivor_Feature_Gates::render_lock_notice(
		__( 'Embeddings Management Is Available in Pro', 'admin' ),
		''
	);
	?>
<?php endif; ?>

<div class="kivor-chat-agent-embeddings-actions" style="margin: 12px 0 16px; display: flex; gap: 8px; flex-wrap: wrap;">
	<button type="button" id="kivor-embeddings-sync-products" class="button button-primary" <?php disabled( $embeddings_submenu_locked ); ?>>
		<?php esc_html_e( 'Manual Sync Products', 'admin' ); ?>
	</button>
	<button type="button" id="kivor-embeddings-sync-kb" class="button" <?php disabled( $embeddings_submenu_locked ); ?>>
		<?php esc_html_e( 'Manual Sync Knowledge Base', 'admin' ); ?>
	</button>
</div>

<div id="kivor-embeddings-progress-wrap" style="display:none; margin: 10px 0 14px; max-width: 640px;">
	<div style="display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom: 6px;">
		<div id="kivor-embeddings-progress-label" class="description"><?php esc_html_e( 'Starting sync...', 'admin' ); ?></div>
		<span id="kivor-embeddings-progress-type" style="display:inline-block; font-size:11px; line-height:1; background:#e7f1ff; color:#0a4b78; border:1px solid #c5ddff; border-radius:999px; padding:4px 8px;"><?php esc_html_e( 'Sync', 'admin' ); ?></span>
	</div>
	<div style="background:#f0f0f1; border-radius: 999px; overflow: hidden; height: 10px;">
		<div id="kivor-embeddings-progress-bar" style="height:10px; width:0%; background:#2271b1; transition: width .25s ease;"></div>
	</div>
	<div id="kivor-embeddings-progress-meta" class="description" style="margin-top: 6px;"></div>
</div>

<div id="kivor-embeddings-sync-products-result" class="kivor-chat-agent-test-result"></div>
<div id="kivor-embeddings-sync-kb-result" class="kivor-chat-agent-test-result"></div>

<?php
$embedding_settings = $settings['embedding'] ?? array();
$vector_store       = (string) ( $embedding_settings['vector_store'] ?? 'local' );

$store_labels = array(
	'pinecone' => __( 'Pinecone', 'admin' ),
	'qdrant'   => __( 'Qdrant', 'admin' ),
);

$available_stores = array();
foreach ( array( 'pinecone', 'qdrant' ) as $store_key ) {
	$store_obj = Kivor_Sync_Manager::create_vector_store( $store_key, $embedding_settings );
	if ( is_wp_error( $store_obj ) ) {
		continue;
	}
	$available_stores[ $store_key ] = $store_obj;
}

$tabs_nonce      = wp_create_nonce( 'kivor_chat_agent_embeddings_tabs' );
$has_tabs_nonce  = isset( $_GET['_kivor_embeddings_tab_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_kivor_embeddings_tab_nonce'] ) ), 'kivor_chat_agent_embeddings_tabs' );

$active_store_tab = $vector_store;
$view_tab         = 'active';
if ( $has_tabs_nonce ) {
	$active_store_tab = isset( $_GET['store_tab'] ) ? sanitize_key( sanitize_text_field( wp_unslash( $_GET['store_tab'] ) ) ) : $vector_store;
	$view_tab         = isset( $_GET['view_tab'] ) ? sanitize_key( sanitize_text_field( wp_unslash( $_GET['view_tab'] ) ) ) : 'active';
}

if ( ! in_array( $view_tab, array( 'active', 'stores' ), true ) ) {
	$view_tab = 'active';
}

if ( ! isset( $available_stores[ $active_store_tab ] ) ) {
	$active_store_tab = ! empty( $available_stores ) ? (string) array_key_first( $available_stores ) : '';
}
?>

<nav class="nav-tab-wrapper" style="margin-top:24px; margin-bottom:12px;">
	<?php
	$active_tab_url = add_query_arg(
		array(
			'page'     => 'kivor-chat-agent-embeddings',
			'view_tab' => 'active',
			'_kivor_embeddings_tab_nonce' => $tabs_nonce,
		),
		admin_url( 'admin.php' )
	);
	$stores_tab_url = add_query_arg(
		array(
			'page'      => 'kivor-chat-agent-embeddings',
			'view_tab'  => 'stores',
			'store_tab' => $active_store_tab,
			'_kivor_embeddings_tab_nonce' => $tabs_nonce
		),
		admin_url( 'admin.php' )
	);
	?>
	<a href="<?php echo esc_url( $active_tab_url ); ?>" class="nav-tab <?php echo 'active' === $view_tab ? 'nav-tab-active' : ''; ?>">
		<?php esc_html_e( 'Active Store Listing', 'admin' ); ?>
	</a>
	<a href="<?php echo esc_url( $stores_tab_url ); ?>" class="nav-tab <?php echo 'stores' === $view_tab ? 'nav-tab-active' : ''; ?>">
		<?php esc_html_e( 'Vector Store Details', 'admin' ); ?>
	</a>
</nav>

<?php if ( 'stores' === $view_tab ) : ?>
	<?php if ( ! empty( $available_stores ) ) : ?>
		<nav class="nav-tab-wrapper" style="margin-bottom:12px;">
			<?php foreach ( $available_stores as $store_key => $store_obj ) : ?>
				<?php
				$tab_url = add_query_arg(
					array(
						'page'      => 'kivor-chat-agent-embeddings',
						'view_tab'  => 'stores',
						'store_tab' => $store_key,
						'_kivor_embeddings_tab_nonce' => $tabs_nonce,
					),
					admin_url( 'admin.php' )
				);
				?>
				<a href="<?php echo esc_url( $tab_url ); ?>" class="nav-tab <?php echo $active_store_tab === $store_key ? 'nav-tab-active' : ''; ?>">
					<?php echo esc_html( $store_labels[ $store_key ] ?? ucfirst( $store_key ) ); ?>
				</a>
			<?php endforeach; ?>
		</nav>

		<?php if ( '' !== $active_store_tab && isset( $available_stores[ $active_store_tab ] ) ) : ?>
			<?php
			$active_store = $available_stores[ $active_store_tab ];
			$total_count  = (int) $active_store->count();
			$rows_result  = $active_store->get_all_vectors( '', 20, 0 );
			$store_ok     = ! is_wp_error( $rows_result );
			$rows         = is_array( $rows_result ) ? $rows_result : array();
			?>
			<p class="description" style="margin-bottom:8px;">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: store name, 2: total vectors */
						__( '%1$s currently has %2$d vectors.', 'admin' ),
						$store_labels[ $active_store_tab ] ?? ucfirst( $active_store_tab ),
						$total_count
					)
				);
				?>
			</p>
			<?php if ( ! $store_ok ) : ?>
				<div class="notice notice-error inline"><p><?php echo esc_html( is_wp_error( $rows_result ) ? $rows_result->get_error_message() : __( 'Could not fetch vectors.', 'admin' ) ); ?></p></div>
			<?php else : ?>
				<table class="widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Object ID', 'admin' ); ?></th>
							<th><?php esc_html_e( 'Type', 'admin' ); ?></th>
							<th><?php esc_html_e( 'Title', 'admin' ); ?></th>
							<th><?php esc_html_e( 'Provider', 'admin' ); ?></th>
							<th><?php esc_html_e( 'Updated', 'admin' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $rows ) ) : ?>
							<tr><td colspan="5"><?php esc_html_e( 'No vectors found for this store.', 'admin' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $rows as $row ) : ?>
								<?php $meta = is_array( $row['metadata'] ?? null ) ? $row['metadata'] : array(); ?>
								<tr>
									<td><?php echo esc_html( (string) ( $row['object_id'] ?? '' ) ); ?></td>
									<td><?php echo esc_html( (string) ( $row['object_type'] ?? '' ) ); ?></td>
									<td><?php echo esc_html( (string) ( $meta['title'] ?? $meta['name'] ?? '' ) ); ?></td>
									<td><?php echo esc_html( (string) ( $meta['provider'] ?? '' ) ); ?></td>
									<td><?php echo esc_html( (string) ( $row['updated_at'] ?? '' ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			<?php endif; ?>
		<?php endif; ?>
	<?php else : ?>
		<div class="notice notice-warning inline"><p><?php esc_html_e( 'No vector stores are currently configured.', 'admin' ); ?></p></div>
	<?php endif; ?>
<?php else : ?>
	<form method="post">
		<?php $table->display(); ?>
	</form>
<?php endif; ?>
