#!/usr/bin/env bash
# Builds a SEPARATE staging store at https://perfume.nokkoo.in on cPanel. It never touches perfumarathi.com.
#
# Run in cPanel > Terminal (after creating the perfume.nokkoo.in domain in cPanel > Domains):
#   curl -fsSL https://raw.githubusercontent.com/importerbrocom/perfume/main/server/install-staging.sh -o ~/install-staging.sh
#   bash ~/install-staging.sh
#
# It asks for the database password (input hidden; not saved in shell history or GitHub).
# It stops without changing anything if the folder or database is not empty, or points at the live site.
set -euo pipefail

DOMAIN=perfume.nokkoo.in
DOCROOT=${DOCROOT:-$HOME/$DOMAIN}
DB_NAME=${DB_NAME:-uddjzwrz_perfume}
DB_USER=${DB_USER:-uddjzwrz_perfume}
PREFIX=pmstg_
BRANCH=${BRANCH:-main}
ZIP_URL="https://codeload.github.com/importerbrocom/perfume/zip/refs/heads/$BRANCH"
LIVE_DOCROOT="$HOME/perfumarathi.com"

die() { echo "STOPPED: $*" >&2; exit 1; }
say() { printf '\n==> %s\n' "$*"; }

# ---------- Safety checks (nothing is changed before these pass) ----------
say "Safety checks"
[ -d "$DOCROOT" ] || die "$DOCROOT does not exist. Create the domain first: cPanel > Domains > Create A New Domain > $DOMAIN (document root: $DOMAIN)."
real_doc=$(cd "$DOCROOT" && pwd -P)
case "$real_doc" in *perfumarathi.com*) die "Document root resolves to the LIVE site ($real_doc).";; esac
if [ -d "$LIVE_DOCROOT" ] && [ "$real_doc" = "$(cd "$LIVE_DOCROOT" && pwd -P)" ]; then die "Document root is the LIVE site."; fi
[ "$real_doc" != "$(cd "$HOME/public_html" 2>/dev/null && pwd -P || echo none)" ] || die "Document root is public_html (main domain). Use a separate folder."
# Files cPanel/LiteSpeed/Imunify create in a new document root are allowed (and left untouched).
leftover=$(find "$DOCROOT" -mindepth 1 -maxdepth 1 ! -name '.well-known' ! -name 'cgi-bin' ! -name '.htaccess' ! -name 'error_log' ! -name 'php.ini' ! -name '.user.ini' | head -5)
[ -z "$leftover" ] || die "$DOCROOT is not empty:
$leftover
Not overwriting anything."
echo "    folder: $real_doc (empty) | database: $DB_NAME | user: $DB_USER | table prefix: $PREFIX"

# ---------- WP-CLI ----------
if command -v wp >/dev/null 2>&1; then WP=(wp); else
  say "Downloading WP-CLI"
  curl -fsSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o "$HOME/.wp-cli/wp-cli.phar" --create-dirs
  WP=(php -d memory_limit=256M "$HOME/.wp-cli/wp-cli.phar")
fi
wp() { "${WP[@]}" --path="$DOCROOT" "$@"; }
export WP_CLI_PHP_ARGS="-d memory_limit=256M"

# ---------- Inputs ----------
read -rsp "Database password for $DB_USER: " DB_PASS; echo
[ -n "$DB_PASS" ] || die "No password entered."
read -rp "Email address that should receive ALL staging emails: " STAGING_MAIL
[[ "$STAGING_MAIL" == *@*.* ]] || die "That does not look like an email address."

# ---------- WordPress ----------
# Downloads use curl + tar/unzip (low memory; shared-hosting LVE limits kill PHP-based unpacking).
# Everything is unpacked in a temp folder first, so a failure never leaves partial files in the document root.
TMP=$(mktemp -d "$HOME/.pm-staging-XXXXXX"); trap 'rm -rf "$TMP"' EXIT
say "Downloading WordPress (curl + tar)"
mkdir -p "$TMP/wp"
curl -fsSL https://wordpress.org/latest.tar.gz | tar -xz --strip-components=1 -C "$TMP/wp" \
  || die "WordPress download failed. Nothing was changed in $DOCROOT."
[ -f "$TMP/wp/wp-load.php" ] || die "WordPress download incomplete. Nothing was changed in $DOCROOT."
say "Downloading WooCommerce and perfumarathi-shop"
curl -fsSL https://downloads.wordpress.org/plugin/woocommerce.latest-stable.zip -o "$TMP/woo.zip" \
  || die "WooCommerce download failed. Nothing was changed in $DOCROOT."
unzip -q "$TMP/woo.zip" -d "$TMP/wp/wp-content/plugins" || die "WooCommerce unzip failed. Nothing was changed."
curl -fsSL "$ZIP_URL" -o "$TMP/repo.zip" || die "GitHub download failed. Nothing was changed."
unzip -q "$TMP/repo.zip" -d "$TMP"
SRC=$(find "$TMP" -mindepth 1 -maxdepth 1 -type d -name 'perfume-*' | head -1)
[ -d "$SRC/wp-content/plugins/perfumarathi-shop" ] || die "Plugin not found in the GitHub download. Nothing was changed."
cp -R "$SRC/wp-content/plugins/perfumarathi-shop" "$TMP/wp/wp-content/plugins/"
mkdir -p "$TMP/wp/wp-content/mu-plugins"
cp "$SRC/server/mu-plugins/pm-staging-guard.php" "$TMP/wp/wp-content/mu-plugins/"
say "Copying files into $DOCROOT"
cp -R "$TMP/wp/." "$DOCROOT/"
# Removes only the files this script copied in (by name, from the temp copy); host files stay.
undo_copy() {
  ( cd "$TMP/wp" && for f in * .[!.]*; do if [ -e "$f" ]; then rm -rf "${DOCROOT:?}/$f"; fi; done ) || true
  rm -f "$DOCROOT/wp-config.php"
}
say "Writing wp-config.php (password read from stdin, not the command line)"
printf '%s' "$DB_PASS" | wp config create --dbname="$DB_NAME" --dbuser="$DB_USER" --dbhost=localhost --dbprefix="$PREFIX" --prompt=dbpass --quiet \
  || { undo_copy; die "Could not connect to $DB_NAME with $DB_USER. Check the password and that the user is added to the database (cPanel > MySQL Databases)."; }
unset DB_PASS
chmod 600 "$DOCROOT/wp-config.php"

# Two independent checks that the database is empty (never write into an existing site's database).
tables=$(wp db tables --all-tables --format=csv 2>/dev/null || true)
if [ -z "$tables" ] && wp core is-installed >/dev/null 2>&1; then tables="(WordPress already installed)"; fi
if [ -n "$tables" ]; then
  undo_copy
  die "Database $DB_NAME already has tables ($tables). Not overwriting. Use an empty database."
fi

wp config set WP_ENVIRONMENT_TYPE staging --quiet
wp config set PM_STAGING_HOST "$DOMAIN" --quiet
wp config set PM_STAGING_MAIL_TO "$STAGING_MAIL" --quiet
wp config set DISALLOW_FILE_EDIT true --raw --quiet
wp config set WP_DEBUG true --raw --quiet
wp config set WP_DEBUG_LOG true --raw --quiet
wp config set WP_DEBUG_DISPLAY false --raw --quiet

ADMIN_PASS=$(openssl rand -base64 18 | tr -d '/+=' | cut -c1-20)
GATE_KEY=$(openssl rand -hex 12)
say "Installing WordPress"
wp core install --url="https://$DOMAIN" --title="Perfumarathi STAGING" --admin_user=pmadmin \
  --admin_password="$ADMIN_PASS" --admin_email="$STAGING_MAIL" --skip-email --quiet
wp option update blog_public 0 --quiet
wp option update pm_staging_gate_key "$GATE_KEY" --quiet
wp rewrite structure '/%postname%/' --quiet
if [ ! -f "$DOCROOT/.htaccess" ] || ! grep -q 'BEGIN WordPress' "$DOCROOT/.htaccess"; then
  cat >> "$DOCROOT/.htaccess" <<'HT'
# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
# END WordPress
HT
fi

# ---------- Plugins from GitHub ----------
say "Activating WooCommerce and perfumarathi-shop"
wp plugin activate woocommerce --quiet
wp plugin activate perfumarathi-shop --quiet

# ---------- Store settings (staging) ----------
say "Configuring the store"
wp action-scheduler run --batches=5 >/dev/null 2>&1 || true
wp option update woocommerce_coming_soon no --quiet
wp option update woocommerce_currency AED --quiet
wp option update woocommerce_default_country AE:DU --quiet
wp option update woocommerce_enable_guest_checkout yes --quiet
wp option update woocommerce_enable_myaccount_registration yes --quiet
wp option update woocommerce_email_from_address "wordpress@$DOMAIN" --quiet
wp option update woocommerce_email_from_name "Perfumarathi STAGING" --quiet
wp option update woocommerce_cod_settings '{"enabled":"yes","title":"Cash on delivery"}' --format=json --quiet
wp post update "$(wp option get woocommerce_checkout_page_id)" --post_content="[woocommerce_checkout]" --quiet
wp post update "$(wp option get woocommerce_cart_page_id)" --post_content="[woocommerce_cart]" --quiet
wp post update "$(wp option get woocommerce_shop_page_id)" --post_content="[pm_shop]" --quiet
mkpage() { wp post create --post_type=page --post_status=publish --post_name="$1" --post_title="$2" --post_content="$3" --porcelain; }
mkpage shop-perfumarathi "Shop Perfumarathi" '[pm_shop brand="perfumarathi"]' >/dev/null
mkpage shop-fixnote "Shop Fixnote" '[pm_shop brand="fixnote"]' >/dev/null
wp option update pm_tracking_page_id "$(mkpage track-order 'Track Order' '[pm_order_tracking]')" --quiet
wp option update pm_wishlist_page_id "$(mkpage wishlist 'Wishlist' '[pm_wishlist]')" --quiet
home_id=$(mkpage staging-home 'Home' '[pm_cart_icon] [pm_home_shop]')
wp option update show_on_front page --quiet && wp option update page_on_front "$home_id" --quiet
wp eval-file "$SRC/dev/seed-staging.php"
wp rewrite flush --quiet

# ---------- Done ----------
cat <<EOF

=====================================================================
 STAGING READY: https://$DOMAIN        (perfumarathi.com was NOT touched)

 Staging key (visitors):  $GATE_KEY
 Admin:  https://$DOMAIN/wp-admin   user: pmadmin   password: $ADMIN_PASS
 All emails go to: $STAGING_MAIL  (subject starts with [STAGING to ...])

 Save the key and password in your password manager now; they are not stored anywhere else.
 Do not paste them into chat. Then change the database password in cPanel > MySQL Databases,
 and update it with:  wp --path="$DOCROOT" config set DB_PASSWORD '<new password>'
=====================================================================
EOF
