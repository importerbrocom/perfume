#!/usr/bin/env bash
# STAGING ONLY (perfume.nokkoo.in). Never changes perfumarathi.com: it only READS the public collection page.
#   1. Updates perfumarathi-shop and installs the Perfumarathi theme (header/footer on every shop page).
#   2. Imports the 12 perfumes + 3 combos from perfumarathi.com/pm-page-new (images, notes, feedback; no prices).
#   3. Turns /shop-perfumarathi/ and /shop-fixnote/ into collection pages.
# Run in cPanel > Terminal:  bash ~/setup-collection.sh        (safe to run again)
set -euo pipefail
DOCROOT=${DOCROOT:-$HOME/perfume.nokkoo.in}
BRANCH=${BRANCH:-main}
ZIP_URL="https://codeload.github.com/importerbrocom/perfume/zip/refs/heads/$BRANCH"
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
wp() { "$PHP_BIN" -d memory_limit=512M "$WPCLI" --path="$DOCROOT" "$@"; }
echo "    staging site: $real"

TMP=$(mktemp -d "$HOME/.pm-coll-XXXXXX"); trap 'rm -rf "$TMP"' EXIT
say "1/3 Updating plugin and theme from GitHub ($BRANCH)"
curl -fsSL "$ZIP_URL" -o "$TMP/repo.zip" || die "GitHub download failed. Nothing changed."
unzip -q "$TMP/repo.zip" -d "$TMP"
SRC=$(find "$TMP" -mindepth 1 -maxdepth 1 -type d -name 'perfume-*' | head -1)
[ -f "$SRC/wp-content/plugins/perfumarathi-shop/includes/class-pm-collection.php" ] && [ -f "$SRC/wp-content/themes/perfumarathi/style.css" ] \
  || die "Download is missing the collection code. Nothing changed."
swap() { # $1 = source dir, $2 = target dir
  rm -rf "$2.new" "$2.old"; cp -R "$1" "$2.new"
  [ -d "$2" ] && mv "$2" "$2.old"; mv "$2.new" "$2"
}
P="$DOCROOT/wp-content/plugins/perfumarathi-shop"; T="$DOCROOT/wp-content/themes/perfumarathi"
swap "$SRC/wp-content/plugins/perfumarathi-shop" "$P"
swap "$SRC/wp-content/themes/perfumarathi" "$T"
cp "$SRC/server/mu-plugins/pm-staging-guard.php" "$DOCROOT/wp-content/mu-plugins/pm-staging-guard.php"
if ! wp eval 'exit( class_exists( "PM_Collection" ) ? 0 : 1 );'; then
  for d in "$P" "$T"; do if [ -d "$d.old" ]; then rm -rf "$d" && mv "$d.old" "$d"; else rm -rf "$d"; fi; done
  die "New version did not load; previous version restored."
fi
rm -rf "$P.old" "$T.old"
wp plugin activate perfumarathi-shop --quiet
wp theme activate perfumarathi --quiet

say "2/3 Importing the Perfumarathi collection (reads perfumarathi.com/pm-page-new, read-only)"
cp "$SRC/server/import-collection.php" "$SRC/server/collection-data.php" "$TMP/"
PM_IMPORT_DIR="$TMP" wp eval-file "$TMP/import-collection.php"

say "3/3 Collection pages"
wp eval-file - <<'PHP'
<?php
foreach ( array( 'perfumarathi' => 'Shop Perfumarathi', 'fixnote' => 'Shop Fixnote' ) as $brand => $title ) {
	$slug = 'shop-' . $brand;
	$page = get_page_by_path( $slug );
	$data = array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => '[pm_collection brand="' . $brand . '"]' );
	if ( $page ) { $data['ID'] = $page->ID; wp_update_post( $data ); } else { $data['post_name'] = $slug; wp_insert_post( $data ); }
	WP_CLI::log( "  /$slug/ is a collection page" );
}
PHP
wp rewrite flush --quiet
wp cache flush >/dev/null 2>&1 || true

cat <<EOF

=====================================================================
 Done. On https://perfume.nokkoo.in (after the staging key):
   /shop-perfumarathi/   collection page (hero, feedback, offers, combos, perfumes)
   each perfume / combo  its own product page with the brand header and footer
 Prices are empty ("Price on request" + WhatsApp). Set prices in
 wp-admin > Products to turn on Add to Cart. perfumarathi.com was NOT changed.
=====================================================================
EOF
