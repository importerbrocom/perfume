#!/usr/bin/env bash
# Idempotently creates the pages the plugin + E2E tests use. Safe on a fresh install or a live clone:
# existing pages are never overwritten. Usage: ./test-pages.sh [--seed]   (--seed adds [TEST] products)
set -euo pipefail
cd "$(dirname "$0")"
set -a; . ./.env; set +a
wp() { docker compose run --rm -T wpcli "$@"; }

page() { # slug title content [option]
  local id
  id=$(wp post list --post_type=page --name="$1" --post_status=publish --format=ids)
  if [ -z "$id" ]; then
    id=$(wp post create --post_type=page --post_status=publish --post_name="$1" --post_title="$2" --post_content="$3" --porcelain)
    echo "created /$1/ (#$id)"
  else
    echo "exists  /$1/ (#$id) - left unchanged"
  fi
  [ -n "${4:-}" ] && wp option update "$4" "$id" >/dev/null
  return 0
}
page shop-perfumarathi "Shop Perfumarathi" '[pm_shop brand="perfumarathi"]'
page shop-fixnote "Shop Fixnote" '[pm_shop brand="fixnote"]'
page track-order "Track Order" '[pm_order_tracking]' pm_tracking_page_id
page wishlist "Wishlist" '[pm_wishlist]' pm_wishlist_page_id
wp option update woocommerce_cod_settings '{"enabled":"yes","title":"Cash on delivery"}' --format=json >/dev/null
wp option update woocommerce_enable_guest_checkout yes >/dev/null
wp option update woocommerce_enable_myaccount_registration yes >/dev/null
wp rewrite flush >/dev/null
if [ "${1:-}" = "--seed" ]; then wp eval-file /seed-staging.php; fi
echo "Checkout: $(wp eval 'echo wp_make_link_relative(wc_get_checkout_url());')  My account: $(wp eval 'echo wp_make_link_relative(wc_get_page_permalink("myaccount"));')"
