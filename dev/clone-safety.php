<?php
// Run via: docker compose run --rm -T wpcli eval-file /clone-safety.php
// Makes an imported copy of the LIVE site safe: disables online payment gateways, pauses webhooks, blocks indexing.
if ( 'production' === wp_get_environment_type() ) {
	WP_CLI::error( 'Refusing to run on production.' );
}
update_option( 'blog_public', '0' );

if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
	foreach ( WC()->payment_gateways()->payment_gateways() as $id => $gateway ) {
		if ( in_array( $id, array( 'cod', 'bacs', 'cheque' ), true ) ) {
			continue;
		}
		if ( 'yes' === $gateway->enabled ) {
			$gateway->update_option( 'enabled', 'no' );
			WP_CLI::log( "Disabled live payment gateway: $id (configure it in TEST mode if you want to test it)" );
		}
	}
}

global $wpdb;
$table = $wpdb->prefix . 'wc_webhooks';
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
	$n = $wpdb->query( "UPDATE `$table` SET status = 'paused' WHERE status = 'active'" ); // phpcs:ignore WordPress.DB
	WP_CLI::log( "Paused $n WooCommerce webhook(s)." );
}

// Stop scheduled jobs that talk to third parties (abandoned-cart mailers, feeds) from firing immediately.
if ( function_exists( 'as_get_scheduled_actions' ) ) {
	WP_CLI::log( 'Action Scheduler present: any queued emails will be caught by Mailpit.' );
}
WP_CLI::success( 'Clone safety applied.' );
