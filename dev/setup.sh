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
wish_id=$(wp post create --post_type=page --post_status=publish --post_title="Wishlist" --post_content="[pm_wishlist]" --porcelain)
wp option update pm_wishlist_page_id "$wish_id"
wp post create --post_type=page --post_status=publish --post_title="Staging Home" --post_content='[pm_cart_icon] [pm_home_shop]'
echo "Done. Site: http://localhost:8080  Admin: http://localhost:8080/wp-admin (user: admin)"
echo "Optional test products (STAGING ONLY): docker compose run --rm -T wpcli eval-file /seed-staging.php"
