<?php
/**
 * Sentiment analyzer service.
 *
 * Classifies user messages into positive, neutral, or negative.
 *
 * @package KivorAgent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class Kivor_Sentiment_Analyzer {

	/**
	 * Settings instance.
	 *
	 * @var Kivor_Settings
	 */
	private Kivor_Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Kivor_Settings $settings Plugin settings.
	 */
	public function __construct( Kivor_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Check if analytics sentiment is enabled.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return (bool) $this->settings->get( 'analytics.enabled', false );
	}

	/**
	 * Classify text sentiment.
	 *
	 * @param string $text User message.
	 * @return string One of: positive, neutral, negative.
	 */
	public function classify( string $text ): string {
		if ( ! $this->is_enabled() || '' === trim( $text ) ) {
			return '';
		}

		$provider_name = (string) $this->settings->get( 'analytics.provider', 'openai' );
		$provider      = Kivor_AI_Factory::create( $this->settings, $provider_name );

		if ( is_wp_error( $provider ) ) {
			return '';
		}

		$messages = array(
			array(
				'role'    => 'system',
				'content' => 'You are a sentiment classifier. Respond with exactly one lowercase word: positive, neutral, or negative.',
			),
			array(
				'role'    => 'user',
				'content' => $text,
			),
		);

		$result = $provider->chat( $messages, array(
			'temperature' => 0,
			'max_tokens'  => 3,
		) );

		if ( is_wp_error( $result ) ) {
			return '';
		}

		$raw = strtolower( trim( (string) ( $result['content'] ?? '' ) ) );
		if ( in_array( $raw, array( 'positive', 'neutral', 'negative' ), true ) ) {
			return $raw;
		}

		if ( false !== strpos( $raw, 'negative' ) ) {
			return 'negative';
		}
		if ( false !== strpos( $raw, 'positive' ) ) {
			return 'positive';
		}

		return 'neutral';
	}
}
