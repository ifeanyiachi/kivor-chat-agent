<?php
/**
 * External knowledge source interface.
 *
 * @package KivorAgent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

interface Kivor_Knowledge_Source_Interface {

	/**
	 * Test source credentials/connectivity.
	 *
	 * @return true|WP_Error
	 */
	public function test_connection();

	/**
	 * Fetch normalized articles from the source.
	 *
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function fetch_articles();
}
