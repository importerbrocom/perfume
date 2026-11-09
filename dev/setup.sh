#!/usr/bin/env bash
# Installs WordPress + WooCommerce on LOCAL staging, activates the plugin, creates pages.
set -euo pipefail
cd "$(dirname "$0")"
set -a; . ./.env; set +a
wp() { docker compose run --rm -T wpcli "$@"; }
# Wait (max ~4 min) for WordPress files + wp-config + DB, using PHP only (no mysql client needed).
ready=0
for i in $(seq 1 80); do
  # --skip-wordpress: WordPress is not installed yet, so only test wp-config presence + a raw DB connection.
  if wp config path >/dev/null 2>&1 && \
     wp eval --skip-wordpress 'exit( @mysqli_connect( getenv("WORDPRESS_DB_HOST"), getenv("WORDPRESS_DB_USER"), getenv("WORDPRESS_DB_PASSWORD"), getenv("WORDPRESS_DB_NAME") ) ? 0 : 1 );' >/dev/null 2>&1; then ready=1; break; fi
  echo "waiting for WordPress/database ($i/80)..."; sleep 3
done
if [ "$ready" != "1" ]; then
  echo "ERROR: WordPress/database not ready. Diagnostics:"; docker compose ps; docker compose logs --tail=40 db wordpress; wp config path || true; exit 1
fi
wp core install --url=http://localhost:8080 --title="Perfumarathi Staging" \
  --admin_user=admin --admin_password="$ADMIN_PASSWORD" --admin_email=admin@example.test --skip-email
wp plugin install woocommerce --activate
wp plugin activate perfumarathi-shop
# Flush WooCommerce's post-install background jobs now (one of them turns on "Coming soon" mode mid-test otherwise).
wp action-scheduler run --batches=5 >/dev/null 2>&1 || true
wp cron event run --due-now >/dev/null 2>&1 || true
wp rewrite structure '/%postname%/' --hard
wp option update woocommerce_enable_guest_checkout yes
wp option update woocommerce_enable_signup_and_login_from_checkout yes
wp option update woocommerce_enable_myaccount_registration yes
wp option update woocommerce_calc_taxes no
wp option update woocommerce_currency AED
wp option update woocommerce_default_country AE:DU
wp option update woocommerce_cod_settings '{"enabled":"yes","title":"Cash on delivery"}' --format=json
# Classic shortcode cart/checkout (matches the classic skt-fragrance theme on the live site).
wp post update "$(wp option get woocommerce_checkout_page_id)" --post_content="[woocommerce_checkout]"
wp post update "$(wp option get woocommerce_cart_page_id)" --post_content="[woocommerce_cart]"
wp post update "$(wp option get woocommerce_shop_page_id)" --post_content="[pm_shop]"
wp post create --post_type=page --post_status=publish --post_title="Staging Home" --post_content='[pm_cart_icon] [pm_home_shop]'
./test-pages.sh
echo "Done. Site: http://localhost:8080  Admin: http://localhost:8080/wp-admin (user: admin)"
echo "Emails: http://localhost:8025 (Mailpit).  Test products (STAGING ONLY): ./test-pages.sh --seed"
