#!/usr/bin/env bash
# STAGING ONLY (perfume.nokkoo.in). Never touches perfumarathi.com: it only DOWNLOADS three public media files from it.
#   1. Updates perfumarathi-shop + the staging guard to the latest version from GitHub.
#   2. Copies the brand logos and the homepage video into the staging site (wp-content/uploads/pm-brand/).
#   3. Makes the homepage the Perfumarathi & Fixnote brand page (same design as the live site) with the shop added.
# Run in cPanel > Terminal:  bash ~/setup-brand-home.sh
set -euo pipefail
DOCROOT=${DOCROOT:-$HOME/perfume.nokkoo.in}
BRANCH=${BRANCH:-main}
ZIP_URL="https://codeload.github.com/importerbrocom/perfume/zip/refs/heads/$BRANCH"
ASSET_SRC=${PM_ASSET_SRC:-https://perfumarathi.com/wp-content/uploads/2026/10}
WPCLI="$HOME/.wp-cli/pm-staging-wp-cli.phar"
PHP_BIN=${PHP_BIN:-php}
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

TMP=$(mktemp -d "$HOME/.pm-brand-XXXXXX"); trap 'rm -rf "$TMP"' EXIT

say "1/3 Updating perfumarathi-shop from GitHub ($BRANCH)"
curl -fsSL "$ZIP_URL" -o "$TMP/repo.zip" || die "GitHub download failed. Nothing changed."
unzip -q "$TMP/repo.zip" -d "$TMP"
SRC=$(find "$TMP" -mindepth 1 -maxdepth 1 -type d -name 'perfume-*' | head -1)
[ -f "$SRC/wp-content/plugins/perfumarathi-shop/templates/brand-home.html" ] || die "Downloaded plugin has no brand homepage. Nothing changed."
P="$DOCROOT/wp-content/plugins"
rm -rf "$P/perfumarathi-shop.new" && cp -R "$SRC/wp-content/plugins/perfumarathi-shop" "$P/perfumarathi-shop.new"
rm -rf "$P/perfumarathi-shop.old"; [ -d "$P/perfumarathi-shop" ] && mv "$P/perfumarathi-shop" "$P/perfumarathi-shop.old"
mv "$P/perfumarathi-shop.new" "$P/perfumarathi-shop"
cp "$SRC/server/mu-plugins/pm-staging-guard.php" "$DOCROOT/wp-content/mu-plugins/pm-staging-guard.php"
if ! wp eval 'exit( class_exists( "PM_Brand_Home" ) ? 0 : 1 );'; then
  rm -rf "$P/perfumarathi-shop" && mv "$P/perfumarathi-shop.old" "$P/perfumarathi-shop"
  die "New plugin version did not load; previous version restored."
fi
rm -rf "$P/perfumarathi-shop.old"
wp plugin activate perfumarathi-shop --quiet

say "2/3 Copying brand logos and video into the staging site (read-only download)"
U="$DOCROOT/wp-content/uploads/pm-brand"; mkdir -p "$U"
for f in IMG_2610.png:image/png IMG_2609.jpg:image/jpeg IMG_2654.mp4:video/mp4; do
  name=${f%%:*}; want=${f#*:}
  if [ -s "$U/$name" ]; then echo "    $name already present"; continue; fi
  type=$(curl -fsSL -o "$TMP/$name" -w '%{content_type}' "$ASSET_SRC/$name") || die "Could not download $name."
  case "$type" in "$want"*) ;; *) die "$name came back as '$type', expected $want.";; esac
  [ -s "$TMP/$name" ] || die "$name is empty."
  mv "$TMP/$name" "$U/$name"; chmod 644 "$U/$name"
  echo "    $name ($(du -h "$U/$name" | cut -f1))"
done

say "3/3 Setting the brand homepage"
wp eval-file - <<'PHP'
<?php
$page = get_page_by_path( 'staging-home' );
$data = array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Home', 'post_content' => '[pm_brand_home]' );
if ( $page ) { $data['ID'] = $page->ID; $id = wp_update_post( $data, true ); }
else { $data['post_name'] = 'staging-home'; $id = wp_insert_post( $data, true ); }
if ( is_wp_error( $id ) ) { WP_CLI::error( $id->get_error_message() ); }
update_post_meta( $id, '_wp_page_template', PM_Brand_Home::TEMPLATE );
update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $id );
WP_CLI::success( "Homepage is page #$id (Perfumarathi Canvas template)." );
PHP
wp rewrite flush --quiet
wp cache flush >/dev/null 2>&1 || true

cat <<EOF

=====================================================================
 Done: https://perfume.nokkoo.in now shows the Perfumarathi & Fixnote
 brand homepage with the shop sections and bag icon.
 (Enter your staging key on the lock page first.) perfumarathi.com was NOT changed.
=====================================================================
EOF
