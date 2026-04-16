<?php
/**
 * Knowledge Base List Table.
 *
 * Extends WP_List_Table to display KB articles in the admin panel.
 *
 * @package KivorAgent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Kivor_KB_List_Table extends WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( array(
			'singular' => 'article',
			'plural'   => 'articles',
			'ajax'     => false,
		) );
	}

	/**
	 * Define table columns.
	 *
	 * @return array
	 */
	public function get_columns(): array {
		return array(
			'title'      => __( 'Title', 'admin' ),
			'characters' => __( 'Characters', 'admin' ),
			'source_url' => __( 'Source', 'admin' ),
			'sync_status' => __( 'Status', 'admin' ),
			'updated_at' => __( 'Updated', 'admin' ),
			'actions'    => __( 'Actions', 'admin' ),
		);
	}

	/**
	 * Define sortable columns.
	 *
	 * @return array
	 */
	public function get_sortable_columns(): array {
		return array(
			'title'      => array( 'title', false ),
			'updated_at' => array( 'updated_at', true ),
		);
	}

	/**
	 * Fetch items from the database.
	 */
	public function prepare_items(): void {
		global $wpdb;

		$table    = $wpdb->prefix . 'kivor_knowledge_base';
		$per_page = 20;
		$paged    = $this->get_pagenum();
		$offset   = ( $paged - 1 ) * $per_page;

		// Sortable columns.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$orderby = isset( $_GET['orderby'] ) ? sanitize_sql_orderby( $_GET['orderby'] . ' ASC' ) : null;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$order   = isset( $_GET['order'] ) && strtolower( $_GET['order'] ) === 'asc' ? 'ASC' : 'DESC';

		$valid_orderby = array( 'title', 'updated_at' );
		$orderby_col   = 'updated_at';
		if ( $orderby ) {
			$parts = explode( ' ', $orderby );
			if ( in_array( $parts[0], $valid_orderby, true ) ) {
				$orderby_col = $parts[0];
			}
		}

		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$this->items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY {$orderby_col} {$order} LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		);

		$this->set_pagination_args( array(
			'total_items' => $total,
			'per_page'    => $per_page,
			'total_pages' => ceil( $total / $per_page ),
		) );

		$this->_column_headers = array(
			$this->get_columns(),
			array(),
			$this->get_sortable_columns(),
		);
	}

	/**
	 * Default column renderer.
	 *
	 * @param array  $item        Row data.
	 * @param string $column_name Column key.
	 * @return string
	 */
	public function column_default( $item, $column_name ): string {
		return esc_html( $item[ $column_name ] ?? '' );
	}

	/**
	 * Title column.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_title( array $item ): string {
		return '<strong>' . esc_html( $item['title'] ) . '</strong>';
	}

	/**
	 * Characters column — show content length.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_characters( array $item ): string {
		$len = mb_strlen( $item['content'], 'UTF-8' );
		$class = $len > 4500 ? ' style="color:#dc3232;font-weight:600;"' : '';
		return '<span' . $class . '>' . number_format_i18n( $len ) . ' / 5,000</span>';
	}

	/**
	 * Source URL column.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_source_url( array $item ): string {
		$source_type = sanitize_key( (string) ( $item['source_type'] ?? 'manual' ) );
		$labels = array(
			'manual'  => __( 'Manual', 'admin' ),
			'wp_post' => __( 'WordPress Post', 'admin' ),
			'wp_page' => __( 'WordPress Page', 'admin' ),
			'zendesk' => __( 'Zendesk', 'admin' ),
			'notion'  => __( 'Notion', 'admin' ),
		);

		$label = $labels[ $source_type ] ?? __( 'External', 'admin' );

		$parts = array();
		$parts[] = '<em>' . esc_html( $label ) . '</em>';

		if ( ! empty( $item['source_id'] ) ) {
			$parts[] = '<code>' . esc_html( (string) $item['source_id'] ) . '</code>';
		}

		if ( empty( $item['source_url'] ) ) {
			return implode( '<br>', $parts );
		}

		$display = wp_parse_url( $item['source_url'], PHP_URL_HOST );
		if ( ! is_string( $display ) || '' === $display ) {
			$display = $item['source_url'];
		}

		$parts[] = '<a href="' . esc_url( $item['source_url'] ) . '" target="_blank" rel="noopener">' . esc_html( $display ) . '</a>';

		return implode( '<br>', $parts );
	}

	/**
	 * Sync status column.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_sync_status( array $item ): string {
		$status = sanitize_key( (string) ( $item['sync_status'] ?? 'synced' ) );
		$labels = array(
			'synced'          => __( 'Synced', 'admin' ),
			'failed'          => __( 'Failed', 'admin' ),
			'needs_review'    => __( 'Needs Review', 'admin' ),
			'conflict'        => __( 'Conflict', 'admin' ),
			'new_data'        => __( 'New Data', 'admin' ),
			'queued'          => __( 'Queued', 'admin' ),
			'processing'      => __( 'Processing', 'admin' ),
		);

		$label = $labels[ $status ] ?? __( 'Unknown', 'admin' );
		$class = 'kivor-chat-agent-status-ok';
		if ( in_array( $status, array( 'failed', 'conflict' ), true ) ) {
			$class = 'kivor-chat-agent-status-fail';
		}

		$output = '<span class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</span>';

		$meta = array();
		if ( ! empty( $item['import_method'] ) ) {
			$meta[] = sprintf(
				/* translators: %s: import method */
				__( 'Import: %s', 'admin' ),
				esc_html( ucfirst( (string) $item['import_method'] ) )
			);
		}

		if ( ! empty( $item['last_synced_at'] ) ) {
			$meta[] = sprintf(
				/* translators: %s: datetime */
				__( 'Synced: %s', 'admin' ),
				esc_html( gmdate( 'Y-m-d H:i', strtotime( (string) $item['last_synced_at'] ) ) . ' UTC' )
			);
		}

		if ( ! empty( $meta ) ) {
			$output .= '<br><span class="description">' . implode( ' | ', $meta ) . '</span>';
		}

		return $output;
	}

	/**
	 * Updated at column.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_updated_at( array $item ): string {
		$timestamp = strtotime( $item['updated_at'] );
		if ( ! $timestamp ) {
			return '&mdash;';
		}
		// Show human-readable diff if recent, otherwise date.
		$diff = time() - $timestamp;
		if ( $diff < 86400 ) {
			return esc_html( human_time_diff( $timestamp ) . ' ' . __( 'ago', 'admin' ) );
		}
		return esc_html( date_i18n( get_option( 'date_format' ), $timestamp ) );
	}

	/**
	 * Actions column — Edit + Delete buttons.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_actions( array $item ): string {
		$output  = '<button type="button" class="button button-small kivor-chat-agent-kb-edit" data-id="' . esc_attr( (string) $item['id'] ) . '">';
		$output .= esc_html__( 'Edit', 'admin' );
		$output .= '</button> ';
		$output .= '<button type="button" class="button button-small button-link-delete kivor-chat-agent-kb-delete" data-id="' . esc_attr( $item['id'] ) . '">';
		$output .= esc_html__( 'Delete', 'admin' );
		$output .= '</button>';

		return $output;
	}

	/**
	 * Message when no items are found.
	 */
	public function no_items(): void {
		esc_html_e( 'No articles found. Add one above or import from a URL.', 'admin' );
	}
}
