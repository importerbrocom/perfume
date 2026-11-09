#!/usr/bin/env bash
# Installs WordPress + WooCommerce on LOCAL staging, activates the plugin, creates pages.
set -euo pipefail
cd "$(dirname "$0")"
set -a; . ./.env; set +a
wp() { docker compose run --rm -T wpcli "$@"; }
until wp db check >/dev/null 2>&1; do echo "waiting for database..."; sleep 3; done
wp core install --url=http://localhost:8080 --title="Perfumarathi Staging" \
  --admin_user=admin --admin_password="$ADMIN_PASSWORD" --admin_email=admin@example.test --skip-email
wp plugin install woocommerce --activate
wp plugin activate perfumarathi-shop
wp rewrite structure '/%postname%/' --hard
wp option update woocommerce_enable_guest_checkout yes
wp option update woocommerce_enable_signup_and_login_from_checkout yes
wp option update woocommerce_enable_myaccount_registration yes
wp option update woocommerce_calc_taxes no
wp option update woocommerce_currency AED
wp option update woocommerce_default_country AE:DU
wp option update woocommerce_cod_settings '{"enabled":"yes","title":"Cash on delivery"}' --format=json
track_id=$(wp post create --post_type=page --post_status=publish --post_title="Track Order" --post_content="[pm_order_tracking]" --porcelain)
wp option update pm_tracking_page_id "$track_id"
shop_id=$(wp option get woocommerce_shop_page_id)
wp post update "$shop_id" --post_content="[pm_shop]"
wp post create --post_type=page --post_status=publish --post_title="Perfumarathi" --post_content='[pm_shop brand="perfumarathi"]'
wp post create --post_type=page --post_status=publish --post_title="Fixnote" --post_content='[pm_shop brand="fixnote"]'
wp post create --post_type=page --post_status=publish --post_title="Staging Home" --post_content='[pm_cart_icon] [pm_home_shop]'
echo "Done. Site: http://localhost:8080  Admin: http://localhost:8080/wp-admin (user: admin)"
echo "Optional test products (STAGING ONLY): docker compose run --rm -T wpcli eval-file /seed-staging.php"
