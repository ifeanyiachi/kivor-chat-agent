<?php
/**
 * Chat Logs List Table.
 *
 * Extends WP_List_Table to display chat log entries in the admin panel.
 * Supports filtering by session ID and date range via $_GET parameters.
 *
 * @package KivorAgent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Kivor_Logs_List_Table extends WP_List_Table {

	/**
	 * Whether current table is grouped by session.
	 *
	 * @var bool
	 */
	private bool $grouped_view = true;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( array(
			'singular' => 'log',
			'plural'   => 'logs',
			'ajax'     => false,
		) );
	}

	/**
	 * Define table columns.
	 *
	 * @return array
	 */
	public function get_columns(): array {
		if ( $this->grouped_view ) {
			return array(
				'session_id' => __( 'Session', 'admin' ),
				'messages'   => __( 'Messages', 'admin' ),
				'negative_messages' => __( 'Negative', 'admin' ),
				'last_role'  => __( 'Last Speaker', 'admin' ),
				'last_message' => __( 'Last Message', 'admin' ),
				'last_at'    => __( 'Last Activity', 'admin' ),
			);
		}

		return array(
			'session_id' => __( 'Session', 'admin' ),
			'role'       => __( 'Role', 'admin' ),
			'sentiment'  => __( 'Sentiment', 'admin' ),
			'message'    => __( 'Message', 'admin' ),
			'created_at' => __( 'Date', 'admin' ),
		);
	}

	/**
	 * Define sortable columns.
	 *
	 * @return array
	 */
	public function get_sortable_columns(): array {
		if ( $this->grouped_view ) {
			return array(
				'session_id' => array( 'session_id', false ),
				'messages'   => array( 'message_count', false ),
				'negative_messages' => array( 'negative_count', false ),
				'last_at'    => array( 'last_at', true ),
			);
		}

		return array(
			'session_id' => array( 'session_id', false ),
			'created_at' => array( 'created_at', true ),
		);
	}

	/**
	 * Fetch items from the database with optional filters.
	 */
	public function prepare_items(): void {
		global $wpdb;

		$table    = $wpdb->prefix . 'kivor_chat_logs';
		$per_page = 50;
		$paged    = $this->get_pagenum();
		$offset   = ( $paged - 1 ) * $per_page;

		$has_valid_filter_nonce = $this->has_valid_filter_nonce();

		$session_id = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $has_valid_filter_nonce && isset( $_GET['session_id'] ) ) {
			$session_id = sanitize_text_field( wp_unslash( $_GET['session_id'] ) );
		}

		$this->grouped_view = '' === $session_id;

		$date_from = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $has_valid_filter_nonce && isset( $_GET['date_from'] ) ) {
			$date_from = $this->normalize_date_filter( sanitize_text_field( wp_unslash( $_GET['date_from'] ) ), false );
		}

		$date_to = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $has_valid_filter_nonce && isset( $_GET['date_to'] ) ) {
			$date_to = $this->normalize_date_filter( sanitize_text_field( wp_unslash( $_GET['date_to'] ) ), true );
		}

		$requested_orderby = '';
		$order             = 'DESC';
		if ( $has_valid_filter_nonce ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( isset( $_GET['orderby'] ) ) {
				$requested_orderby = sanitize_text_field( wp_unslash( $_GET['orderby'] ) );
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_text_field( wp_unslash( $_GET['order'] ) ) ) ) {
				$order = 'ASC';
			}
		}

		$base_prepare_args = array(
			$table,
			$session_id,
			$session_id,
			$date_from,
			$date_from,
			$date_to,
			$date_to,
		);

		$where_sql = " WHERE (%s = '' OR session_id = %s) AND (%s = '' OR created_at >= %s) AND (%s = '' OR created_at <= %s)";

		if ( $this->grouped_view ) {
			$count_sql   = "SELECT COUNT(DISTINCT session_id) FROM %i" . $where_sql;
			// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
			$count_query = $wpdb->prepare( $count_sql, ...$base_prepare_args );
			$total       = (int) $wpdb->get_var( $count_query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

			$select_sql = "SELECT session_id, COUNT(*) AS message_count, SUM(CASE WHEN sentiment = 'negative' THEN 1 ELSE 0 END) AS negative_count, MAX(created_at) AS last_at, SUBSTRING_INDEX(GROUP_CONCAT(role ORDER BY created_at DESC SEPARATOR '||'), '||', 1) AS last_role, SUBSTRING_INDEX(GROUP_CONCAT(message ORDER BY created_at DESC SEPARATOR '||'), '||', 1) AS last_message FROM %i" . $where_sql . ' GROUP BY session_id';

			$order_column = 'last_at';
			switch ( $requested_orderby ) {
				case 'session_id':
					$order_column = 'session_id';
					break;
				case 'message_count':
				case 'messages':
					$order_column = 'message_count';
					break;
				case 'negative_count':
				case 'negative_messages':
					$order_column = 'negative_count';
					break;
				case 'last_at':
					$order_column = 'last_at';
					break;
			}

			$data_sql          = $select_sql . ' ORDER BY ' . $order_column . ' ' . $order . ' LIMIT %d OFFSET %d';
			$data_prepare_args = array_merge( $base_prepare_args, array( $per_page, $offset ) );
			// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
			$data_query        = $wpdb->prepare( $data_sql, ...$data_prepare_args );
			$this->items       = $wpdb->get_results( $data_query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		} else {
			$count_sql   = "SELECT COUNT(*) FROM %i" . $where_sql;
			// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
			$count_query = $wpdb->prepare( $count_sql, ...$base_prepare_args );
			$total       = (int) $wpdb->get_var( $count_query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

			$select_sql = "SELECT * FROM %i" . $where_sql;

			$order_column = 'created_at';
			if ( 'session_id' === $requested_orderby ) {
				$order_column = 'session_id';
			}

			$data_sql          = $select_sql . ' ORDER BY ' . $order_column . ' ' . $order . ' LIMIT %d OFFSET %d';
			$data_prepare_args = array_merge( $base_prepare_args, array( $per_page, $offset ) );
			// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
			$data_query        = $wpdb->prepare( $data_sql, ...$data_prepare_args );
			$this->items       = $wpdb->get_results( $data_query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}

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
	 * Validate the logs filter nonce.
	 *
	 * @return bool
	 */
	private function has_valid_filter_nonce(): bool {
		if ( ! isset( $_GET['_kivor_logs_nonce'] ) ) {
			return false;
		}

		$nonce = sanitize_text_field( wp_unslash( $_GET['_kivor_logs_nonce'] ) );
		return (bool) wp_verify_nonce( $nonce, 'kivor_chat_agent_logs_filter' );
	}
	/**
	 * Normalize date filter value to datetime string.
	 *
	 * @param string $raw_date Raw date input.
	 * @param bool   $end_of_day Whether to set end-of-day time.
	 * @return string
	 */
	private function normalize_date_filter( string $raw_date, bool $end_of_day ): string {
		$date = sanitize_text_field( $raw_date );

		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return '';
		}

		$parsed_date = \DateTime::createFromFormat( 'Y-m-d', $date, new \DateTimeZone( 'UTC' ) );
		if ( false === $parsed_date || $parsed_date->format( 'Y-m-d' ) !== $date ) {
			return '';
		}

		return $date . ( $end_of_day ? ' 23:59:59' : ' 00:00:00' );
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
	 * Messages count column in grouped view.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_messages( array $item ): string {
		return isset( $item['message_count'] ) ? esc_html( (string) intval( $item['message_count'] ) ) : '&mdash;';
	}

	/**
	 * Negative messages count column in grouped view.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_negative_messages( array $item ): string {
		$count = isset( $item['negative_count'] ) ? intval( $item['negative_count'] ) : 0;
		if ( $count <= 0 ) {
			return '&mdash;';
		}

		return '<span class="kivor-chat-agent-sentiment-badge kivor-chat-agent-sentiment-badge--negative">' . esc_html( (string) $count ) . '</span>';
	}

	/**
	 * Last speaker column in grouped view.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_last_role( array $item ): string {
		$role = (string) ( $item['last_role'] ?? '' );
		if ( '' === $role ) {
			return '&mdash;';
		}
		$class = 'kivor-chat-agent-role-badge kivor-chat-agent-role-badge--' . esc_attr( $role );
		return '<span class="' . $class . '">' . esc_html( $role ) . '</span>';
	}

	/**
	 * Last message column in grouped view.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_last_message( array $item ): string {
		$message = (string) ( $item['last_message'] ?? '' );
		if ( '' === $message ) {
			return '&mdash;';
		}
		$max = 120;
		if ( mb_strlen( $message, 'UTF-8' ) > $max ) {
			$truncated = mb_substr( $message, 0, $max, 'UTF-8' ) . '&hellip;';
			return '<span title="' . esc_attr( $message ) . '">' . esc_html( $truncated ) . '</span>';
		}
		return esc_html( $message );
	}

	/**
	 * Last activity column in grouped view.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_last_at( array $item ): string {
		$timestamp = strtotime( $item['last_at'] ?? '' );
		if ( ! $timestamp ) {
			return '&mdash;';
		}
		$date_format = get_option( 'date_format' );
		$time_format = get_option( 'time_format' );
		return esc_html( date_i18n( $date_format . ' ' . $time_format, $timestamp ) );
	}

	/**
	 * Session ID column — link to filter by session.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_session_id( array $item ): string {
		$session = $item['session_id'];
		$short   = substr( $session, 0, 12 ) . '&hellip;';

		$url = add_query_arg( array(
			'page'             => 'kivor-chat-agent-insights',
			'insights_tab'     => 'logs',
			'session_id'       => rawurlencode( $session ),
			'_kivor_logs_nonce' => wp_create_nonce( 'kivor_chat_agent_logs_filter' ),
		), admin_url( 'admin.php' ) );

		if ( $this->grouped_view ) {
			$view_link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'View conversation', 'admin' ) . '</a>';
			return '<code title="' . esc_attr( $session ) . '">' . esc_html( $short ) . '</code><br><span class="description">' . $view_link . '</span>';
		}

		return '<a href="' . esc_url( $url ) . '" title="' . esc_attr( $session ) . '"><code>' . esc_html( $short ) . '</code></a>';
	}

	/**
	 * Role column — color-coded badge.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_role( array $item ): string {
		$role = $item['role'];
		$class = 'kivor-chat-agent-role-badge kivor-chat-agent-role-badge--' . esc_attr( $role );
		return '<span class="' . $class . '">' . esc_html( $role ) . '</span>';
	}

	/**
	 * Sentiment column — color-coded badge.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_sentiment( array $item ): string {
		$sentiment = strtolower( (string) ( $item['sentiment'] ?? '' ) );
		if ( '' === $sentiment ) {
			return '&mdash;';
		}

		if ( ! in_array( $sentiment, array( 'positive', 'neutral', 'negative' ), true ) ) {
			return esc_html( $sentiment );
		}

		$class = 'kivor-chat-agent-sentiment-badge kivor-chat-agent-sentiment-badge--' . esc_attr( $sentiment );
		return '<span class="' . $class . '">' . esc_html( ucfirst( $sentiment ) ) . '</span>';
	}

	/**
	 * Message column — truncated with full text in title attribute.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_message( array $item ): string {
		$message = $item['message'];
		$max     = 120;

		if ( mb_strlen( $message, 'UTF-8' ) > $max ) {
			$truncated = mb_substr( $message, 0, $max, 'UTF-8' ) . '&hellip;';
			return '<span title="' . esc_attr( $message ) . '">' . esc_html( $truncated ) . '</span>';
		}

		return esc_html( $message );
	}

	/**
	 * Date column — formatted datetime.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_created_at( array $item ): string {
		$timestamp = strtotime( $item['created_at'] );
		if ( ! $timestamp ) {
			return '&mdash;';
		}

		$date_format = get_option( 'date_format' );
		$time_format = get_option( 'time_format' );

		return esc_html( date_i18n( $date_format . ' ' . $time_format, $timestamp ) );
	}

	/**
	 * Extra controls above/below the table — filter bar.
	 *
	 * @param string $which 'top' or 'bottom'.
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		$session_id = '';
		$date_from  = '';
		$date_to    = '';
		if ( $this->has_valid_filter_nonce() ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$session_id = isset( $_GET['session_id'] ) ? sanitize_text_field( wp_unslash( $_GET['session_id'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$date_from  = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$date_to    = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '';
		}

		echo '<div class="kivor-chat-agent-filter-bar">';

		echo '<div>';
		echo '<label for="kivor-chat-agent-filter-session">' . esc_html__( 'Session ID', 'admin' ) . '</label>';
		echo '<input type="text" id="kivor-chat-agent-filter-session" name="session_id" value="' . esc_attr( $session_id ) . '" placeholder="' . esc_attr__( 'Filter by session', 'admin' ) . '">';
		echo '</div>';

		echo '<div>';
		echo '<label for="kivor-chat-agent-filter-from">' . esc_html__( 'From', 'admin' ) . '</label>';
		echo '<input type="date" id="kivor-chat-agent-filter-from" name="date_from" value="' . esc_attr( $date_from ) . '">';
		echo '</div>';

		echo '<div>';
		echo '<label for="kivor-chat-agent-filter-to">' . esc_html__( 'To', 'admin' ) . '</label>';
		echo '<input type="date" id="kivor-chat-agent-filter-to" name="date_to" value="' . esc_attr( $date_to ) . '">';
		echo '</div>';

		// Preserve page in filter form.
		echo '<input type="hidden" name="page" value="kivor-chat-agent-insights">';
		echo '<input type="hidden" name="insights_tab" value="logs">';
		wp_nonce_field( 'kivor_chat_agent_logs_filter', '_kivor_logs_nonce', false );

		submit_button( __( 'Filter', 'admin' ), 'secondary', 'kivor_chat_agent_filter', false );

		// Clear filter link (only show if filters are active).
		if ( $session_id || $date_from || $date_to ) {
			$clear_url = add_query_arg( array(
				'page'         => 'kivor-chat-agent-insights',
				'insights_tab' => 'logs',
			), admin_url( 'admin.php' ) );
			echo ' <a href="' . esc_url( $clear_url ) . '" class="button">' . esc_html__( 'Clear Filters', 'admin' ) . '</a>';
		}

		echo '</div>';
	}

	/**
	 * Message when no items are found.
	 */
	public function no_items(): void {
		esc_html_e( 'No chat logs found.', 'admin' );
	}
}
