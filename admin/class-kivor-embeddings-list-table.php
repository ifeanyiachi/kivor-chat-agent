<?php
/**
 * Embeddings list table.
 *
 * @package KivorAgent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Kivor_Embeddings_List_Table extends WP_List_Table {

	/**
	 * Embedding settings.
	 *
	 * @var array
	 */
	private array $embedding_settings;

	/**
	 * Constructor.
	 *
	 * @param array $embedding_settings Embedding settings.
	 */
	public function __construct( array $embedding_settings ) {
		$this->embedding_settings = $embedding_settings;

		parent::__construct(
			array(
				'singular' => 'embedding',
				'plural'   => 'embeddings',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns(): array {
		return array(
			'cb'           => '<input type="checkbox" />',
			'id'           => __( 'Object ID', 'admin' ),
			'type'         => __( 'Type', 'admin' ),
			'title'        => __( 'Title', 'admin' ),
			'provider'     => __( 'Provider', 'admin' ),
			'content_hash' => __( 'Content Hash', 'admin' ),
			'synced_at'    => __( 'Synced At', 'admin' ),
			'actions'      => __( 'Actions', 'admin' ),
		);
	}

	/**
	 * Prepare table data.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		$per_page = 20;
		$paged    = $this->get_pagenum();
		$offset   = max( 0, ( $paged - 1 ) * $per_page );

		$vectors = array();
		$total   = 0;

		$store = Kivor_Sync_Manager::create_vector_store(
			(string) ( $this->embedding_settings['vector_store'] ?? 'local' ),
			$this->embedding_settings
		);

		if ( ! is_wp_error( $store ) ) {
			$fetched = $store->get_all_vectors( '', $per_page, $offset );
			if ( ! is_wp_error( $fetched ) ) {
				$vectors = is_array( $fetched ) ? $fetched : array();
			}
			$total = $store->count();
		}

		$this->items = array_map( array( $this, 'normalize_row' ), $vectors );

		$this->_column_headers = array(
			$this->get_columns(),
			array(),
			array(),
		);

		$this->set_pagination_args(
			array(
				'total_items' => max( 0, (int) $total ),
				'per_page'    => $per_page,
				'total_pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 1,
			)
		);
	}

	/**
	 * Checkbox column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_cb( $item ): string {
		$key = (string) $item['type'] . ':' . (string) $item['id'];
		return '<input type="checkbox" name="embedding_ids[]" value="' . esc_attr( $key ) . '" />';
	}

	/**
	 * Render title column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_title( array $item ): string {
		$title = (string) $item['title'];
		return '' === $title ? '&mdash;' : esc_html( $title );
	}

	/**
	 * Render actions.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_actions( array $item ): string {
		$id   = (int) $item['id'];
		$type = sanitize_key( (string) $item['type'] );

		return sprintf(
			'<button type="button" class="button button-small kivor-embeddings-sync-one" data-id="%1$d" data-type="%2$s">%3$s</button> <button type="button" class="button button-small button-link-delete kivor-embeddings-delete-one" data-id="%1$d" data-type="%2$s">%4$s</button>',
			$id,
			esc_attr( $type ),
			esc_html__( 'Sync', 'admin' ),
			esc_html__( 'Delete', 'admin' )
		);
	}

	/**
	 * Default column renderer.
	 *
	 * @param array  $item        Item.
	 * @param string $column_name Column.
	 * @return string
	 */
	public function column_default( $item, $column_name ): string {
		$value = $item[ $column_name ] ?? '';

		if ( 'synced_at' === $column_name ) {
			$timestamp = strtotime( (string) $value );
			return $timestamp ? esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) ) : '&mdash;';
		}

		if ( 'content_hash' === $column_name ) {
			$hash = (string) $value;
			return '' === $hash ? '&mdash;' : '<code>' . esc_html( substr( $hash, 0, 12 ) ) . '</code>';
		}

		return '' === (string) $value ? '&mdash;' : esc_html( (string) $value );
	}

	/**
	 * No-items text.
	 *
	 * @return void
	 */
	public function no_items(): void {
		esc_html_e( 'No embeddings found.', 'admin' );
	}

	/**
	 * Normalize raw vector row for rendering.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	private function normalize_row( array $row ): array {
		$metadata = is_array( $row['metadata'] ?? null ) ? $row['metadata'] : array();

		return array(
			'id'           => (int) ( $row['object_id'] ?? 0 ),
			'type'         => (string) ( $row['object_type'] ?? '' ),
			'title'        => (string) ( $metadata['title'] ?? $metadata['name'] ?? '' ),
			'provider'     => (string) ( $metadata['provider'] ?? $this->embedding_settings['active_provider'] ?? '' ),
			'content_hash' => (string) ( $row['content_hash'] ?? '' ),
			'synced_at'    => (string) ( $row['updated_at'] ?? '' ),
			'actions'      => '',
		);
	}
}
