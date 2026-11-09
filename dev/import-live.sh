#!/usr/bin/env bash
# Import a copy of the LIVE site into local Docker staging, made safe for testing.
#
#   ./import-live.sh <database.sql|database.sql.gz> <wp-content.zip|wp-content.tar.gz|wp-content-dir> [LIVE_URL]
#
# LIVE_URL defaults to https://perfumarathi.com. See docs/STAGING-CLONE.md for how to export both files from the host.
# Result: http://localhost:8080 (live data + perfumarathi-shop plugin), emails in Mailpit http://localhost:8025.
# The dump contains customer data and password hashes: keep it on your machine, never commit it.
set -euo pipefail
cd "$(dirname "$0")"

DB_FILE=${1:?Usage: ./import-live.sh <db.sql[.gz]> <wp-content archive or dir> [LIVE_URL]}
CONTENT=${2:?Usage: ./import-live.sh <db.sql[.gz]> <wp-content archive or dir> [LIVE_URL]}
LIVE_URL=${3:-https://perfumarathi.com}
LOCAL_URL=http://localhost:8080
[ -f "$DB_FILE" ] || { echo "Database file not found: $DB_FILE"; exit 1; }
[ -e "$CONTENT" ] || { echo "wp-content not found: $CONTENT"; exit 1; }
[ -f .env ] || { echo "Create dev/.env first: cp .env.dev.example .env (and set passwords)"; exit 1; }
DB_FILE=$(cd "$(dirname "$DB_FILE")" && pwd)/$(basename "$DB_FILE")
CONTENT=$(cd "$(dirname "$CONTENT")" && pwd)/$(basename "$CONTENT")

cat_db() { case "$DB_FILE" in *.gz) gzip -dc "$DB_FILE" ;; *) cat "$DB_FILE" ;; esac; }
# MariaDB dumps -> MySQL 8: drop the MariaDB sandbox line and map MariaDB-only collations.
clean_db() { sed -e '/^\/\*M!999999/d' -e 's/utf8mb4_uca1400_ai_ci/utf8mb4_unicode_ci/g' -e 's/utf8mb3_uca1400_ai_ci/utf8mb3_unicode_ci/g'; }

echo "==> Detecting table prefix"
PREFIX=$(cat_db | grep -m1 -oE 'CREATE TABLE `[A-Za-z0-9_]+options`' | sed -E 's/CREATE TABLE `(.*)options`/\1/' || true)
[ -n "$PREFIX" ] || { echo "Could not find an <prefix>options table in the dump. Is this a WordPress database export?"; exit 1; }
echo "    prefix: $PREFIX"
if grep -q '^TABLE_PREFIX=' .env; then sed -i.bak "s/^TABLE_PREFIX=.*/TABLE_PREFIX=$PREFIX/" .env && rm -f .env.bak; else echo "TABLE_PREFIX=$PREFIX" >> .env; fi
set -a; . ./.env; set +a

wp() { docker compose run --rm -T wpcli "$@"; }
dbroot() { docker compose exec -T db sh -c 'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" --default-character-set=utf8mb4 "$@"' sh "$@"; }

echo "==> Starting containers"
docker compose up -d --force-recreate db wordpress mailpit
for i in $(seq 1 80); do
  docker compose exec -T db sh -c 'mysql -h127.0.0.1 -uwp -p"$MYSQL_PASSWORD" -e "SELECT 1" wordpress' >/dev/null 2>&1 && break
  [ "$i" = 80 ] && { echo "Database did not start"; docker compose logs --tail=40 db; exit 1; }
  sleep 3
done
for i in $(seq 1 60); do
  docker compose exec -T wordpress test -f /var/www/html/wp-config.php && break
  [ "$i" = 60 ] && { echo "WordPress files not ready"; exit 1; }
  sleep 2
done

echo "==> Importing database (this can take a while)"
dbroot -e "DROP DATABASE IF EXISTS wordpress; CREATE DATABASE wordpress CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON wordpress.* TO 'wp'@'%'; FLUSH PRIVILEGES;"
cat_db | clean_db | dbroot wordpress

echo "==> Copying wp-content"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
case "$CONTENT" in
  *.zip) unzip -q "$CONTENT" -d "$TMP/x" ;;
  *.tar.gz|*.tgz) mkdir -p "$TMP/x" && tar -xzf "$CONTENT" -C "$TMP/x" ;;
  *.tar) mkdir -p "$TMP/x" && tar -xf "$CONTENT" -C "$TMP/x" ;;
  *) [ -d "$CONTENT" ] || { echo "Unsupported wp-content format: $CONTENT"; exit 1; }; mkdir -p "$TMP/x" && cp -a "$CONTENT/." "$TMP/x/" ;;
esac
SRC=""
for d in "$TMP/x" $(find "$TMP/x" -mindepth 1 -maxdepth 3 -type d -name plugins -exec dirname {} \;); do
  if [ -d "$d/plugins" ] && [ -d "$d/themes" ]; then SRC=$d; break; fi
done
[ -n "$SRC" ] || { echo "Could not find a wp-content folder (with plugins/ and themes/) in $CONTENT"; exit 1; }
# Our plugin + safety mu-plugin are mounted from the repo; host caches/drop-ins break locally.
rm -rf "$SRC/plugins/perfumarathi-shop" "$SRC/mu-plugins/pm-staging-safety.php" \
       "$SRC/object-cache.php" "$SRC/advanced-cache.php" "$SRC/db.php" "$SRC/cache" "$SRC/litespeed" "$SRC/upgrade"
docker compose cp "$SRC/." wordpress:/var/www/html/wp-content/
docker compose exec -T wordpress sh -c "find /var/www/html/wp-content -path '*/plugins/perfumarathi-shop' -prune -o -path '*/mu-plugins/pm-staging-safety.php' -prune -o -exec chown www-data:www-data {} +"

echo "==> Rewriting URLs $LIVE_URL -> $LOCAL_URL"
HOST=$(echo "$LIVE_URL" | sed -E 's#^https?://##; s#/.*$##; s#^www\.##')
for from in "https://www.$HOST" "http://www.$HOST" "https://$HOST" "http://$HOST"; do
  wp search-replace "$from" "$LOCAL_URL" --all-tables-with-prefix --precise --skip-columns=guid --skip-plugins --skip-themes --report-changed-only --quiet
  esc_from=${from//\//\\/}; esc_to=${LOCAL_URL//\//\\/}   # JSON-escaped (Elementor data)
  wp search-replace "$esc_from" "$esc_to" --all-tables-with-prefix --precise --skip-columns=guid --skip-plugins --skip-themes --report-changed-only --quiet
done
wp option update home "$LOCAL_URL" --skip-plugins --skip-themes
wp option update siteurl "$LOCAL_URL" --skip-plugins --skip-themes

echo "==> Safety: deactivating cache / SMTP / force-SSL plugins (would break local or send real mail)"
for p in litespeed-cache wp-mail-smtp post-smtp fluent-smtp easy-wp-smtp smtp-mailer wp-ses really-simple-ssl ssl-insecure-content-fixer wp-rocket w3-total-cache wp-super-cache; do
  wp plugin is-active "$p" --skip-plugins --skip-themes >/dev/null 2>&1 && wp plugin deactivate "$p" --skip-plugins --skip-themes && echo "    deactivated $p" || true
done

echo "==> Staging admin user (live admin logins also still work)"
if wp user get pmstaging --skip-plugins --skip-themes >/dev/null 2>&1; then
  wp user update pmstaging --user_pass="$ADMIN_PASSWORD" --skip-plugins --skip-themes --skip-email
else
  wp user create pmstaging staging-admin@example.test --role=administrator --user_pass="$ADMIN_PASSWORD" --skip-plugins --skip-themes
fi

echo "==> Activating perfumarathi-shop alongside the live plugins"
if ! wp plugin activate perfumarathi-shop; then
  echo "ERROR: plugin activation failed against the live plugin set. Output above shows the PHP error."; exit 1
fi
wp eval-file /clone-safety.php
wp rewrite flush
wp elementor flush-css >/dev/null 2>&1 || true
wp cache flush >/dev/null 2>&1 || true

echo "==> Summary"
wp eval 'echo "WordPress ".get_bloginfo("version")." | WooCommerce ".(defined("WC_VERSION")?WC_VERSION:"not active")." | theme ".wp_get_theme()->get("Name")."\n";
$c=wp_count_posts("product"); echo "Products: ".(int)($c->publish??0)." published, ".(int)($c->draft??0)." draft\n";
echo "Orders: ".(function_exists("wc_orders_count")?array_sum(array_map(function($s){return wc_orders_count(substr($s,3));},array_keys(wc_get_order_statuses()))):0)."\n";
echo "Checkout page uses: ".(has_block("woocommerce/checkout",(int)get_option("woocommerce_checkout_page_id"))?"BLOCK checkout":"classic shortcode")."\n";'
wp plugin list --status=active --fields=name,version --skip-themes
cat <<EOF

Done.
  Site:    $LOCAL_URL            (STAGING banner bottom-left)
  Admin:   $LOCAL_URL/wp-admin   user: pmstaging  password: ADMIN_PASSWORD from dev/.env
  Emails:  http://localhost:8025 (Mailpit) - nothing is sent to real customers
Next: ./test-pages.sh  then run the Playwright suite (docs/STAGING-CLONE.md).
EOF
