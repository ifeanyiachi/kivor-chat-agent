<?php
/**
 * Insights statistics tab.
 *
 * @package KivorAgent
 * @since   1.0.0
 * @var array $settings All plugin settings.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$analytics = new Kivor_Analytics( $admin->get_settings() );
$summary   = $analytics->get_summary();

if ( ! Kivor_Feature_Gates::is_feature_available( 'analytics_insights' ) ) {
	Kivor_Feature_Gates::render_lock_notice(
		__( 'Insights Are Available in Pro', 'admin' ),
		__( 'Upgrade to unlock conversation analytics, sentiment trends, and conversion insights.', 'admin' )
	);

	echo '<p class="description">' . esc_html__( 'This page remains visible for preview, but interaction and analytics collection are locked in the free plan.', 'admin' ) . '</p>';
	return;
}

if ( ! empty( $settings['analytics']['enabled'] ) ) {
	$analytics_enabled = true;
} else {
	$analytics_enabled = false;
}

if ( ! $analytics_enabled ) {
	echo '<p class="description">' . esc_html__( 'Analytics is currently disabled. Enable it in Analytics Settings to start collecting sentiment and conversion insights.', 'admin' ) . '</p>';
	return;
}

$sentiment_counts = $summary['sentiment']['counts'] ?? array();
$conversion       = $summary['conversion'] ?? array();
$top_keywords     = $summary['top_keywords'] ?? array();
$peak_hours       = $summary['peak_hours'] ?? array();
?>

<h2><?php esc_html_e( 'Analytics Overview', 'admin' ); ?></h2>

<div class="kivor-chat-agent-stats-grid">
	<div class="kivor-chat-agent-stat-card">
		<div class="kivor-chat-agent-stat-card__label"><?php esc_html_e( 'Total Conversations', 'admin' ); ?></div>
		<div class="kivor-chat-agent-stat-card__value"><?php echo esc_html( (string) ( $summary['total_conversations'] ?? 0 ) ); ?></div>
	</div>
	<div class="kivor-chat-agent-stat-card">
		<div class="kivor-chat-agent-stat-card__label"><?php esc_html_e( 'Messages / Conversation', 'admin' ); ?></div>
		<div class="kivor-chat-agent-stat-card__value"><?php echo esc_html( (string) ( $summary['avg_messages'] ?? 0 ) ); ?></div>
	</div>
	<div class="kivor-chat-agent-stat-card">
		<div class="kivor-chat-agent-stat-card__label"><?php esc_html_e( 'Bounce Rate', 'admin' ); ?></div>
		<div class="kivor-chat-agent-stat-card__value"><?php echo esc_html( (string) ( $summary['bounce_rate'] ?? 0 ) ); ?>%</div>
	</div>
	<div class="kivor-chat-agent-stat-card">
		<div class="kivor-chat-agent-stat-card__label"><?php esc_html_e( 'Avg Session Duration', 'admin' ); ?></div>
		<div class="kivor-chat-agent-stat-card__value"><?php echo esc_html( (string) ( $summary['avg_session_duration_seconds'] ?? 0 ) ); ?>s</div>
	</div>
	<div class="kivor-chat-agent-stat-card">
		<div class="kivor-chat-agent-stat-card__label"><?php esc_html_e( 'Avg Response Time', 'admin' ); ?></div>
		<div class="kivor-chat-agent-stat-card__value"><?php echo esc_html( (string) ( $summary['avg_response_time_seconds'] ?? 0 ) ); ?>s</div>
	</div>
</div>

<hr>

<h3><?php esc_html_e( 'Sentiment', 'admin' ); ?></h3>
<div class="kivor-chat-agent-stats-grid">
	<div class="kivor-chat-agent-stat-card">
		<div class="kivor-chat-agent-stat-card__label"><?php esc_html_e( 'Positive', 'admin' ); ?></div>
		<div class="kivor-chat-agent-stat-card__value"><?php echo esc_html( (string) ( $sentiment_counts['positive'] ?? 0 ) ); ?></div>
	</div>
	<div class="kivor-chat-agent-stat-card">
		<div class="kivor-chat-agent-stat-card__label"><?php esc_html_e( 'Neutral', 'admin' ); ?></div>
		<div class="kivor-chat-agent-stat-card__value"><?php echo esc_html( (string) ( $sentiment_counts['neutral'] ?? 0 ) ); ?></div>
	</div>
	<div class="kivor-chat-agent-stat-card">
		<div class="kivor-chat-agent-stat-card__label"><?php esc_html_e( 'Negative', 'admin' ); ?></div>
		<div class="kivor-chat-agent-stat-card__value"><?php echo esc_html( (string) ( $sentiment_counts['negative'] ?? 0 ) ); ?></div>
	</div>
</div>

<hr>

<h3><?php esc_html_e( 'Conversion Funnel', 'admin' ); ?></h3>
<div class="kivor-chat-agent-stats-grid">
	<div class="kivor-chat-agent-stat-card">
		<div class="kivor-chat-agent-stat-card__label"><?php esc_html_e( 'Recommended', 'admin' ); ?></div>
		<div class="kivor-chat-agent-stat-card__value"><?php echo esc_html( (string) ( $conversion['recommended'] ?? 0 ) ); ?></div>
	</div>
	<div class="kivor-chat-agent-stat-card">
		<div class="kivor-chat-agent-stat-card__label"><?php esc_html_e( 'Clicked', 'admin' ); ?></div>
		<div class="kivor-chat-agent-stat-card__value"><?php echo esc_html( (string) ( $conversion['clicked'] ?? 0 ) ); ?> (<?php echo esc_html( (string) ( $conversion['ctr'] ?? 0 ) ); ?>%)</div>
	</div>
	<div class="kivor-chat-agent-stat-card">
		<div class="kivor-chat-agent-stat-card__label"><?php esc_html_e( 'Added To Cart', 'admin' ); ?></div>
		<div class="kivor-chat-agent-stat-card__value"><?php echo esc_html( (string) ( $conversion['added'] ?? 0 ) ); ?> (<?php echo esc_html( (string) ( $conversion['cart_rate'] ?? 0 ) ); ?>%)</div>
	</div>
	<div class="kivor-chat-agent-stat-card">
		<div class="kivor-chat-agent-stat-card__label"><?php esc_html_e( 'Purchased', 'admin' ); ?></div>
		<div class="kivor-chat-agent-stat-card__value"><?php echo esc_html( (string) ( $conversion['purchased'] ?? 0 ) ); ?> (<?php echo esc_html( (string) ( $conversion['purchase_rate'] ?? 0 ) ); ?>%)</div>
	</div>
	<div class="kivor-chat-agent-stat-card">
		<div class="kivor-chat-agent-stat-card__label"><?php esc_html_e( 'Attributed Revenue', 'admin' ); ?></div>
		<div class="kivor-chat-agent-stat-card__value">$<?php echo esc_html( number_format_i18n( (float) ( $conversion['revenue'] ?? 0 ), 2 ) ); ?></div>
	</div>
</div>

<hr>

<h3><?php esc_html_e( 'Popular Keywords', 'admin' ); ?></h3>
<?php if ( ! empty( $top_keywords ) ) : ?>
	<table class="widefat striped">
		<thead>
		<tr>
			<th><?php esc_html_e( 'Keyword', 'admin' ); ?></th>
			<th><?php esc_html_e( 'Count', 'admin' ); ?></th>
		</tr>
		</thead>
		<tbody>
		<?php foreach ( $top_keywords as $row ) : ?>
			<tr>
				<td><?php echo esc_html( (string) ( $row['keyword'] ?? '' ) ); ?></td>
				<td><?php echo esc_html( (string) ( $row['cnt'] ?? 0 ) ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
<?php else : ?>
	<p class="description"><?php esc_html_e( 'No keyword data available yet.', 'admin' ); ?></p>
<?php endif; ?>

<h3><?php esc_html_e( 'Peak Usage Times (UTC Hour)', 'admin' ); ?></h3>
<?php if ( ! empty( $peak_hours ) ) : ?>
	<table class="widefat striped">
		<thead>
		<tr>
			<th><?php esc_html_e( 'Hour', 'admin' ); ?></th>
			<th><?php esc_html_e( 'Messages', 'admin' ); ?></th>
		</tr>
		</thead>
		<tbody>
		<?php foreach ( $peak_hours as $row ) : ?>
			<tr>
				<td><?php echo esc_html( sprintf( '%02d:00', absint( $row['hr'] ?? 0 ) ) ); ?></td>
				<td><?php echo esc_html( (string) ( $row['cnt'] ?? 0 ) ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
<?php else : ?>
	<p class="description"><?php esc_html_e( 'No usage time data available yet.', 'admin' ); ?></p>
<?php endif; ?>

<h3><?php esc_html_e( 'Trending Negative Topics', 'admin' ); ?></h3>
<?php $negative_topics = $summary['negative_topics'] ?? array(); ?>
<?php if ( ! empty( $negative_topics ) ) : ?>
	<table class="widefat striped">
		<thead>
		<tr>
			<th><?php esc_html_e( 'Keyword', 'admin' ); ?></th>
			<th><?php esc_html_e( 'Count', 'admin' ); ?></th>
		</tr>
		</thead>
		<tbody>
		<?php foreach ( $negative_topics as $row ) : ?>
			<tr>
				<td><?php echo esc_html( (string) ( $row['keyword'] ?? '' ) ); ?></td>
				<td><?php echo esc_html( (string) ( $row['cnt'] ?? 0 ) ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
<?php else : ?>
	<p class="description"><?php esc_html_e( 'No negative topic trends available yet.', 'admin' ); ?></p>
<?php endif; ?>
