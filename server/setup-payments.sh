#!/usr/bin/env bash
# STAGING ONLY (perfume.nokkoo.in). Sets up payments for testing:
#   - Cash on Delivery: on
#   - Cards / Apple Pay / Google Pay: official "WooCommerce Stripe Payment Gateway" plugin, in TEST mode
# Optional: paste your Stripe TEST keys (pk_test_... / sk_test_...) when asked; input is hidden and not saved anywhere else.
# Live keys are refused here: staging must never take real payments.
# Run in cPanel > Terminal:  bash ~/setup-payments.sh      (safe to run again)
set -euo pipefail
DOCROOT=${DOCROOT:-$HOME/perfume.nokkoo.in}
WPCLI="$HOME/.wp-cli/pm-staging-wp-cli.phar"
PHP_BIN=${PHP_BIN:-php}
STRIPE_ZIP=${STRIPE_ZIP:-https://downloads.wordpress.org/plugin/woocommerce-gateway-stripe.latest-stable.zip}
die() { echo "STOPPED: $*" >&2; exit 1; }
say() { printf '\n==> %s\n' "$*"; }

say "Safety checks"
real=$(cd "$DOCROOT" 2>/dev/null && pwd -P) || die "$DOCROOT not found."
case "$real" in *perfumarathi.com*) die "$DOCROOT is the live site.";; esac
[ -f "$DOCROOT/wp-config.php" ] && grep -q "PM_STAGING_HOST" "$DOCROOT/wp-config.php" \
  || die "$DOCROOT is not the staging site (no PM_STAGING_HOST in wp-config.php). Not changing anything."
[ -f "$WPCLI" ] || die "WP-CLI copy missing ($WPCLI). Run install-staging.sh first."
wp() { "$PHP_BIN" -d memory_limit=256M "$WPCLI" --path="$DOCROOT" "$@"; }
echo "    staging site: $real"

say "Updating the staging guard (lets the Stripe webhook through the staging lock)"
BRANCH=${BRANCH:-main}
GUARD_URL=${GUARD_URL:-https://raw.githubusercontent.com/importerbrocom/perfume/$BRANCH/server/mu-plugins/pm-staging-guard.php}
G="$DOCROOT/wp-content/mu-plugins/pm-staging-guard.php"
if curl -fsSL "$GUARD_URL" -o "$G.new" && grep -q "PM Staging Guard" "$G.new" && grep -q "pm_staging_gate_webhooks" "$G.new"; then
  mv "$G.new" "$G"; echo "    updated"
else
  rm -f "$G.new"; echo "    could not download the new guard; kept the current one (webhook may be blocked by the staging lock)"
fi

say "1/3 Cash on Delivery"
wp option update woocommerce_cod_settings '{"enabled":"yes","title":"Cash on delivery","description":"Pay with cash when your order is delivered.","instructions":"Pay with cash upon delivery."}' --format=json --quiet
echo "    on"

say "2/3 Stripe (cards, Apple Pay, Google Pay)"
P="$DOCROOT/wp-content/plugins"
if [ -d "$P/woocommerce-gateway-stripe" ]; then
  echo "    already installed"
else
  TMP=$(mktemp -d "$HOME/.pm-pay-XXXXXX"); trap 'rm -rf "$TMP"' EXIT
  curl -fsSL "$STRIPE_ZIP" -o "$TMP/stripe.zip" || die "Could not download the Stripe plugin. Nothing changed."
  unzip -q "$TMP/stripe.zip" -d "$TMP" || die "Stripe plugin zip is damaged. Nothing changed."
  [ -f "$TMP/woocommerce-gateway-stripe/woocommerce-gateway-stripe.php" ] || die "Unexpected Stripe plugin contents. Nothing changed."
  cp -R "$TMP/woocommerce-gateway-stripe" "$P/"
  echo "    installed (official plugin by WooCommerce, from wordpress.org)"
fi
if ! wp plugin activate woocommerce-gateway-stripe --quiet; then
  die "Stripe plugin did not activate. Run: php ~/.wp-cli/pm-staging-wp-cli.phar --path=\"$DOCROOT\" plugin activate woocommerce-gateway-stripe  and send Kiro the output."
fi

say "3/3 Stripe test keys (optional)"
echo "    Get them at https://dashboard.stripe.com/test/apikeys (Developers > API keys, 'Test mode' on)."
read -rp "    Publishable TEST key (pk_test_..., or Enter to skip): " PK || PK=""
SK=""
if [ -n "$PK" ]; then
  read -rsp "    Secret TEST key (sk_test_..., hidden): " SK || SK=""; echo
fi
case "$PK$SK" in *_live_*) die "That is a LIVE key. Staging only accepts TEST keys (pk_test_/sk_test_). Nothing saved.";; esac
if [ -n "$PK" ]; then
  [[ "$PK" == pk_test_* && "$SK" == sk_test_* ]] || die "Keys must start with pk_test_ and sk_test_. Nothing saved."
fi
PM_PK="$PK" PM_SK="$SK" wp eval-file - <<'PHP'
<?php
// Stores settings through the gateway's own settings API (option woocommerce_stripe_settings).
$s = get_option( 'woocommerce_stripe_settings', array() );
$s = is_array( $s ) ? $s : array();
$s['testmode'] = 'yes';
if ( empty( $s['title'] ) ) { $s['title'] = 'Credit / Debit Card'; }
$pk = (string) getenv( 'PM_PK' ); $sk = (string) getenv( 'PM_SK' );
if ( '' !== $pk && '' !== $sk ) {
	$s['test_publishable_key'] = $pk;
	$s['test_secret_key']      = $sk;
	$s['enabled']              = 'yes';
	WP_CLI::log( '    test keys saved; card payments enabled in TEST mode' );
} else {
	WP_CLI::log( '    no keys entered; Stripe is installed but not enabled yet' );
}
update_option( 'woocommerce_stripe_settings', $s );
PHP
unset PK SK
wp cache flush >/dev/null 2>&1 || true

cat <<EOF

=====================================================================
 Payments on https://perfume.nokkoo.in (staging, TEST mode):
   Cash on Delivery ........ ON
   Cards (Stripe) .......... installed (enabled if you entered test keys)
 Finish Stripe in wp-admin > WooCommerce > Settings > Payments > Stripe:
   1. If not connected: 'Enter account keys' (TEST keys) or 'Connect' with your Stripe account.
   2. Webhook: in Stripe Dashboard > Developers > Webhooks add the URL shown
      on that settings page (https://perfume.nokkoo.in/?wc-api=wc_stripe) and paste the
      signing secret (whsec_...) back into the plugin. The staging lock lets this URL through.
   3. Test card 4242 4242 4242 4242, any future date, any CVC. Declined test: 4000 0000 0000 0002.
 Live payments only on perfumarathi.com after testing (docs/PAYMENTS.md). perfumarathi.com was NOT changed.
=====================================================================
EOF
