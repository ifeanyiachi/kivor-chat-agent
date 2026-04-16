<?php
/**
 * Feature gate helpers backed by Freemius.
 *
 * @package KivorAgent
 * @since   1.2.0
 */

defined( 'ABSPATH' ) || exit;

class Kivor_Feature_Gates {

	/**
	 * Lifetime limit for free webpage scans.
	 *
	 * @var int
	 */
	private const FREE_WEBPAGE_SCAN_LIMIT = 5;

	/**
	 * Option key for webpage scan usage counter.
	 *
	 * @var string
	 */
	private const WEBPAGE_SCAN_COUNT_OPTION = 'kivor_chat_agent_webpage_scan_count';

	/**
	 * Check if premium code can be used.
	 *
	 * @return bool
	 */
	public static function can_use_pro(): bool {
		if ( function_exists( 'kca_fs' ) ) {
			$fs = kca_fs();
			if ( is_object( $fs ) && method_exists( $fs, 'can_use_premium_code' ) ) {
				return (bool) $fs->can_use_premium_code();
			}
		}

		return false;
	}

	/**
	 * Determine whether a feature is available.
	 *
	 * @param string $feature_key Feature key.
	 * @return bool
	 */
	public static function is_feature_available( string $feature_key ): bool {
		if ( self::can_use_pro() ) {
			return true;
		}

		switch ( $feature_key ) {
			case 'knowledge_zendesk':
			case 'knowledge_notion':
			case 'embeddings_providers':
			case 'vector_store_pinecone':
			case 'vector_store_qdrant':
			case 'embeddings_submenu':
			case 'analytics_insights':
			case 'forms':
			case 'voice':
			case 'voice_providers':
			case 'phone_call':
			case 'advanced_styling':
				return false;
			case 'knowledge_webpage_scan':
				return self::get_webpage_scan_count() < self::FREE_WEBPAGE_SCAN_LIMIT;
		}

		return true;
	}

	/**
	 * Check whether a settings/admin group should be locked for free users.
	 *
	 * @param string $group Group key.
	 * @return bool
	 */
	public static function is_group_locked( string $group ): bool {
		if ( self::can_use_pro() ) {
			return false;
		}

		return in_array( $group, array( 'forms', 'analytics', 'chat_logs', 'phone_call', 'voice', 'styling', 'embeddings_submenu', 'insights_submenu' ), true );
	}

	/**
	 * Check if a knowledge source is available on current plan.
	 *
	 * @param string $source_type Source type.
	 * @return bool
	 */
	public static function is_knowledge_source_available( string $source_type ): bool {
		if ( self::can_use_pro() ) {
			return true;
		}

		switch ( $source_type ) {
			case 'zendesk':
			case 'notion':
				return false;
		}

		return true;
	}

	/**
	 * Get Freemius upgrade URL.
	 *
	 * @return string
	 */
	public static function get_upgrade_url(): string {
		if ( function_exists( 'kca_fs' ) ) {
			$fs = kca_fs();
			if ( is_object( $fs ) && method_exists( $fs, 'get_upgrade_url' ) ) {
				return (string) $fs->get_upgrade_url();
			}
		}

		return '';
	}

	/**
	 * Return a menu-safe premium badge.
	 *
	 * @return string
	 */
	public static function get_menu_badge_html(): string {
		if ( self::can_use_pro() ) {
			return '';
		}

		return ' <span class="kivor-chat-agent-pro-badge-menu">Pro</span>';
	}

	/**
	 * Render a lock notice block with upgrade CTA.
	 *
	 * @param string $title Notice title.
	 * @param string $message Notice message.
	 * @return void
	 */
	public static function render_lock_notice( string $title, string $message ): void {
		$upgrade_url = self::get_upgrade_url();

		echo '<div class="kivor-chat-agent-pro-lock-notice">';
		echo '<h3>' . esc_html( $title ) . ' <span class="kivor-chat-agent-pro-badge">Pro</span></h3>';

		if ( '' !== trim( $message ) ) {
			echo '<p>' . esc_html( $message ) . '</p>';
		}

		if ( '' !== $upgrade_url ) {
			echo '<p><a class="button button-primary" href="' . esc_url( $upgrade_url ) . '">' . esc_html__( 'Upgrade to Pro', 'kivor-chat-agent' ) . '</a></p>';
		}

		echo '</div>';
	}

	/**
	 * Get current lifetime webpage scan usage.
	 *
	 * @return int
	 */
	public static function get_webpage_scan_count(): int {
		return max( 0, absint( get_option( self::WEBPAGE_SCAN_COUNT_OPTION, 0 ) ) );
	}

	/**
	 * Get free lifetime webpage scan limit.
	 *
	 * @return int
	 */
	public static function get_webpage_scan_limit(): int {
		return self::FREE_WEBPAGE_SCAN_LIMIT;
	}

	/**
	 * Increment webpage scan usage after a successful scan.
	 *
	 * @return void
	 */
	public static function increment_webpage_scan_count(): void {
		if ( self::can_use_pro() ) {
			return;
		}

		$count = self::get_webpage_scan_count();
		update_option( self::WEBPAGE_SCAN_COUNT_OPTION, $count + 1, true );
	}

	/**
	 * Enforce free-plan restrictions for settings groups.
	 *
	 * @param string         $group    Settings group.
	 * @param array          $values   Submitted values.
	 * @param Kivor_Settings $settings Settings instance.
	 * @return array
	 */
	public static function enforce_group_restrictions( string $group, array $values, Kivor_Settings $settings ): array {
		if ( self::can_use_pro() ) {
			return $values;
		}

		$current_embedding = $settings->get( 'embedding', array() );

			switch ( $group ) {
			case 'forms':
				$values['enabled'] = false;
				$values['primary_form_id'] = 0;
				$values['tab_form_id'] = 0;
				break;
			case 'analytics':
				$values['enabled'] = false;
				break;
			case 'chat_logs':
				$values['logging_enabled'] = false;
				break;
			case 'phone_call':
				$values['enabled'] = false;
				break;
			case 'embedding':
				$values['active_provider'] = sanitize_key( (string) ( $current_embedding['active_provider'] ?? 'openai' ) );
				$values['fallback_provider'] = 'local';
				$values['sync_on_product_save'] = false;
				$values['vector_store'] = 'local';

				if ( isset( $current_embedding['providers'] ) && is_array( $current_embedding['providers'] ) ) {
					$values['providers'] = $current_embedding['providers'];
				}

				if ( isset( $current_embedding['pinecone'] ) && is_array( $current_embedding['pinecone'] ) ) {
					$values['pinecone'] = $current_embedding['pinecone'];
				}

				if ( isset( $current_embedding['qdrant'] ) && is_array( $current_embedding['qdrant'] ) ) {
					$values['qdrant'] = $current_embedding['qdrant'];
				}
				break;
			case 'voice':
				$values['enabled'] = false;
				$values['input_enabled'] = false;
				$values['stt_provider'] = 'webspeech';
				break;
			case 'external_platforms':
				if ( isset( $values['wordpress'] ) && is_array( $values['wordpress'] ) ) {
					$values['wordpress']['enabled'] = ! empty( $values['wordpress']['enabled'] );
				}
				if ( isset( $values['zendesk'] ) && is_array( $values['zendesk'] ) ) {
					$values['zendesk']['enabled'] = false;
				}
				if ( isset( $values['notion'] ) && is_array( $values['notion'] ) ) {
					$values['notion']['enabled'] = false;
				}
				break;
		}

		return $values;
	}
}
