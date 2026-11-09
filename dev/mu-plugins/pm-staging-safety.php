<?php
/**
 * Plugin Name: PM Staging Safety (local Docker only)
 * Description: Routes ALL email to Mailpit, blocks search indexing and shows a STAGING banner.
 * Only active when the PM_MAIL_CATCHER environment variable is set (dev/docker-compose.yml). Never deploy to production.
 */
defined( 'ABSPATH' ) || exit;

$pm_catcher = getenv( 'PM_MAIL_CATCHER' );
if ( ! $pm_catcher || 'production' === wp_get_environment_type() ) {
	return;
}
list( $pm_host, $pm_port ) = array_pad( explode( ':', $pm_catcher, 2 ), 2, '1025' );

// Remove API-based mailers (they short-circuit wp_mail via pre_wp_mail) and other SMTP overrides.
$pm_strip = function () {
	remove_all_filters( 'pre_wp_mail' );
	remove_all_actions( 'phpmailer_init' );
	add_action( 'phpmailer_init', 'pm_staging_phpmailer', PHP_INT_MAX );
};
add_action( 'plugins_loaded', $pm_strip, PHP_INT_MAX );
add_action( 'init', $pm_strip, PHP_INT_MAX );

function pm_staging_phpmailer( $m ) {
	$c = explode( ':', (string) getenv( 'PM_MAIL_CATCHER' ), 2 );
	$m->isSMTP();
	$m->Host        = $c[0];
	$m->Port        = isset( $c[1] ) ? (int) $c[1] : 1025;
	$m->SMTPAuth    = false;
	$m->SMTPSecure  = '';
	$m->SMTPAutoTLS = false;
}
add_action( 'phpmailer_init', 'pm_staging_phpmailer', PHP_INT_MAX );

// Never indexable.
add_filter( 'pre_option_blog_public', function () { return '0'; } );
add_filter( 'wp_robots', function ( $r ) { $r['noindex'] = true; $r['nofollow'] = true; return $r; } );

// Visible marker for staff.
add_action( 'admin_notices', function () {
	echo '<div class="notice notice-warning"><p><strong>STAGING COPY</strong> &mdash; all emails go to Mailpit (http://localhost:8025). Live payment gateways and webhooks are disabled.</p></div>';
} );
add_action( 'wp_footer', function () {
	echo '<div style="position:fixed;left:8px;bottom:8px;z-index:100001;background:#b3261e;color:#fff;font:700 11px Arial;padding:4px 8px;border-radius:4px">STAGING</div>';
} );
