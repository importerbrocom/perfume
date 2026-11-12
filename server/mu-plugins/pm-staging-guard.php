<?php
/**
 * Plugin Name: PM Staging Guard (perfume.nokkoo.in only)
 * Description: For the cPanel STAGING site. (1) Sends every email to one staging inbox instead of the real recipient.
 * (2) Hides the site behind a staging key (logged-in users and WP-Cron are allowed). (3) Blocks search indexing.
 * Refuses to do anything unless WP_ENVIRONMENT_TYPE is "staging" and the host is the staging host. Never install on perfumarathi.com.
 */
defined( 'ABSPATH' ) || exit;

if ( 'staging' !== wp_get_environment_type() || ! defined( 'PM_STAGING_HOST' ) || ! defined( 'PM_STAGING_MAIL_TO' ) ) {
	return;
}

// Cloudflare terminates TLS: tell WordPress the request was HTTPS.
if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] ) {
	$_SERVER['HTTPS'] = 'on';
}

/* ---------- 1. All mail goes to the staging inbox ---------- */
add_filter( 'wp_mail', function ( $args ) {
	$orig            = is_array( $args['to'] ) ? implode( ', ', $args['to'] ) : (string) $args['to'];
	$args['to']      = PM_STAGING_MAIL_TO;
	$args['subject'] = '[STAGING to ' . $orig . '] ' . $args['subject'];
	// Drop CC/BCC so nobody else is ever copied.
	$headers         = is_array( $args['headers'] ) ? $args['headers'] : explode( "\n", str_replace( "\r\n", "\n", (string) $args['headers'] ) );
	$args['headers'] = array_values( array_filter( $headers, function ( $h ) { return ! preg_match( '/^\s*(cc|bcc)\s*:/i', $h ); } ) );
	return $args;
}, PHP_INT_MAX );
// API mailers bypass wp_mail's filters via pre_wp_mail: remove them.
add_action( 'plugins_loaded', function () { remove_all_filters( 'pre_wp_mail' ); }, PHP_INT_MAX );

/* ---------- 2. Staging gate ---------- */
function pm_gate_token() {
	return hash_hmac( 'sha256', (string) get_option( 'pm_staging_gate_key' ), wp_salt( 'auth' ) );
}
add_action( 'init', function () {
	$key = (string) get_option( 'pm_staging_gate_key' );
	if ( '' === $key || wp_doing_cron() || wp_doing_ajax() || ( defined( 'WP_CLI' ) && WP_CLI ) || is_user_logged_in() ) {
		return;
	}
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore
	if ( 0 === strpos( $uri, '/wp-login.php' ) || 0 === strpos( $uri, '/wp-cron.php' ) ) {
		return;
	}
	// Payment-provider webhooks (e.g. Stripe -> /?wc-api=wc_stripe) cannot send the staging key.
	// The gateway plugin verifies each call's signature itself; only these named endpoints are let through.
	$hooks = (array) apply_filters( 'pm_staging_gate_webhooks', array( 'wc_stripe' ) );
	// phpcs:ignore WordPress.Security.NonceVerification -- endpoint name only, compared against an allow-list.
	if ( isset( $_GET['wc-api'] ) && in_array( sanitize_key( wp_unslash( $_GET['wc-api'] ) ), array_map( 'sanitize_key', $hooks ), true ) ) {
		return;
	}
	$cookie = isset( $_COOKIE['pm_gate'] ) ? (string) $_COOKIE['pm_gate'] : '';
	$header = isset( $_SERVER['HTTP_X_PM_GATE'] ) ? (string) wp_unslash( $_SERVER['HTTP_X_PM_GATE'] ) : '';
	if ( ( $cookie && hash_equals( pm_gate_token(), $cookie ) ) || ( $header && hash_equals( $key, $header ) ) ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification -- shared staging key, compared in constant time.
	$given = isset( $_POST['pm_gate_key'] ) ? (string) wp_unslash( $_POST['pm_gate_key'] ) : '';
	if ( $given && hash_equals( $key, $given ) ) {
		setcookie( 'pm_gate', pm_gate_token(), array( 'expires' => time() + 30 * DAY_IN_SECONDS, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax' ) );
		wp_safe_redirect( home_url( $uri ) );
		exit;
	}
	status_header( 401 );
	nocache_headers();
	header( 'X-Robots-Tag: noindex, nofollow' );
	if ( 0 === strpos( $uri, '/wp-json/' ) ) {
		wp_send_json( array( 'code' => 'pm_staging_locked', 'message' => 'Staging site' ), 401 );
	}
	echo '<!doctype html><meta name="robots" content="noindex"><title>Staging</title><body style="font:15px Arial;display:grid;place-items:center;height:90vh;background:#fff8f2;color:#3e2a21">'
		. '<form method="post" style="text-align:center"><h1 style="letter-spacing:2px">PERFUMARATHI STAGING</h1>'
		. '<p>Private test site.</p><input type="password" name="pm_gate_key" placeholder="Staging key" required style="padding:10px 16px;border:1px solid #f2d4bc;border-radius:99px"> '
		. '<button style="padding:10px 22px;border:0;border-radius:99px;background:#ec7d28;color:#fff;font-weight:700">Enter</button></form>'
		. '<p style="font-size:12px"><a href="' . esc_url( wp_login_url() ) . '">Staff login</a></p></body>';
	exit;
}, 0 );

/* ---------- 3. Never indexable + visible marker ---------- */
add_filter( 'pre_option_blog_public', function () { return '0'; } );
add_filter( 'wp_robots', function ( $r ) { $r['noindex'] = true; $r['nofollow'] = true; return $r; } );
add_action( 'admin_notices', function () {
	echo '<div class="notice notice-warning"><p><strong>STAGING (perfume.nokkoo.in)</strong> &mdash; every email is redirected to ' . esc_html( PM_STAGING_MAIL_TO ) . '. This is not the live store.</p></div>';
} );
add_action( 'wp_footer', function () {
	echo '<div style="position:fixed;left:8px;bottom:8px;z-index:100001;background:#b3261e;color:#fff;font:700 11px Arial;padding:4px 8px;border-radius:4px">STAGING</div>';
} );
