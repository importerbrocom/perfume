#!/usr/bin/env bash
# Replaces the secrets of the STAGING site perfume.nokkoo.in (never touches perfumarathi.com):
#   1. staging key (visitor gate)    2. pmadmin password    3. database password in wp-config.php (optional)
# Run in cPanel > Terminal:  bash ~/rotate-staging-secrets.sh
# New key/password are shown ONCE in this terminal. Nothing is printed for the database password.
set -euo pipefail
DOCROOT=${DOCROOT:-$HOME/perfume.nokkoo.in}
WPCLI="$HOME/.wp-cli/pm-staging-wp-cli.phar"
PHP_BIN=${PHP_BIN:-php}
die() { echo "STOPPED: $*" >&2; exit 1; }

case "$(cd "$DOCROOT" 2>/dev/null && pwd -P)" in
  *perfumarathi.com*|"") die "$DOCROOT is missing or is the live site." ;;
esac
[ -f "$DOCROOT/wp-config.php" ] || die "No wp-config.php in $DOCROOT."
grep -q "PM_STAGING_HOST" "$DOCROOT/wp-config.php" || die "$DOCROOT is not the staging site (no PM_STAGING_HOST). Not changing anything."
[ -f "$WPCLI" ] || die "WP-CLI copy missing ($WPCLI). Re-run the installer download step."
wp() { "$PHP_BIN" -d memory_limit=256M "$WPCLI" --path="$DOCROOT" "$@"; }

# ---------- 3. Database password (first, so WP-CLI keeps working) ----------
read -rp "Did you change the database password in cPanel? [y/N] " ans
if [[ "$ans" =~ ^[Yy] ]]; then
  read -rsp "New database password (hidden): " NEWP; echo
  [ -n "$NEWP" ] || die "Empty password."
  DBU=$(grep -oE "define\( *'DB_USER', *'[^']+'" "$DOCROOT/wp-config.php" | sed -E "s/.*'([^']+)'$/\1/")
  DBN=$(grep -oE "define\( *'DB_NAME', *'[^']+'" "$DOCROOT/wp-config.php" | sed -E "s/.*'([^']+)'$/\1/")
  PM_DB_PASS="$NEWP" PM_DB_USER="$DBU" PM_DB_NAME="$DBN" "$PHP_BIN" -r \
    'mysqli_report(MYSQLI_REPORT_OFF); exit(@mysqli_connect("localhost", getenv("PM_DB_USER"), getenv("PM_DB_PASS"), getenv("PM_DB_NAME")) ? 0 : 1);' \
    || die "That password does not work for $DBU. wp-config.php not changed."
  cp -p "$DOCROOT/wp-config.php" "$DOCROOT/wp-config.php.bak"
  PM_DB_PASS="$NEWP" "$PHP_BIN" -- "$DOCROOT/wp-config.php" <<'PHP' || { mv "$DOCROOT/wp-config.php.bak" "$DOCROOT/wp-config.php"; die "Could not update wp-config.php (restored)."; }
<?php
$f = $argv[1]; $c = file_get_contents( $f ); $p = getenv( 'PM_DB_PASS' );
$n = 0;
$c = preg_replace_callback( "/define\(\s*'DB_PASSWORD'\s*,\s*'(?:[^'\\\\]|\\\\.)*'\s*\);/",
  function () use ( $p ) { return "define( 'DB_PASSWORD', " . var_export( $p, true ) . ' );'; }, $c, -1, $n );
if ( 1 !== $n || false === file_put_contents( $f, $c ) ) { exit( 1 ); }
PHP
  rm -f "$DOCROOT/wp-config.php.bak"
  unset NEWP
  chmod 600 "$DOCROOT/wp-config.php"
  wp option get siteurl >/dev/null || die "WordPress cannot connect with the new password."
  echo "    Database password updated in wp-config.php."
else
  echo "    Database password: skipped (change it in cPanel first, then run this again)."
fi

# ---------- 1 + 2. Staging key and admin password ----------
KEY=$(openssl rand -hex 12)
ADMIN_PASS=$(openssl rand -base64 18 | tr -d '/+=' | cut -c1-20)
wp option update pm_staging_gate_key "$KEY" --quiet
wp user update pmadmin --user_pass="$ADMIN_PASS" --skip-email --quiet
wp eval 'foreach ( get_users( array( "fields" => "ID" ) ) as $id ) { WP_Session_Tokens::get_instance( $id )->destroy_all(); }' # log everyone out

cat <<EOF

=====================================================================
 New staging key (visitors):  $KEY
 New admin password (pmadmin): $ADMIN_PASS
 All logged-in sessions were signed out; old staging-key cookies no longer work.
 Save these in your password manager. Do not paste them into chat.
=====================================================================
EOF
