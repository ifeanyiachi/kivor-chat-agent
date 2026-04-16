<?php
/**
 * Native WordPress admin panel for Kivor Chat Agent.
 *
 * Tab-based settings page using WordPress Settings API patterns,
 * WP_List_Table for KB and Logs, and vanilla JS for AJAX actions.
 *
 * @package KivorAgent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class Kivor_Admin {

	/**
	 * Settings instance.
	 *
	 * @var Kivor_Settings
	 */
	private Kivor_Settings $settings;

	/**
	 * The page hook suffixes returned by add_menu_page / add_submenu_page.
	 *
	 * @var array<int, string>
	 */
	private array $page_hooks = array();

	/**
	 * Available tabs.
	 *
	 * @var array
	 */
	private array $tabs = array();

	/**
	 * Constructor.
	 *
	 * @param Kivor_Settings $settings Plugin settings.
	 */
	public function __construct( Kivor_Settings $settings ) {
		$this->settings = $settings;
		$pro_badge      = Kivor_Feature_Gates::get_menu_badge_html();

		$this->tabs = array(
			'general'        => __( 'General', 'kivor-chat-agent' ),
			'ai-providers'   => __( 'AI Providers', 'kivor-chat-agent' ),
			'embeddings'     => __( 'Embeddings', 'kivor-chat-agent' ),
			'phone-call'     => __( 'Phone Call', 'kivor-chat-agent' ) . $pro_badge,
			'voice'          => __( 'Voice', 'kivor-chat-agent' ) . $pro_badge,
			'appearance'     => __( 'Appearance', 'kivor-chat-agent' ),
			'styling'        => __( 'Chatbot Styling', 'kivor-chat-agent' ) . $pro_badge,
			'whatsapp'       => __( 'WhatsApp', 'kivor-chat-agent' ),
			'gdpr'           => __( 'GDPR', 'kivor-chat-agent' ),
		);
	}

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_init', array( $this, 'handle_form_submission' ) );
		add_action( 'admin_notices', array( $this, 'render_negative_sentiment_notice' ) );
	}

	/**
	 * Register the admin menu page.
	 */
	public function register_menu(): void {
		$pro_badge = Kivor_Feature_Gates::get_menu_badge_html();
		$icon_svg = 'data:image/svg+xml;base64,' . base64_encode(
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><circle cx="12" cy="10" r="1"/><circle cx="8" cy="10" r="1"/><circle cx="16" cy="10" r="1"/></svg>'
		);

		$settings_hook = add_menu_page(
			__( 'Kivor Chat Agent', 'kivor-chat-agent' ),
			__( 'Kivor Chat Agent', 'kivor-chat-agent' ),
			'manage_options',
			'kivor-chat-agent',
			array( $this, 'render_page' ),
			$icon_svg,
			58
		);

		$this->page_hooks[] = (string) $settings_hook;

		$settings_submenu_hook = add_submenu_page(
			'kivor-chat-agent',
			__( 'Settings', 'kivor-chat-agent' ),
			__( 'Settings', 'kivor-chat-agent' ),
			'manage_options',
			'kivor-chat-agent',
			array( $this, 'render_page' )
		);

		if ( false !== $settings_submenu_hook ) {
			$this->page_hooks[] = (string) $settings_submenu_hook;
		}

		$forms_hook = add_submenu_page(
			'kivor-chat-agent',
			__( 'Form', 'kivor-chat-agent' ),
			__( 'Form', 'kivor-chat-agent' ) . $pro_badge,
			'manage_options',
			'kivor-chat-agent-forms',
			array( $this, 'render_forms_page' )
		);

		if ( false !== $forms_hook ) {
			$this->page_hooks[] = (string) $forms_hook;
		}

		$knowledge_hook = add_submenu_page(
			'kivor-chat-agent',
			__( 'Knowledge Base', 'kivor-chat-agent' ),
			__( 'Knowledge Base', 'kivor-chat-agent' ),
			'manage_options',
			'kivor-chat-agent-knowledge-base',
			array( $this, 'render_knowledge_base_page' )
		);

		if ( false !== $knowledge_hook ) {
			$this->page_hooks[] = (string) $knowledge_hook;
		}

		$insights_hook = add_submenu_page(
			'kivor-chat-agent',
			__( 'Insights', 'kivor-chat-agent' ),
			__( 'Insights', 'kivor-chat-agent' ) . $pro_badge,
			'manage_options',
			'kivor-chat-agent-insights',
			array( $this, 'render_insights_page' )
		);

		if ( false !== $insights_hook ) {
			$this->page_hooks[] = (string) $insights_hook;
		}

		$embeddings_hook = add_submenu_page(
			'kivor-chat-agent',
			__( 'Embeddings', 'kivor-chat-agent' ),
			__( 'Embeddings', 'kivor-chat-agent' ) . $pro_badge,
			'manage_options',
			'kivor-chat-agent-embeddings',
			array( $this, 'render_embeddings_page' )
		);

		if ( false !== $embeddings_hook ) {
			$this->page_hooks[] = (string) $embeddings_hook;
		}
	}

	/**
	 * Get the current active tab slug.
	 *
	 * @return string
	 */
	private function get_current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only tab selection.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return array_key_exists( $tab, $this->tabs ) ? $tab : 'general';
	}

	/**
	 * Render the admin page.
	 */
	public function render_page(): void {
		$current_tab = $this->get_current_tab();
		$all         = $this->settings->get_all();

		echo '<div class="wrap kivor-chat-agent-admin-wrap">';
		echo '<h1>' . esc_html__( 'Kivor Chat Agent', 'kivor-chat-agent' ) . ' <span class="kivor-chat-agent-version">v' . esc_html( KIVOR_AGENT_VERSION ) . '</span></h1>';

		// Show save result notice.
		if ( isset( $_GET['kivor-chat-agent-saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$saved = sanitize_key( wp_unslash( $_GET['kivor-chat-agent-saved'] ) );
			if ( 'success' === $saved ) {
				$class = 'notice-success';
				$msg   = __( 'Settings saved.', 'kivor-chat-agent' );
			} else {
				$class = 'notice-error';
				$msg   = __( 'Failed to save settings.', 'kivor-chat-agent' );
			}
			echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}

		// Tab navigation.
		echo '<nav class="nav-tab-wrapper kivor-chat-agent-nav-tabs">';
		foreach ( $this->tabs as $slug => $label ) {
			$url   = add_query_arg( array( 'page' => 'kivor-chat-agent', 'tab' => $slug ), admin_url( 'admin.php' ) );
			$class = ( $slug === $current_tab ) ? 'nav-tab nav-tab-active' : 'nav-tab';
			echo '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $class ) . '">' . wp_kses( $label, array( 'span' => array( 'class' => array() ) ) ) . '</a>';
		}
		echo '</nav>';

		// Tab content.
		echo '<div class="kivor-chat-agent-tab-content">';

		$view_file = KIVOR_AGENT_PATH . 'admin/views/tab-' . $current_tab . '.php';
		if ( file_exists( $view_file ) ) {
			// Make settings available to view partials.
			$settings = $all;
			$admin    = $this;
			include $view_file;
		} else {
			echo '<p>' . esc_html__( 'Tab content not found.', 'kivor-chat-agent' ) . '</p>';
		}

		echo '</div>'; // .kivor-chat-agent-tab-content
		echo '</div>'; // .wrap
	}

	/**
	 * Render Form submenu page.
	 */
	public function render_forms_page(): void {
		$all       = $this->settings->get_all();
		$forms_tab = isset( $_GET['forms_tab'] ) ? sanitize_key( $_GET['forms_tab'] ) : 'manage'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $forms_tab, array( 'manage', 'submissions' ), true ) ) {
			$forms_tab = 'manage';
		}

		echo '<div class="wrap kivor-chat-agent-admin-wrap">';
		echo '<h1>' . esc_html__( 'Kivor Chat Agent', 'kivor-chat-agent' ) . ' <span class="kivor-chat-agent-version">v' . esc_html( KIVOR_AGENT_VERSION ) . '</span></h1>';
		echo '<h2>' . esc_html__( 'Form', 'kivor-chat-agent' ) . wp_kses( Kivor_Feature_Gates::get_menu_badge_html(), array( 'span' => array( 'class' => array() ) ) ) . '</h2>';

		if ( isset( $_GET['kivor-chat-agent-saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$class = 'success' === $_GET['kivor-chat-agent-saved'] ? 'notice-success' : 'notice-error';
			$msg   = 'success' === $_GET['kivor-chat-agent-saved']
				? __( 'Settings saved.', 'kivor-chat-agent' )
				: __( 'Failed to save settings.', 'kivor-chat-agent' );
			echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}

		echo '<nav class="nav-tab-wrapper kivor-chat-agent-subtabs">';
		$manage_url = add_query_arg(
			array(
				'page'      => 'kivor-chat-agent-forms',
				'forms_tab' => 'manage',
			),
			admin_url( 'admin.php' )
		);
		$submissions_url = add_query_arg(
			array(
				'page'      => 'kivor-chat-agent-forms',
				'forms_tab' => 'submissions',
			),
			admin_url( 'admin.php' )
		);

		echo '<a href="' . esc_url( $manage_url ) . '" class="nav-tab ' . esc_attr( 'manage' === $forms_tab ? 'nav-tab-active' : '' ) . '">' . esc_html__( 'Manage Forms', 'kivor-chat-agent' ) . '</a>';
		echo '<a href="' . esc_url( $submissions_url ) . '" class="nav-tab ' . esc_attr( 'submissions' === $forms_tab ? 'nav-tab-active' : '' ) . '">' . esc_html__( 'Submissions', 'kivor-chat-agent' ) . '</a>';
		echo '</nav>';

		echo '<div class="kivor-chat-agent-tab-content">';
		$settings = $all;
		$admin    = $this;
		include KIVOR_AGENT_PATH . 'admin/views/tab-forms.php';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Render Knowledge Base submenu page.
	 */
	public function render_knowledge_base_page(): void {
		$all    = $this->settings->get_all();
		$kb_tab = isset( $_GET['kb_tab'] ) ? sanitize_key( $_GET['kb_tab'] ) : 'knowledge'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $kb_tab, array( 'knowledge', 'integrations' ), true ) ) {
			$kb_tab = 'knowledge';
		}

		echo '<div class="wrap kivor-chat-agent-admin-wrap">';
		echo '<h1>' . esc_html__( 'Kivor Chat Agent', 'kivor-chat-agent' ) . ' <span class="kivor-chat-agent-version">v' . esc_html( KIVOR_AGENT_VERSION ) . '</span></h1>';
		echo '<h2>' . esc_html__( 'Knowledge Base', 'kivor-chat-agent' ) . '</h2>';

		if ( isset( $_GET['kivor-chat-agent-saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$class = 'success' === $_GET['kivor-chat-agent-saved'] ? 'notice-success' : 'notice-error';
			$msg   = 'success' === $_GET['kivor-chat-agent-saved']
				? __( 'Settings saved.', 'kivor-chat-agent' )
				: __( 'Failed to save settings.', 'kivor-chat-agent' );
			echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}

		echo '<nav class="nav-tab-wrapper kivor-chat-agent-subtabs">';
		$knowledge_url = add_query_arg(
			array(
				'page'   => 'kivor-chat-agent-knowledge-base',
				'kb_tab' => 'knowledge',
			),
			admin_url( 'admin.php' )
		);
		$integrations_url = add_query_arg(
			array(
				'page'   => 'kivor-chat-agent-knowledge-base',
				'kb_tab' => 'integrations',
			),
			admin_url( 'admin.php' )
		);

		echo '<a href="' . esc_url( $knowledge_url ) . '" class="nav-tab ' . esc_attr( 'knowledge' === $kb_tab ? 'nav-tab-active' : '' ) . '">' . esc_html__( 'Knowledge', 'kivor-chat-agent' ) . '</a>';
		echo '<a href="' . esc_url( $integrations_url ) . '" class="nav-tab ' . esc_attr( 'integrations' === $kb_tab ? 'nav-tab-active' : '' ) . '">' . esc_html__( 'Integrations', 'kivor-chat-agent' ) . '</a>';
		echo '</nav>';

		echo '<div class="kivor-chat-agent-tab-content">';
		$settings = $all;
		$admin    = $this;
		if ( 'integrations' === $kb_tab ) {
			include KIVOR_AGENT_PATH . 'admin/views/tab-external-platforms.php';
		} else {
			include KIVOR_AGENT_PATH . 'admin/views/tab-knowledge-base.php';
		}
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Render Insights submenu page.
	 */
	public function render_insights_page(): void {
		$all          = $this->settings->get_all();
		$insights_tab = isset( $_GET['insights_tab'] ) ? sanitize_key( $_GET['insights_tab'] ) : 'logs'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $insights_tab, array( 'logs', 'statistics', 'settings' ), true ) ) {
			$insights_tab = 'logs';
		}

		echo '<div class="wrap kivor-chat-agent-admin-wrap">';
		echo '<h1>' . esc_html__( 'Kivor Chat Agent', 'kivor-chat-agent' ) . ' <span class="kivor-chat-agent-version">v' . esc_html( KIVOR_AGENT_VERSION ) . '</span></h1>';
		echo '<h2>' . esc_html__( 'Insights', 'kivor-chat-agent' ) . wp_kses( Kivor_Feature_Gates::get_menu_badge_html(), array( 'span' => array( 'class' => array() ) ) ) . '</h2>';

		if ( isset( $_GET['kivor-chat-agent-saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$class = 'success' === $_GET['kivor-chat-agent-saved'] ? 'notice-success' : 'notice-error';
			$msg   = 'success' === $_GET['kivor-chat-agent-saved']
				? __( 'Settings saved.', 'kivor-chat-agent' )
				: __( 'Failed to save settings.', 'kivor-chat-agent' );
			echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}

		echo '<nav class="nav-tab-wrapper kivor-chat-agent-subtabs">';
		$logs_url = add_query_arg(
			array(
				'page'         => 'kivor-chat-agent-insights',
				'insights_tab' => 'logs',
			),
			admin_url( 'admin.php' )
		);
		$statistics_url = add_query_arg(
			array(
				'page'         => 'kivor-chat-agent-insights',
				'insights_tab' => 'statistics',
			),
			admin_url( 'admin.php' )
		);
		$settings_url = add_query_arg(
			array(
				'page'         => 'kivor-chat-agent-insights',
				'insights_tab' => 'settings',
			),
			admin_url( 'admin.php' )
		);

		echo '<a href="' . esc_url( $logs_url ) . '" class="nav-tab ' . esc_attr( 'logs' === $insights_tab ? 'nav-tab-active' : '' ) . '">' . esc_html__( 'Chat Logs', 'kivor-chat-agent' ) . '</a>';
		echo '<a href="' . esc_url( $statistics_url ) . '" class="nav-tab ' . esc_attr( 'statistics' === $insights_tab ? 'nav-tab-active' : '' ) . '">' . esc_html__( 'Statistics', 'kivor-chat-agent' ) . '</a>';
		echo '<a href="' . esc_url( $settings_url ) . '" class="nav-tab ' . esc_attr( 'settings' === $insights_tab ? 'nav-tab-active' : '' ) . '">' . esc_html__( 'Analytics Settings', 'kivor-chat-agent' ) . '</a>';
		echo '</nav>';

		echo '<div class="kivor-chat-agent-tab-content">';
		$settings = $all;
		$admin    = $this;
		if ( 'statistics' === $insights_tab ) {
			include KIVOR_AGENT_PATH . 'admin/views/tab-insights-statistics.php';
		} elseif ( 'settings' === $insights_tab ) {
			include KIVOR_AGENT_PATH . 'admin/views/tab-insights-settings.php';
		} else {
			include KIVOR_AGENT_PATH . 'admin/views/tab-chat-logs.php';
		}
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Render Embeddings submenu page.
	 *
	 * @return void
	 */
	public function render_embeddings_page(): void {
		$this->render_single_view_page( 'page-embeddings.php', __( 'Embeddings', 'kivor-chat-agent' ) . Kivor_Feature_Gates::get_menu_badge_html() );
	}

	/**
	 * Render a single view page wrapper.
	 *
	 * @param string $view_file_name View filename under admin/views.
	 * @param string $title          Section title.
	 */
	private function render_single_view_page( string $view_file_name, string $title ): void {
		$all = $this->settings->get_all();

		echo '<div class="wrap kivor-chat-agent-admin-wrap">';
		echo '<h1>' . esc_html__( 'Kivor Chat Agent', 'kivor-chat-agent' ) . ' <span class="kivor-chat-agent-version">v' . esc_html( KIVOR_AGENT_VERSION ) . '</span></h1>';
		echo '<h2>' . wp_kses( $title, array( 'span' => array( 'class' => array() ) ) ) . '</h2>';

		if ( isset( $_GET['kivor-chat-agent-saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$class = 'success' === $_GET['kivor-chat-agent-saved'] ? 'notice-success' : 'notice-error';
			$msg   = 'success' === $_GET['kivor-chat-agent-saved']
				? __( 'Settings saved.', 'kivor-chat-agent' )
				: __( 'Failed to save settings.', 'kivor-chat-agent' );
			echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}

		echo '<div class="kivor-chat-agent-tab-content">';
		$view_file = KIVOR_AGENT_PATH . 'admin/views/' . $view_file_name;
		if ( file_exists( $view_file ) ) {
			$settings = $all;
			$admin    = $this;
			include $view_file;
		} else {
			echo '<p>' . esc_html__( 'Tab content not found.', 'kivor-chat-agent' ) . '</p>';
		}
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Handle form submissions on admin_init.
	 */
	public function handle_form_submission(): void {
		if ( ! isset( $_POST['kivor_chat_agent_save_settings'] ) ) {
			return;
		}

		$requested_group = sanitize_key( $_POST['kivor_chat_agent_save_settings'] );
		$group           = ( 'integrations' === $requested_group ) ? 'external_platforms' : $requested_group;

		// Verify nonce.
		$nonce_valid = false;
		if ( isset( $_POST['_kivor_chat_agent_nonce'] ) ) {
			$nonce       = sanitize_text_field( wp_unslash( $_POST['_kivor_chat_agent_nonce'] ) );
			$nonce_valid = (bool) wp_verify_nonce( $nonce, 'kivor_chat_agent_save_' . $requested_group );

			// Backward compatibility for older integrations tab form group naming.
			if ( ! $nonce_valid && $requested_group !== $group ) {
				$nonce_valid = (bool) wp_verify_nonce( $nonce, 'kivor_chat_agent_save_' . $group );
			}
		}

		if ( ! $nonce_valid ) {
			wp_die( esc_html__( 'Security check failed.', 'kivor-chat-agent' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'kivor-chat-agent' ) );
		}

		$values = array();
		$save_group = $group;

			switch ( $group ) {
				case 'general':
					$values = $this->extract_general();
					break;
			case 'ai_provider':
				$values = $this->extract_ai_provider();
				break;
				case 'embedding':
					$values = $this->extract_embedding();
					break;
			case 'forms':
				$values = $this->extract_forms();
				break;
			case 'external_platforms':
				$values = $this->extract_external_platforms();
				break;
			case 'voice':
				$values = $this->extract_voice();
				break;
			case 'phone_call':
				$values = $this->extract_phone_call();
				break;
			case 'appearance':
				$values = $this->extract_appearance();
				break;
			case 'styling':
				$values = $this->extract_widget_styling();
				$save_group = 'appearance';
				break;
		case 'whatsapp':
			$values = $this->extract_whatsapp();
			break;
			case 'gdpr':
				$values = $this->extract_gdpr();
				break;
			case 'chat_logs':
				$values = $this->extract_chat_logs();
				break;
			case 'analytics':
				$values = $this->extract_analytics();
				break;
			default:
				$this->redirect_back( $group, 'error' );
				return;
		}

		if ( Kivor_Feature_Gates::is_group_locked( $group ) ) {
			if ( 'forms' === $group ) {
				$this->redirect_page( 'kivor-chat-agent-forms', array(), 'error' );
			}

			if ( 'analytics' === $group ) {
				$this->redirect_page( 'kivor-chat-agent-insights', array( 'insights_tab' => 'settings' ), 'error' );
			}

			if ( 'chat_logs' === $group ) {
				$this->redirect_page( 'kivor-chat-agent-insights', array( 'insights_tab' => 'logs' ), 'error' );
			}

			if ( 'phone_call' === $group ) {
				$this->redirect_back( 'phone-call', 'error' );
			}

			if ( 'voice' === $group ) {
				$this->redirect_back( 'voice', 'error' );
			}

			$this->redirect_back( $group, 'error' );
		}

		$values  = Kivor_Feature_Gates::enforce_group_restrictions( $save_group, $values, $this->settings );
		$updated = $this->settings->update_group( $save_group, $values );
		if ( $updated ) {
			$this->purge_frontend_caches();
		}

		// Handle custom_css which lives in the 'general' group but is
		// submitted from the appearance tab for UX convenience.
		if ( 'appearance' === $group && isset( $_POST['custom_css'] ) ) {
			$css = wp_strip_all_tags( wp_unslash( $_POST['custom_css'] ) );
			$current_general = $this->settings->get_all()['general'];
			$current_general['custom_css'] = $css;
			$this->settings->update_group( 'general', $current_general );
		}

		if ( 'forms' === $group ) {
			$this->redirect_page( 'kivor-chat-agent-forms', array(), $updated ? 'success' : 'error' );
		}

		if ( 'external_platforms' === $group ) {
			$this->redirect_page( 'kivor-chat-agent-knowledge-base', array( 'kb_tab' => 'integrations' ), $updated ? 'success' : 'error' );
		}

		if ( 'chat_logs' === $group ) {
			$this->redirect_page( 'kivor-chat-agent-insights', array(), $updated ? 'success' : 'error' );
		}

		if ( 'analytics' === $group ) {
			$this->redirect_page( 'kivor-chat-agent-insights', array( 'insights_tab' => 'settings' ), $updated ? 'success' : 'error' );
		}

		// Map group key to tab slug for redirect.
		$tab_map = array(
			'general'      => 'general',
			'ai_provider'  => 'ai-providers',
			'embedding'    => 'embeddings',
			'voice'        => 'voice',
			'phone_call'   => 'phone-call',
			'appearance'   => 'appearance',
			'styling'      => 'styling',
			'whatsapp'     => 'whatsapp',
			'gdpr'         => 'gdpr',
		);

		$tab = $tab_map[ $group ] ?? 'general';
		$this->redirect_back( $tab, $updated ? 'success' : 'error' );
	}

	/**
	 * Redirect to a specific submenu page.
	 *
	 * @param string $page_slug Page slug.
	 * @param array  $args      Additional query args.
	 * @param string $result    Save result.
	 */
	private function redirect_page( string $page_slug, array $args, string $result ): void {
		$args['page']                   = $page_slug;
		$args['kivor-chat-agent-saved'] = $result;

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Redirect back to the settings page after form submission.
	 *
	 * @param string $tab    Tab slug.
	 * @param string $result 'success' or 'error'.
	 */
	private function redirect_back( string $tab, string $result ): void {
		wp_safe_redirect( add_query_arg( array(
			'page'        => 'kivor-chat-agent',
			'tab'         => $tab,
			'kivor-chat-agent-saved' => $result,
		), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Purge common frontend caches after settings updates.
	 *
	 * @return void
	 */
	private function purge_frontend_caches(): void {
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}

		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}

		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}

		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
		}

		do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party cache hook
		do_action( 'endurance_page_cache_clear' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party cache hook
	}

	// =========================================================================
	// Extract methods — pull form data from $_POST
	// =========================================================================

	/**
	 * Extract general settings from POST.
	 *
	 * @return array
	 */
	// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in save_settings().
	private function extract_general(): array {
		$values = array();

		$values['bot_name']    = isset( $_POST['bot_name'] ) ? sanitize_text_field( wp_unslash( $_POST['bot_name'] ) ) : '';
		$values['use_in_app_intro'] = ! empty( $_POST['use_in_app_intro'] );
		$values['chatbot_title'] = isset( $_POST['chatbot_title'] ) ? sanitize_text_field( wp_unslash( $_POST['chatbot_title'] ) ) : '';
		$values['chatbot_description'] = isset( $_POST['chatbot_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['chatbot_description'] ) ) : '';
		$values['first_greeting_message'] = isset( $_POST['first_greeting_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['first_greeting_message'] ) ) : '';
		$values['bot_avatar']  = isset( $_POST['bot_avatar'] ) ? esc_url_raw( $_POST['bot_avatar'] ) : '';
		$values['widget_logo_id'] = isset( $_POST['widget_logo_id'] ) ? absint( $_POST['widget_logo_id'] ) : 0;
		$values['widget_logo'] = '';
		if ( $values['widget_logo_id'] > 0 ) {
			$logo_url = wp_get_attachment_url( $values['widget_logo_id'] );
			if ( is_string( $logo_url ) ) {
				$filetype = wp_check_filetype( $logo_url );
				$ext      = strtolower( (string) ( $filetype['ext'] ?? '' ) );
				if ( in_array( $ext, array( 'png', 'svg' ), true ) ) {
					$values['widget_logo'] = esc_url_raw( $logo_url );
				} else {
					$values['widget_logo_id'] = 0;
				}
			}
		}
		$values['chat_tab_label'] = isset( $_POST['chat_tab_label'] ) ? sanitize_text_field( wp_unslash( $_POST['chat_tab_label'] ) ) : '';
		$values['chat_position'] = isset( $_POST['chat_position'] ) ? sanitize_key( $_POST['chat_position'] ) : 'bottom-right';
		$values['show_end_session_button'] = ! empty( $_POST['show_end_session_button'] );


		$values['custom_instructions']          = isset( $_POST['custom_instructions'] ) ? sanitize_textarea_field( wp_unslash( $_POST['custom_instructions'] ) ) : '';
		$values['override_system_instructions'] = ! empty( $_POST['override_system_instructions'] );
		$values['total_uninstall']              = ! empty( $_POST['total_uninstall'] );

		// Preserve custom_css — it's managed on the appearance tab, not here.
		$values['custom_css'] = $this->settings->get( 'general.custom_css' ) ?? '';

		return $values;
	}

	/**
	 * Extract AI provider settings from POST.
	 *
	 * @return array
	 */
	// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in save_settings().
	private function extract_ai_provider(): array {
		$values = array();

		$values['active_provider']          = isset( $_POST['active_provider'] ) ? sanitize_key( $_POST['active_provider'] ) : 'openai';
		$values['conversation_memory_size'] = isset( $_POST['conversation_memory_size'] ) ? absint( $_POST['conversation_memory_size'] ) : 10;

		$providers = array();
		foreach ( array( 'openai', 'gemini', 'openrouter' ) as $p ) {
			$providers[ $p ] = array(
				'enabled' => ! empty( $_POST[ "provider_{$p}_enabled" ] ),
				'api_key' => $this->get_key_from_post( "provider_{$p}_api_key", "ai_provider.providers.{$p}.api_key" ),
				'model'   => isset( $_POST[ "provider_{$p}_model" ] ) ? sanitize_text_field( $_POST[ "provider_{$p}_model" ] ) : '',
			);
		}
		$values['providers'] = $providers;

		return $values;
	}

	/**
	 * Extract embedding settings from POST.
	 *
	 * @return array
	 */
	// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in save_settings().
	private function extract_embedding(): array {
		$values = array();

		$values['active_provider']   = isset( $_POST['embedding_active_provider'] ) ? sanitize_key( $_POST['embedding_active_provider'] ) : 'openai';
		$values['fallback_provider'] = isset( $_POST['embedding_fallback_provider'] ) ? sanitize_key( $_POST['embedding_fallback_provider'] ) : 'local';
		$values['vector_store']      = isset( $_POST['vector_store'] ) ? sanitize_key( $_POST['vector_store'] ) : 'local';
		$values['sync_on_product_save'] = ! empty( $_POST['sync_on_product_save'] );

		$values['providers'] = array();
		foreach ( array( 'openai', 'gemini', 'openrouter', 'cohere' ) as $provider ) {
			$values['providers'][ $provider ] = array(
				'enabled' => ! empty( $_POST[ "embedding_provider_{$provider}_enabled" ] ),
				'api_key' => $this->get_key_from_post( "embedding_provider_{$provider}_api_key", "embedding.providers.{$provider}.api_key" ),
				'model'   => isset( $_POST[ "embedding_provider_{$provider}_model" ] ) ? sanitize_text_field( $_POST[ "embedding_provider_{$provider}_model" ] ) : '',
			);
		}


		$values['pinecone'] = array(
			'api_key'     => $this->get_key_from_post( 'pinecone_api_key', 'embedding.pinecone.api_key' ),
			'index_name'  => isset( $_POST['pinecone_index_name'] ) ? sanitize_text_field( $_POST['pinecone_index_name'] ) : '',
			'environment' => isset( $_POST['pinecone_environment'] ) ? sanitize_text_field( $_POST['pinecone_environment'] ) : '',
		);

		$values['qdrant'] = array(
			'endpoint_url'    => isset( $_POST['qdrant_endpoint_url'] ) ? esc_url_raw( $_POST['qdrant_endpoint_url'] ) : '',
			'api_key'         => $this->get_key_from_post( 'qdrant_api_key', 'embedding.qdrant.api_key' ),
			'collection_name' => isset( $_POST['qdrant_collection_name'] ) ? sanitize_text_field( $_POST['qdrant_collection_name'] ) : 'kivor_chat_agent_products',
		);

		return $values;
	}

	/**
	 * Extract voice settings from POST.
	 *
	 * @return array
	 */
	// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in save_settings().
	private function extract_voice(): array {
		return array(
			'input_enabled'        => ! empty( $_POST['voice_input_enabled'] ),
			'interaction_mode'     => isset( $_POST['voice_interaction_mode'] ) ? sanitize_key( $_POST['voice_interaction_mode'] ) : 'push_to_talk',
			'auto_send_mode'       => isset( $_POST['voice_auto_send_mode'] ) ? sanitize_key( $_POST['voice_auto_send_mode'] ) : 'silence',
			'auto_send_delay_ms'   => isset( $_POST['voice_auto_send_delay_ms'] ) ? absint( $_POST['voice_auto_send_delay_ms'] ) : 800,
			'confidence_threshold' => isset( $_POST['voice_confidence_threshold'] ) ? floatval( $_POST['voice_confidence_threshold'] ) : 0.65,
			'auto_detect_language' => ! empty( $_POST['voice_auto_detect_language'] ),
			'default_language'     => isset( $_POST['voice_default_language'] ) ? sanitize_text_field( $_POST['voice_default_language'] ) : 'en-US',
			'stt_provider'         => isset( $_POST['voice_stt_provider'] ) ? sanitize_key( $_POST['voice_stt_provider'] ) : 'webspeech',
			'stt_model'            => isset( $_POST['voice_stt_model'] ) ? sanitize_text_field( $_POST['voice_stt_model'] ) : '',
			'providers'            => array(
				'openai'   => array(
					'api_key'   => $this->get_key_from_post( 'voice_openai_api_key', 'voice.providers.openai.api_key' ),
					'stt_model' => isset( $_POST['voice_openai_stt_model'] ) ? sanitize_text_field( $_POST['voice_openai_stt_model'] ) : '',
				),
				'cartesia' => array(
					'api_key'   => $this->get_key_from_post( 'voice_cartesia_api_key', 'voice.providers.cartesia.api_key' ),
					'version'   => isset( $_POST['voice_cartesia_version'] ) ? sanitize_text_field( $_POST['voice_cartesia_version'] ) : '2025-04-16',
					'stt_model' => isset( $_POST['voice_cartesia_stt_model'] ) ? sanitize_text_field( $_POST['voice_cartesia_stt_model'] ) : '',
				),
				'deepgram' => array(
					'api_key'   => $this->get_key_from_post( 'voice_deepgram_api_key', 'voice.providers.deepgram.api_key' ),
					'stt_model' => isset( $_POST['voice_deepgram_stt_model'] ) ? sanitize_text_field( $_POST['voice_deepgram_stt_model'] ) : '',
				),
			),
			'limits'               => array(
				'max_stt_seconds' => isset( $_POST['voice_max_stt_seconds'] ) ? absint( $_POST['voice_max_stt_seconds'] ) : 20,
			),
		);
	}

	/**
	 * Extract appearance settings from POST.
	 *
	 * @return array
	 */
	// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in save_settings().
	private function extract_appearance(): array {
		return array(
			'product_card_layout'          => isset( $_POST['product_card_layout'] ) ? sanitize_key( $_POST['product_card_layout'] ) : 'carousel',
			'product_card_show_image'      => ! empty( $_POST['product_card_show_image'] ),
			'product_card_show_price'      => ! empty( $_POST['product_card_show_price'] ),
			'product_card_show_link'       => ! empty( $_POST['product_card_show_link'] ),
			'product_card_show_add_to_cart' => ! empty( $_POST['product_card_show_add_to_cart'] ),
			'widget_primary_color'         => isset( $_POST['widget_primary_color'] ) ? sanitize_hex_color( $_POST['widget_primary_color'] ) : '',
			'widget_primary_hover_color'   => isset( $_POST['widget_primary_hover_color'] ) ? sanitize_hex_color( $_POST['widget_primary_hover_color'] ) : '',
			'widget_primary_text_color'    => isset( $_POST['widget_primary_text_color'] ) ? sanitize_hex_color( $_POST['widget_primary_text_color'] ) : '',
			'widget_background_color'      => isset( $_POST['widget_background_color'] ) ? sanitize_hex_color( $_POST['widget_background_color'] ) : '',
			'widget_background_alt_color'  => isset( $_POST['widget_background_alt_color'] ) ? sanitize_hex_color( $_POST['widget_background_alt_color'] ) : '',
			'widget_text_color'            => isset( $_POST['widget_text_color'] ) ? sanitize_hex_color( $_POST['widget_text_color'] ) : '',
			'widget_text_muted_color'      => isset( $_POST['widget_text_muted_color'] ) ? sanitize_hex_color( $_POST['widget_text_muted_color'] ) : '',
			'widget_border_color'          => isset( $_POST['widget_border_color'] ) ? sanitize_hex_color( $_POST['widget_border_color'] ) : '',
			'widget_user_bubble_color'     => isset( $_POST['widget_user_bubble_color'] ) ? sanitize_hex_color( $_POST['widget_user_bubble_color'] ) : '',
			'widget_user_text_color'       => isset( $_POST['widget_user_text_color'] ) ? sanitize_hex_color( $_POST['widget_user_text_color'] ) : '',
			'widget_bot_bubble_color'      => isset( $_POST['widget_bot_bubble_color'] ) ? sanitize_hex_color( $_POST['widget_bot_bubble_color'] ) : '',
			'widget_bot_text_color'        => isset( $_POST['widget_bot_text_color'] ) ? sanitize_hex_color( $_POST['widget_bot_text_color'] ) : '',
			'widget_tab_background_color'  => isset( $_POST['widget_tab_background_color'] ) ? sanitize_hex_color( $_POST['widget_tab_background_color'] ) : '',
			'widget_tab_text_color'        => isset( $_POST['widget_tab_text_color'] ) ? sanitize_hex_color( $_POST['widget_tab_text_color'] ) : '',
			'widget_tab_active_color'      => isset( $_POST['widget_tab_active_color'] ) ? sanitize_hex_color( $_POST['widget_tab_active_color'] ) : '',
			'widget_tab_active_text_color' => isset( $_POST['widget_tab_active_text_color'] ) ? sanitize_hex_color( $_POST['widget_tab_active_text_color'] ) : '',
		);
	}

	/**
	 * Extract widget styling colors from POST.
	 *
	 * @return array
	 */
	// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in save_settings().
	private function extract_widget_styling(): array {
		return array(
			'widget_primary_color'         => isset( $_POST['widget_primary_color'] ) ? sanitize_hex_color( $_POST['widget_primary_color'] ) : '',
			'widget_primary_hover_color'   => isset( $_POST['widget_primary_hover_color'] ) ? sanitize_hex_color( $_POST['widget_primary_hover_color'] ) : '',
			'widget_primary_text_color'    => isset( $_POST['widget_primary_text_color'] ) ? sanitize_hex_color( $_POST['widget_primary_text_color'] ) : '',
			'widget_background_color'      => isset( $_POST['widget_background_color'] ) ? sanitize_hex_color( $_POST['widget_background_color'] ) : '',
			'widget_background_alt_color'  => isset( $_POST['widget_background_alt_color'] ) ? sanitize_hex_color( $_POST['widget_background_alt_color'] ) : '',
			'widget_text_color'            => isset( $_POST['widget_text_color'] ) ? sanitize_hex_color( $_POST['widget_text_color'] ) : '',
			'widget_text_muted_color'      => isset( $_POST['widget_text_muted_color'] ) ? sanitize_hex_color( $_POST['widget_text_muted_color'] ) : '',
			'widget_border_color'          => isset( $_POST['widget_border_color'] ) ? sanitize_hex_color( $_POST['widget_border_color'] ) : '',
			'widget_user_bubble_color'     => isset( $_POST['widget_user_bubble_color'] ) ? sanitize_hex_color( $_POST['widget_user_bubble_color'] ) : '',
			'widget_user_text_color'       => isset( $_POST['widget_user_text_color'] ) ? sanitize_hex_color( $_POST['widget_user_text_color'] ) : '',
			'widget_bot_bubble_color'      => isset( $_POST['widget_bot_bubble_color'] ) ? sanitize_hex_color( $_POST['widget_bot_bubble_color'] ) : '',
			'widget_bot_text_color'        => isset( $_POST['widget_bot_text_color'] ) ? sanitize_hex_color( $_POST['widget_bot_text_color'] ) : '',
			'widget_tab_background_color'  => isset( $_POST['widget_tab_background_color'] ) ? sanitize_hex_color( $_POST['widget_tab_background_color'] ) : '',
			'widget_tab_text_color'        => isset( $_POST['widget_tab_text_color'] ) ? sanitize_hex_color( $_POST['widget_tab_text_color'] ) : '',
			'widget_tab_active_color'      => isset( $_POST['widget_tab_active_color'] ) ? sanitize_hex_color( $_POST['widget_tab_active_color'] ) : '',
			'widget_tab_active_text_color' => isset( $_POST['widget_tab_active_text_color'] ) ? sanitize_hex_color( $_POST['widget_tab_active_text_color'] ) : '',
		);
	}

	/**
	 * Extract WhatsApp settings from POST.
	 *
	 * @return array
	 */
	// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in save_settings().
	private function extract_whatsapp(): array {
		return array(
			'enabled'           => ! empty( $_POST['whatsapp_enabled'] ),
			'name'              => isset( $_POST['whatsapp_name'] ) ? sanitize_text_field( $_POST['whatsapp_name'] ) : '',
			'number'            => isset( $_POST['whatsapp_number'] ) ? preg_replace( '/[^\d+]/', '', $_POST['whatsapp_number'] ) : '',
			'prefilled_message' => isset( $_POST['whatsapp_prefilled_message'] ) ? sanitize_textarea_field( $_POST['whatsapp_prefilled_message'] ) : '',
		);
	}

	/**
	 * Extract GDPR settings from POST.
	 *
	 * @return array
	 */
	// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in save_settings().
	private function extract_gdpr(): array {
		return array(
			'enabled'             => ! empty( $_POST['gdpr_enabled'] ),
			'consent_required'    => ! empty( $_POST['gdpr_consent_required'] ),
			'consent_message'     => isset( $_POST['gdpr_consent_message'] ) ? sanitize_textarea_field( $_POST['gdpr_consent_message'] ) : '',
			'show_privacy_link'   => ! empty( $_POST['gdpr_show_privacy_link'] ),
			'privacy_page_id'     => isset( $_POST['gdpr_privacy_page_id'] ) ? absint( $_POST['gdpr_privacy_page_id'] ) : 0,
			'data_retention_days' => isset( $_POST['gdpr_data_retention_days'] ) ? absint( $_POST['gdpr_data_retention_days'] ) : 90,
			'anonymize_ips'       => ! empty( $_POST['gdpr_anonymize_ips'] ),
		);
	}

	/**
	 * Extract chat logs settings from POST.
	 *
	 * @return array
	 */
	// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in save_settings().
	private function extract_chat_logs(): array {
		return array(
			'logging_enabled'   => ! empty( $_POST['logging_enabled'] ),
			'auto_cleanup_days' => isset( $_POST['auto_cleanup_days'] ) ? absint( $_POST['auto_cleanup_days'] ) : 90,
		);
	}

	/**
	 * Extract analytics settings from POST.
	 *
	 * @return array
	 */
	// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in save_settings().
	private function extract_analytics(): array {
		return array(
			'enabled'          => ! empty( $_POST['analytics_enabled'] ),
			'provider'         => isset( $_POST['analytics_provider'] ) ? sanitize_key( $_POST['analytics_provider'] ) : 'openai',
			'analyze_mode'     => isset( $_POST['analytics_analyze_mode'] ) ? sanitize_key( $_POST['analytics_analyze_mode'] ) : 'first_message',
			'alert_threshold'  => isset( $_POST['analytics_alert_threshold'] ) ? absint( $_POST['analytics_alert_threshold'] ) : 30,
			'alert_email'      => isset( $_POST['analytics_alert_email'] ) ? sanitize_email( wp_unslash( $_POST['analytics_alert_email'] ) ) : '',
			'attribution_days' => isset( $_POST['analytics_attribution_days'] ) ? absint( $_POST['analytics_attribution_days'] ) : 14,
		);
	}

	/**
	 * Render admin notice for negative sentiment alerts.
	 *
	 * @return void
	 */
	public function render_negative_sentiment_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$notice = get_transient( 'kivor_chat_agent_negative_alert_notice' );
		if ( ! is_array( $notice ) ) {
			return;
		}

		$ratio     = isset( $notice['ratio'] ) ? floatval( $notice['ratio'] ) : 0;
		$threshold = isset( $notice['threshold'] ) ? absint( $notice['threshold'] ) : 0;

		echo '<div class="notice notice-warning is-dismissible"><p>';
		echo esc_html(
			sprintf(
				/* translators: 1: current ratio, 2: threshold */
				__( 'Kivor Analytics Alert: Negative sentiment is %1$s%% (threshold %2$s%%). Review Insights for details.', 'kivor-chat-agent' ),
				$ratio,
				$threshold
			)
		);
		echo '</p></div>';

		delete_transient( 'kivor_chat_agent_negative_alert_notice' );
	}

	/**
	 * Extract rate limit settings from POST.
	 *
	 * @return array
	 */
	/**
	 * Extract phone call settings from POST.
	 *
	 * @return array
	 */
	// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in save_settings().
	private function extract_phone_call(): array {
		return array(
			'enabled'      => ! empty( $_POST['phone_call_enabled'] ),
			'mobile_only'  => ! empty( $_POST['phone_call_mobile_only'] ),
			'number'       => isset( $_POST['phone_call_number'] ) ? preg_replace( '/[^\d+]/', '', (string) $_POST['phone_call_number'] ) : '',
			'button_label' => isset( $_POST['phone_call_button_label'] ) ? sanitize_text_field( $_POST['phone_call_button_label'] ) : 'Call Support',
		);
	}

	/**
	 * Extract forms settings from POST.
	 *
	 * @return array
	 */
	// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in save_settings().
	private function extract_forms(): array {
		$primary_block_input = ! empty( $_POST['forms_primary_block_input'] );
		$primary_allow_skip  = ! empty( $_POST['forms_primary_allow_skip'] );

		if ( $primary_block_input ) {
			$primary_allow_skip = false;
		}

		return array(
			'enabled'              => ! empty( $_POST['forms_enabled'] ),
			'primary_form_id'      => isset( $_POST['forms_primary_form_id'] ) ? absint( $_POST['forms_primary_form_id'] ) : 0,
			'tab_form_id'          => isset( $_POST['forms_tab_form_id'] ) ? absint( $_POST['forms_tab_form_id'] ) : 0,
			'tab_label'            => isset( $_POST['forms_tab_label'] ) ? sanitize_text_field( $_POST['forms_tab_label'] ) : 'Form',
			'primary_block_input'  => $primary_block_input,
			'primary_allow_skip'   => $primary_allow_skip,
			'primary_submit_message' => isset( $_POST['forms_primary_submit_message'] ) ? sanitize_text_field( $_POST['forms_primary_submit_message'] ) : '',
			'show_field_titles'    => ! empty( $_POST['forms_show_field_titles'] ),
			'notify_email_enabled' => ! empty( $_POST['forms_notify_email_enabled'] ),
			'notify_email_to'      => isset( $_POST['forms_notify_email_to'] ) ? sanitize_text_field( $_POST['forms_notify_email_to'] ) : '',
		);
	}

	/**
	 * Extract external platform settings from POST.
	 *
	 * @return array
	 */
	// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in save_settings().
	private function extract_external_platforms(): array {
		return array(
			'wordpress' => array(
				'enabled'       => ! empty( $_POST['ext_wp_enabled'] ),
				'posts_enabled' => ! empty( $_POST['ext_wp_posts_enabled'] ),
				'pages_enabled' => ! empty( $_POST['ext_wp_pages_enabled'] ),
				'sync_mode'     => isset( $_POST['ext_wp_sync_mode'] ) ? sanitize_key( $_POST['ext_wp_sync_mode'] ) : 'incremental',
				'trigger'       => isset( $_POST['ext_wp_trigger'] ) ? sanitize_key( $_POST['ext_wp_trigger'] ) : 'daily',
			),
			'zendesk' => array(
				'enabled'   => ! empty( $_POST['ext_zendesk_enabled'] ),
				'subdomain' => isset( $_POST['ext_zendesk_subdomain'] ) ? sanitize_text_field( $_POST['ext_zendesk_subdomain'] ) : '',
				'email'     => isset( $_POST['ext_zendesk_email'] ) ? sanitize_email( $_POST['ext_zendesk_email'] ) : '',
				'api_token' => $this->get_key_from_post( 'ext_zendesk_api_token', 'external_platforms.zendesk.api_token' ),
				'sync_mode' => isset( $_POST['ext_zendesk_sync_mode'] ) ? sanitize_key( $_POST['ext_zendesk_sync_mode'] ) : 'incremental',
				'trigger'   => isset( $_POST['ext_zendesk_trigger'] ) ? sanitize_key( $_POST['ext_zendesk_trigger'] ) : 'manual',
			),
			'notion' => array(
				'enabled'     => ! empty( $_POST['ext_notion_enabled'] ),
				'api_key'     => $this->get_key_from_post( 'ext_notion_api_key', 'external_platforms.notion.api_key' ),
				'database_id' => isset( $_POST['ext_notion_database_id'] ) ? sanitize_text_field( $_POST['ext_notion_database_id'] ) : '',
				'sync_mode'   => isset( $_POST['ext_notion_sync_mode'] ) ? sanitize_key( $_POST['ext_notion_sync_mode'] ) : 'incremental',
				'trigger'     => isset( $_POST['ext_notion_trigger'] ) ? sanitize_key( $_POST['ext_notion_trigger'] ) : 'manual',
			),
			'content_options' => array(
				'split_by_headers' => ! empty( $_POST['ext_content_split_by_headers'] ),
				'add_read_more'    => ! empty( $_POST['ext_content_add_read_more'] ),
			),
		);
	}

	// =========================================================================
	// Asset enqueuing
	// =========================================================================

	/**
	 * Enqueue admin CSS and JS.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, $this->page_hooks, true ) ) {
			return;
		}

		wp_enqueue_media();

		$version = KIVOR_AGENT_VERSION;
		$asset_version = static function ( string $relative_path ) use ( $version ): string {
			$asset_path = KIVOR_AGENT_PATH . ltrim( $relative_path, '/' );
			return file_exists( $asset_path ) ? (string) filemtime( $asset_path ) : $version;
		};

		// CSS.
		wp_enqueue_style(
			'kivor-chat-agent-admin',
			KIVOR_AGENT_URL . 'admin/css/kivor-chat-agent-admin.css',
			array(),
			$asset_version( 'admin/css/kivor-chat-agent-admin.css' )
		);

		// JS.
		wp_enqueue_script(
			'kivor-chat-agent-admin',
			KIVOR_AGENT_URL . 'admin/js/kivor-chat-agent-admin.js',
			array(),
			$asset_version( 'admin/js/kivor-chat-agent-admin.js' ),
			true
		);

		wp_enqueue_style(
			'kivor-chat-agent-embeddings',
			KIVOR_AGENT_URL . 'admin/css/kivor-chat-agent-embeddings.css',
			array( 'kivor-chat-agent-admin' ),
			$asset_version( 'admin/css/kivor-chat-agent-embeddings.css' )
		);

		wp_enqueue_script(
			'kivor-chat-agent-embeddings',
			KIVOR_AGENT_URL . 'admin/js/kivor-chat-agent-embeddings.js',
			array( 'kivor-chat-agent-admin' ),
			$asset_version( 'admin/js/kivor-chat-agent-embeddings.js' ),
			true
		);

		// Pass config to JS.
		$config = array(
			'restUrl'           => esc_url_raw( rest_url( 'kivor-chat-agent/v1/admin/' ) ),
			'nonce'             => wp_create_nonce( 'wp_rest' ),
			'woocommerceActive' => class_exists( 'WooCommerce' ),
		);

		wp_add_inline_script(
			'kivor-chat-agent-admin',
			'var kivorChatAgentAdmin = ' . wp_json_encode( $config ) . ';',
			'before'
		);
	}

	// =========================================================================
	// Helpers for view partials
	// =========================================================================

	/**
	 * Mask an API key for display in form fields.
	 *
	 * @param string $key Full API key.
	 * @return string Masked key like "****abcd" or empty string.
	 */
	public static function mask_key( string $key ): string {
		if ( empty( $key ) ) {
			return '';
		}
		if ( strlen( $key ) <= 4 ) {
			return '****';
		}
		return '****' . substr( $key, -4 );
	}

	/**
	 * Mask long secret values that are not API keys.
	 *
	 * @param string $value Secret value.
	 * @return string
	 */
	public static function mask_secret( string $value ): string {
		if ( '' === trim( $value ) ) {
			return '';
		}

		return '***configured***';
	}

	/**
	 * Get an API key from POST data, preserving stored key if masked.
	 *
	 * @param string $post_key    The $_POST key name.
	 * @param string $setting_key Dot-notation key in settings.
	 * @return string
	 */
	private function get_key_from_post( string $post_key, string $setting_key ): string {
		$value = isset( $_POST[ $post_key ] ) ? sanitize_text_field( $_POST[ $post_key ] ) : '';

		// If the value is masked or empty, keep the stored key.
		if ( empty( $value ) || strpos( $value, '****' ) === 0 ) {
			$stored = $this->settings->get( $setting_key );
			return is_string( $stored ) ? $stored : '';
		}

		return $value;
	}

	/**
	 * Get embedding sync overview for WooCommerce products.
	 *
	 * @return array
	 */
	public function get_embedding_product_sync_overview(): array {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return array(
				'available' => false,
				'message'   => __( 'WooCommerce is not active.', 'kivor-chat-agent' ),
			);
		}

		$embedding_settings = $this->settings->get( 'embedding' );
		$vector_store_type  = $embedding_settings['vector_store'] ?? 'local';

		$vector_store = Kivor_Sync_Manager::create_vector_store( $vector_store_type, $embedding_settings );
		if ( is_wp_error( $vector_store ) ) {
			return array(
				'available' => false,
				'message'   => $vector_store->get_error_message(),
			);
		}

		$all_product_ids = wc_get_products(
			array(
				'status' => 'publish',
				'limit'  => -1,
				'return' => 'ids',
			)
		);

		if ( ! is_array( $all_product_ids ) ) {
			$all_product_ids = array();
		}

		$all_product_ids = array_values( array_unique( array_map( 'absint', $all_product_ids ) ) );
		$synced_ids      = array_values( array_unique( array_map( 'absint', $vector_store->get_stored_ids( 'product' ) ) ) );

		$unsynced_ids = array_values( array_diff( $all_product_ids, $synced_ids ) );
		$preview_ids  = array_slice( $unsynced_ids, 0, 20 );
		$preview      = array();

		if ( ! empty( $preview_ids ) ) {
			$products = wc_get_products(
				array(
					'include' => $preview_ids,
					'limit'   => count( $preview_ids ),
				)
			);

			foreach ( $products as $product ) {
				if ( ! $product instanceof \WC_Product ) {
					continue;
				}

				$preview[] = array(
					'id'   => $product->get_id(),
					'name' => $product->get_name(),
				);
			}
		}

		$synced_count = count( array_intersect( $all_product_ids, $synced_ids ) );

		return array(
			'available'      => true,
			'total'          => count( $all_product_ids ),
			'synced'         => $synced_count,
			'unsynced'       => count( $unsynced_ids ),
			'unsynced_items' => $preview,
			'has_more'       => count( $unsynced_ids ) > count( $preview ),
		);
	}

	/**
	 * Get settings instance.
	 *
	 * @return Kivor_Settings
	 */
	public function get_settings(): Kivor_Settings {
		return $this->settings;
	}

}
