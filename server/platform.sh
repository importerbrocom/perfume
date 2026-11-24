#!/usr/bin/env bash
# Perfumarathi shop (React + Laravel) on cPanel: install, update, roll back, or put WordPress back.
# It only works on https://perfume.nokkoo.in. perfumarathi.com is never touched.
#
# First install (cPanel > Terminal):
#   curl -fsSL https://raw.githubusercontent.com/importerbrocom/perfume/main/server/platform.sh -o ~/platform.sh
#   bash ~/platform.sh install
# Afterwards the script lives in ~/pmshop/platform.sh:
#   bash ~/pmshop/platform.sh update              newest release from GitHub (database backup first)
#   bash ~/pmshop/platform.sh rollback            previous release
#   bash ~/pmshop/platform.sh open | lock         make the shop public / private again (preview key)
#   bash ~/pmshop/platform.sh mail                set up order emails (SMTP)
#   bash ~/pmshop/platform.sh status
#   bash ~/pmshop/platform.sh restore-wordpress   put the WordPress staging site back (shop data is kept)
#   bash ~/pmshop/platform.sh publish             switch perfume.nokkoo.in from WordPress back to the shop
#
# Builds happen on GitHub (Actions > "Platform release"); the server only downloads, checks the
# SHA-256 and unpacks. Passwords are typed hidden, never put on a command line, never printed.
set -euo pipefail
umask 022   # uploaded photos must be readable by the web server; secret files get 600 below

DOMAIN=${DOMAIN:-perfume.nokkoo.in}
DOCROOT=${DOCROOT:-$HOME/$DOMAIN}
APP=${APP:-$HOME/pmshop}
REPO=${REPO:-importerbrocom/perfume}
RELEASE=${RELEASE:-latest}          # or a release tag, e.g. platform-20261124-101500-abcd1234
RELEASE_FILE=${RELEASE_FILE:-}      # a local pmshop.tar.gz (with pmshop.tar.gz.sha256 next to it)
SCHEME=${SCHEME:-https}
HEALTH_URL=${HEALTH_URL:-$SCHEME://$DOMAIN/up}
STATEFUL=${STATEFUL:-$DOMAIN}
LIVE_DOCROOT="$HOME/perfumarathi.com"
PHP_BIN=${PHP_BIN:-php}
PHPX=("$PHP_BIN" -d memory_limit=256M)
KEEP_RELEASES=5
# cPanel / LiteSpeed / SSL files that stay in the web folder whatever is installed there
KEEP_IN_DOCROOT=(.well-known cgi-bin php.ini .user.ini error_log)
ENVF="$APP/shared/.env"
TS=$(date +%Y%m%d-%H%M%S)

die() { echo "STOPPED: $*" >&2; exit 1; }
say() { printf '\n==> %s\n' "$*"; }
ask() { local a; read -rp "$1 [${2:-}]: " a || true; printf '%s' "${a:-${2:-}}"; }
ask_secret() { local a; read -rsp "$1: " a || true; echo >&2; printf '%s' "$a"; }
yes_no() { local a; read -rp "$1 [$2]: " a || true; a=${a:-$2}; [[ "$a" =~ ^[Yy] ]]; }

killed() { # $1 exit code, $2 what
  echo "STOPPED: the server stopped PHP (signal $(( $1 - 128 ))) while running: $2" >&2
  echo "  This is the hosting account's process / memory limit, not a password problem." >&2
  echo "  Please send Kiro the output of  $PHP_BIN -v  and cPanel > Resource Usage." >&2
  exit 1
}
artisan_in() { # $1 = app dir, rest = artisan args
  local dir=$1 rc=0; shift
  (cd "$dir" && "${PHPX[@]}" artisan "$@") || rc=$?
  [ "$rc" -lt 128 ] || killed "$rc" "php artisan $*"
  return "$rc"
}
artisan() { artisan_in "$APP/current" "$@"; }

lock() {
  mkdir -p "$APP"
  mkdir "$APP/.lock" 2>/dev/null || die "Another platform.sh is running (or crashed). If not, remove $APP/.lock and try again."
  TMP=$(mktemp -d "$HOME/.pmshop-XXXXXX")
  trap 'rm -rf "$APP/.lock" "$TMP"' EXIT
}

# ---------- Safety ----------
check_docroot() {
  [ -d "$DOCROOT" ] || die "$DOCROOT does not exist (cPanel > Domains > $DOMAIN)."
  local real; real=$(cd "$DOCROOT" && pwd -P)
  case "$real" in *perfumarathi.com*) die "Web folder resolves to the LIVE site ($real).";; esac
  if [ -d "$LIVE_DOCROOT" ] && [ "$real" = "$(cd "$LIVE_DOCROOT" && pwd -P)" ]; then die "Web folder is the LIVE site."; fi
  [ "$real" != "$(cd "$HOME/public_html" 2>/dev/null && pwd -P || echo none)" ] || die "Web folder is public_html (main domain). Not touching it."
  case "$(cd "$APP" 2>/dev/null && pwd -P || echo "$APP")" in "$real"|"$real"/*) die "The app folder ($APP) must not be inside the web folder.";; esac
}

check_php() {
  "$PHP_BIN" -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);' \
    || die "PHP 8.3 or newer is needed for the command line ($("$PHP_BIN" -r 'echo PHP_VERSION;')). cPanel > MultiPHP Manager, or run with PHP_BIN=/opt/cpanel/ea-php83/root/usr/bin/php"
  local missing
  missing=$("$PHP_BIN" -r 'echo implode(" ", array_filter(["pdo_mysql","mbstring","openssl","tokenizer","xml","ctype","fileinfo","curl","json","dom","filter","session"], fn ($e) => ! extension_loaded($e)));')
  [ -z "$missing" ] || die "PHP extensions missing: $missing (cPanel > Select PHP Version > Extensions)."
  "$PHP_BIN" -r 'exit(extension_loaded("gd") ? 0 : 1);' || echo "    note: PHP 'gd' is off, uploaded photos are stored without resizing."
  command -v tar >/dev/null && command -v curl >/dev/null && command -v sha256sum >/dev/null || die "tar, curl and sha256sum are needed."
}

# ---------- .env (values only ever travel in environment variables) ----------
ENV_PHP=$(cat <<'PHP'
$f = getenv('PM_ENV_FILE'); $n = (int) getenv('PM_N');
$lines = is_file($f) && filesize($f) > 0 ? preg_split('/\r?\n/', rtrim(file_get_contents($f), "\n")) : [];
$q = function (string $v): string {
    if (preg_match('/[\r\n\0]/', $v)) { fwrite(STDERR, "Values cannot contain line breaks.\n"); exit(2); }
    if ($v === '' || preg_match('~^[A-Za-z0-9_.:/@+,-]+$~', $v)) return $v;
    if (strpos($v, "'") === false) return "'" . $v . "'";          // single quotes: taken literally
    return '"' . strtr($v, ['\\' => '\\\\', '"' => '\\"', '$' => '\\$']) . '"';
};
for ($i = 0; $i < $n; $i++) {
    $k = getenv("PM_K_$i"); $line = $k . '=' . $q((string) getenv("PM_V_$i")); $done = false;
    foreach ($lines as $j => $l) { if (strpos($l, $k . '=') === 0) { $lines[$j] = $line; $done = true; } }
    if (! $done) { $lines[] = $line; }
}
$tmp = $f . '.tmp'; file_put_contents($tmp, implode("\n", $lines) . "\n"); chmod($tmp, 0600); rename($tmp, $f);
PHP
)
env_set() { # KEY VALUE [KEY VALUE ...]
  (
    i=0
    while [ $# -gt 1 ]; do export "PM_K_$i=$1" "PM_V_$i=$2"; shift 2; i=$((i + 1)); done
    export PM_N=$i PM_ENV_FILE="$ENVF"
    "$PHP_BIN" -r "$ENV_PHP"
  )
}
env_get() { # reads .env with Laravel's own parser (handles quotes and escapes)
  PM_ENV_DIR="$APP/shared" PM_KEY="$1" "$PHP_BIN" -r 'require getenv("PM_ENV_DIR") . "/../current/vendor/autoload.php"; $d = Dotenv\Dotenv::createArrayBacked(getenv("PM_ENV_DIR"))->load(); echo $d[getenv("PM_KEY")] ?? "";'
}

# ---------- Releases ----------
fetch_release() { # prints the new release folder
  local tgz="$TMP/pmshop.tar.gz"
  if [ -n "$RELEASE_FILE" ]; then
    cp "$RELEASE_FILE" "$tgz"; cp "$RELEASE_FILE.sha256" "$tgz.sha256" || die "Missing $RELEASE_FILE.sha256"
  else
    local base="https://github.com/$REPO/releases/latest/download"
    [ "$RELEASE" = latest ] || base="https://github.com/$REPO/releases/download/$RELEASE"
    curl -fsSL --retry 3 "$base/pmshop.tar.gz" -o "$tgz" || die "Could not download the release ($base). Has the 'Platform release' workflow run on GitHub?"
    curl -fsSL --retry 3 "$base/pmshop.tar.gz.sha256" -o "$tgz.sha256" || die "Could not download the release checksum."
  fi
  (cd "$TMP" && sha256sum -c --status pmshop.tar.gz.sha256) || die "Checksum mismatch: the download is damaged or not from the release. Nothing was changed."
  mkdir -p "$TMP/x" "$APP/releases"
  tar -xzf "$tgz" -C "$TMP/x" || die "Could not unpack the release."
  [ -f "$TMP/x/pmshop/artisan" ] && [ -f "$TMP/x/pmshop/VERSION" ] && [ -f "$TMP/x/pmshop/vendor/autoload.php" ] || die "The release archive is incomplete."
  local v; v=$(tr -cd 'A-Za-z0-9._-' < "$TMP/x/pmshop/VERSION")
  [ -n "$v" ] || die "The release has no version."
  if [ -d "$APP/releases/$v" ]; then
    rm -rf "$TMP/x"; echo "$APP/releases/$v"; return
  fi
  mv "$TMP/x/pmshop" "$APP/releases/$v"
  echo "$APP/releases/$v"
}

link_shared() { # $1 release folder
  rm -rf "$1/storage"; ln -s "$APP/shared/storage" "$1/storage"
  ln -sfn "$ENVF" "$1/.env"
  chmod -R u+rwX "$1/bootstrap/cache"
}

make_shared() {
  local d
  for d in app/public app/private framework/cache/data framework/sessions framework/views logs; do mkdir -p "$APP/shared/storage/$d"; done
  mkdir -p "$APP/shared/backups" && chmod 700 "$APP/shared/backups"
  # The web server may only pass through to storage/app/public (uploaded photos); nothing else is listable.
  chmod 711 "$APP" "$APP/shared" "$APP/shared/storage" "$APP/shared/storage/app"
  chmod 755 "$APP/shared/storage/app/public"
  chmod 700 "$APP/shared/storage/app/private" "$APP/shared/storage/logs" "$APP/shared/storage/framework" 2>/dev/null || true
}

cache_app() { # $1 release folder
  artisan_in "$1" optimize:clear -q >/dev/null 2>&1 || true
  artisan_in "$1" config:cache -q
  artisan_in "$1" route:cache -q
  artisan_in "$1" view:cache -q
  artisan_in "$1" event:cache -q
}

current_version() { [ -L "$APP/current" ] && basename "$(readlink "$APP/current")" || echo none; }
switch_to() { ln -sfn "$1" "$APP/current.new" && mv -T "$APP/current.new" "$APP/current"; }

# ---------- Web folder ----------
docroot_mode() {
  if [ -f "$DOCROOT/index.php" ] && grep -q '__PM_APP_DIR__\|pmshop' "$DOCROOT/index.php" 2>/dev/null; then echo shop
  elif [ -f "$DOCROOT/wp-config.php" ]; then echo wordpress
  elif [ -z "$(docroot_entries)" ]; then echo empty
  else echo other; fi
}
docroot_entries() { # everything except the cPanel / SSL files
  local args=() k
  for k in "${KEEP_IN_DOCROOT[@]}"; do args+=(! -name "$k"); done
  find "$DOCROOT" -mindepth 1 -maxdepth 1 "${args[@]}" -printf '%f\n'
}
cpanel_blocks() { # cPanel-generated .htaccess blocks (PHP version handler, php.ini directives) must survive
  [ -f "$1" ] && awk '/^# (php -- )?BEGIN cPanel-generated/{p=1} p{print} /^# (php -- )?END cPanel-generated/{p=0; print ""}' "$1" || true
}

sync_assets() { # $1 release folder, $2 previous release folder (optional)
  mkdir -p "$DOCROOT/assets"
  cp -Rp "$1/public/assets/." "$DOCROOT/assets/"
  local f
  for f in "$1"/public/*; do
    case "$(basename "$f")" in index.php|assets|storage|.htaccess) ;; *) cp -Rp "$f" "$DOCROOT/";; esac
  done
  # Old hashed files are kept for one release (open browser tabs), older ones removed.
  for f in "$DOCROOT"/assets/*; do
    [ -e "$f" ] || continue
    local n; n=$(basename "$f")
    [ -e "$1/public/assets/$n" ] || { [ -n "${2:-}" ] && [ -e "$2/public/assets/$n" ]; } || rm -f "$f"
  done
}

write_docroot() { # turns the (cleared) web folder into the shop
  local blocks; blocks=$(cpanel_blocks "${1:-}")
  PM_APP_DIR="$APP/current" "$PHP_BIN" -r '$t = file_get_contents($argv[1]); file_put_contents($argv[2], str_replace("__PM_APP_DIR__", addcslashes(getenv("PM_APP_DIR"), "\\\x27"), $t));' \
    "$APP/current/deploy/index.php" "$DOCROOT/index.php"
  { [ -z "$blocks" ] || printf '%s\n\n' "$blocks"; cat "$APP/current/deploy/htaccess"; } > "$DOCROOT/.htaccess"
  chmod 644 "$DOCROOT/index.php" "$DOCROOT/.htaccess"
  ln -sfn "$APP/shared/storage/app/public" "$DOCROOT/storage"
  sync_assets "$APP/current"
}

move_wordpress_out() { # moves WordPress files (not delete) to a backup folder; prints the folder
  local bk="$HOME/pm-wordpress-backup-$TS" e
  mkdir -p "$bk/files"
  while IFS= read -r e; do mv "$DOCROOT/$e" "$bk/files/"; done < <(docroot_entries)
  echo "$bk"
}

health_local() { # runs one request through the web folder's index.php with PHP (no network, no Cloudflare)
  (cd "$DOCROOT" && REQUEST_URI=/up REQUEST_METHOD=GET HTTP_HOST="$DOMAIN" SERVER_NAME="$DOMAIN" SERVER_PORT=443 HTTPS=on \
    SCRIPT_NAME=/index.php SCRIPT_FILENAME="$DOCROOT/index.php" "${PHPX[@]}" index.php 2>&1) | grep -q "Application up"
}
health() { # 0 = the app answers. The check over the internet only warns (Cloudflare may challenge the server itself).
  health_local || { echo "    The app did not start (PHP check of $DOCROOT/index.php failed)." >&2; return 1; }
  local i code=""
  for i in 1 2 3 4; do
    code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$HEALTH_URL" || true)
    [ "$code" = 200 ] && return 0
    sleep 3
  done
  echo "    note: $HEALTH_URL answered HTTP ${code:-none} from this server (Cloudflare may block requests from the server itself). Open it in your browser to check." >&2
  return 0
}

install_cron() {
  local php_abs line
  php_abs=$(command -v "$PHP_BIN")
  line="* * * * * cd $APP/current && $php_abs artisan schedule:run >/dev/null 2>&1 # pmshop-scheduler"
  if command -v crontab >/dev/null 2>&1 && { (crontab -l 2>/dev/null | grep -v 'pmshop-scheduler'; echo "$line") | crontab - ; } 2>/dev/null; then
    echo "    cron job added (every minute: releases stock of unpaid card orders)"
  else
    echo "    Add this in cPanel > Cron Jobs (Once Per Minute):"; echo "      ${line% \#*}"
  fi
}
remove_cron() { command -v crontab >/dev/null 2>&1 && (crontab -l 2>/dev/null | grep -v 'pmshop-scheduler' | crontab -) 2>/dev/null || true; }

wpcli() { # our own checksum-verified WP-CLI (the server's `wp` gets killed on this host)
  local p="$HOME/.wp-cli/pm-staging-wp-cli.phar"
  if [ ! -f "$p" ]; then
    mkdir -p "$HOME/.wp-cli"
    curl -fsSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o "$p.tmp" || die "WP-CLI download failed."
    local want have
    want=$(curl -fsSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar.sha512 | tr -d '[:space:]')
    have=$(sha512sum "$p.tmp" | cut -d' ' -f1)
    [ -n "$want" ] && [ "$want" = "$have" ] || { rm -f "$p.tmp"; die "WP-CLI checksum mismatch."; }
    mv "$p.tmp" "$p"
  fi
  local rc=0
  "$PHP_BIN" -d memory_limit=256M "$p" --path="$DOCROOT" "$@" || rc=$?
  [ "$rc" -lt 128 ] || killed "$rc" "wp $*"
  return "$rc"
}

db_backup() { # mysqldump with the shop's credentials from .env (password via a private options file)
  command -v mysqldump >/dev/null 2>&1 || { echo "    note: mysqldump not found, database backup skipped (cPanel > Backup can make one)."; return 0; }
  local cnf="$APP/shared/.my.cnf.tmp" out="$APP/shared/backups/db-$TS.sql.gz"
  ( umask 077
    PM_U=$(env_get DB_USERNAME) PM_P=$(env_get DB_PASSWORD) "$PHP_BIN" -r 'printf("[client]\nuser=%s\npassword=\"%s\"\n", getenv("PM_U"), addcslashes(getenv("PM_P"), "\\\""));' > "$cnf" )
  local rc=0
  mysqldump --defaults-extra-file="$cnf" --single-transaction --quick --no-tablespaces "$(env_get DB_DATABASE)" 2>/dev/null | gzip > "$out" || rc=$?
  rm -f "$cnf"
  if [ "$rc" -ne 0 ] || [ ! -s "$out" ]; then rm -f "$out"; echo "    note: database backup failed; continuing (cPanel > Backup can make one)."; return 0; fi
  ls -1t "$APP"/shared/backups/db-*.sql.gz 2>/dev/null | tail -n +11 | xargs -r rm -f
  echo "    database backup: $out"
}

copy_self() { # keep the newest platform.sh next to the app (atomic: a running copy is never overwritten in place)
  local src=${1:-}
  [ -n "$src" ] && [ -f "$src" ] || return 0
  cp "$src" "$APP/platform.sh.new" && chmod 700 "$APP/platform.sh.new" && mv -f "$APP/platform.sh.new" "$APP/platform.sh"
}

# =====================================================================================
cmd_install() {
  say "Safety checks"
  check_docroot
  [ ! -e "$APP/current" ] || die "The shop is already installed in $APP. Use:  bash $APP/platform.sh update"
  local mode; mode=$(docroot_mode)
  case "$mode" in
    wordpress)
      grep -q "PM_STAGING_HOST" "$DOCROOT/wp-config.php" || die "$DOCROOT has a WordPress site that is not the Perfumarathi staging site. Not touching it."
      echo "    $DOMAIN has the WordPress staging site: its products are copied, then its files are moved to a backup (not deleted).";;
    empty) echo "    $DOMAIN web folder is empty.";;
    shop) die "The web folder already runs the shop.";;
    *) die "$DOCROOT has other files:
$(docroot_entries | head -5)
Not overwriting anything.";;
  esac
  check_php
  echo "    PHP $("$PHP_BIN" -r 'echo PHP_VERSION;') OK"
  lock

  say "Downloading the shop release from GitHub"
  local rel; rel=$(fetch_release)
  echo "    version $(basename "$rel")"

  say "Database"
  local db_name db_user db_pass wp_db=""
  db_name=$(ask "Database name" "${DB_NAME:-uddjzwrz_perfumarathi1}")
  db_user=$(ask "Database user" "${DB_USER:-$db_name}")
  db_pass=$(ask_secret "Database password for $db_user (hidden)")
  [ -n "$db_pass" ] || die "No password entered."
  if [ "$mode" = wordpress ]; then
    wp_db=$("$PHP_BIN" -r 'preg_match("/define\(\s*.DB_NAME.\s*,\s*.([^\x27\"]+)./", file_get_contents($argv[1]), $m); echo $m[1] ?? "";' "$DOCROOT/wp-config.php")
    [ "$wp_db" != "$db_name" ] || die "$db_name is the WordPress staging database. Use the new empty database (uddjzwrz_perfumarathi1)."
  fi
  # Tries the database socket (localhost) and then TCP (127.0.0.1); prints the one that works.
  # PDO error messages name the user and host but never contain the password.
  local rc=0 db_host
  db_host=$(PM_N="$db_name" PM_U="$db_user" PM_P="$db_pass" "$PHP_BIN" -r '
    foreach (["localhost", "127.0.0.1"] as $h) {
      try { $pdo = new PDO("mysql:host=$h;dbname=" . getenv("PM_N") . ";charset=utf8mb4", getenv("PM_U"), getenv("PM_P"), [PDO::ATTR_TIMEOUT => 10]); }
      catch (Throwable $e) { fwrite(STDERR, "    $h: " . $e->getMessage() . "\n"); continue; }
      if (count($pdo->query("SHOW TABLES")->fetchAll()) > 0) exit(4);
      echo $h; exit(0);
    }
    exit(3);') || rc=$?
  [ "$rc" -lt 128 ] || killed "$rc" "php (database check)"
  if [ "$rc" -eq 3 ]; then
    die "Could not log in to $db_name as $db_user (the reason is shown above).
  'Access denied': the password is wrong, or the user is not added to the database
     (cPanel > MySQL Databases > Add User To Database > ALL PRIVILEGES). You can set a new password there.
  'Unknown database': check the database name in cPanel > MySQL Databases.
  'No such file' / 'Connection refused': send Kiro the lines above.
Nothing was changed; run the install again."
  fi
  [ "$rc" -ne 4 ] || die "Database $db_name is not empty. The installer only uses an empty database."
  [ "$rc" -eq 0 ] && [ -n "$db_host" ] || die "Database check failed (exit $rc)."
  echo "    connected to $db_name on $db_host (empty)"

  # Export first: if WordPress cannot be read, nothing has been written yet.
  local exp=""
  if [ "$mode" = wordpress ]; then
    say "Reading products, photos and reviews from the WordPress staging site (read-only)"
    exp="$TMP/wp-export"
    PM_EXPORT_DIR="$exp" wpcli eval-file "$rel/deploy/export-wp.php" || die "Could not read the WordPress shop. Nothing was changed; WordPress is still online."
  fi

  local admin_email; admin_email=$(ask "Your email for the shop admin login" "${ADMIN_EMAIL:-}")
  [[ "$admin_email" == *@*.* ]] || die "That does not look like an email address."
  local smtp=n smtp_host="" smtp_port="" smtp_user="" smtp_pass="" smtp_from=""
  if yes_no "Set up order emails now (needs an email account's SMTP details)? y/n" n; then
    smtp=y
    smtp_host=$(ask "SMTP server" "mail.${DOMAIN#*.}")
    smtp_port=$(ask "SMTP port (465 = SSL, 587 = TLS)" 465)
    smtp_user=$(ask "SMTP user (full email address)" "shop@${DOMAIN#*.}")
    smtp_pass=$(ask_secret "SMTP password for $smtp_user (hidden)")
    smtp_from=$(ask "Send emails from" "$smtp_user")
  fi

  say "Configuring the app"
  make_shared
  : > "$ENVF"; chmod 600 "$ENVF"
  local gate; gate=$(openssl rand -hex 12 2>/dev/null || "$PHP_BIN" -r 'echo bin2hex(random_bytes(12));')
  env_set APP_NAME Perfumarathi APP_ENV production APP_DEBUG false APP_URL "$SCHEME://$DOMAIN" APP_KEY "" \
    APP_LOCALE en APP_FALLBACK_LOCALE en APP_MAINTENANCE_DRIVER file BCRYPT_ROUNDS 12 \
    LOG_CHANNEL daily LOG_LEVEL warning LOG_DAILY_DAYS 14 \
    DB_CONNECTION mysql DB_HOST "$db_host" DB_PORT 3306 DB_DATABASE "$db_name" DB_USERNAME "$db_user" DB_PASSWORD "$db_pass" \
    SESSION_DRIVER database SESSION_LIFETIME 480 SESSION_ENCRYPT false SESSION_PATH / SESSION_SECURE_COOKIE "$([ "$SCHEME" = https ] && echo true || echo false)" SESSION_SAME_SITE lax \
    SANCTUM_STATEFUL_DOMAINS "$STATEFUL" CACHE_STORE file QUEUE_CONNECTION sync FILESYSTEM_DISK local BROADCAST_CONNECTION log \
    SHOP_GATE_KEY "$gate" PAYMENTS_ALLOW_LIVE false \
    MAIL_MAILER log MAIL_FROM_ADDRESS "shop@${DOMAIN#*.}" MAIL_FROM_NAME Perfumarathi
  if [ "$smtp" = y ]; then
    env_set MAIL_MAILER smtp MAIL_SCHEME "$([ "$smtp_port" = 465 ] && echo smtps || echo smtp)" MAIL_HOST "$smtp_host" MAIL_PORT "$smtp_port" \
      MAIL_USERNAME "$smtp_user" MAIL_PASSWORD "$smtp_pass" MAIL_FROM_ADDRESS "$smtp_from"
  fi
  unset db_pass smtp_pass
  link_shared "$rel"
  artisan_in "$rel" key:generate --force -q
  switch_to "$rel"

  say "Creating the shop tables"
  artisan migrate --force
  artisan db:seed --force -q

  if [ -n "$exp" ]; then
    say "Importing products, photos and reviews from WordPress"
    artisan shop:import-wp "$exp"
  fi
  say "Copying the homepage video and logos into the shop"
  [ -n "${SKIP_BRAND_MEDIA:-}" ] && echo "    skipped" || artisan shop:fetch-brand-media || echo "    (some media could not be copied; the homepage keeps loading those from perfumarathi.com)"

  say "Creating your admin login"
  local admin_out; admin_out=$(artisan shop:admin "$admin_email" --name="Store Admin" --role=admin)

  cache_app "$APP/current"

  local bk=""
  if [ "$mode" = wordpress ]; then
    say "Backing up WordPress (files are moved, not deleted; its database is not changed)"
    bk="$HOME/pm-wordpress-backup-$TS"; mkdir -p "$bk"
    wpcli db export "$bk/wordpress-db.sql" --quiet >/dev/null 2>&1 && gzip "$bk/wordpress-db.sql" && echo "    database copy: $bk/wordpress-db.sql.gz" \
      || { rm -f "$bk/wordpress-db.sql"; echo "    note: WordPress database copy skipped (its database stays as it is)."; }
    cp -p "$DOCROOT/.htaccess" "$bk/htaccess-before" 2>/dev/null || true
    bk=$(move_wordpress_out)
  fi

  say "Switching $DOMAIN to the shop"
  write_docroot "${bk:+$bk/files/.htaccess}"
  if ! health; then
    echo "    The shop did not answer at $HEALTH_URL." >&2
    if [ "$mode" = wordpress ] && yes_no "Put the WordPress site back now? y/n" y; then
      restore_wordpress_from "$bk"; die "WordPress is back. The shop stays installed in $APP; send Kiro the last lines of $APP/shared/storage/logs/."
    fi
    echo "    Check $APP/shared/storage/logs/ and send Kiro the last lines." >&2
  fi
  install_cron
  copy_self "${BASH_SOURCE[0]}"

  cat <<EOF

=====================================================================
 SHOP INSTALLED: $SCHEME://$DOMAIN   (perfumarathi.com was NOT touched)
 Version $(current_version)

 Preview key (visitors need it until you open the shop):  $gate
 Admin:  $SCHEME://$DOMAIN/admin
$(printf '%s\n' "$admin_out" | sed 's/^/ /')
${bk:+ WordPress backup: $bk   (put it back: bash $APP/platform.sh restore-wordpress)}

 Save the key and password in your password manager now. Do not paste them into chat.
 Next: Admin > Payment methods (Stripe test keys), Admin > Store settings, then
       bash $APP/platform.sh open     when you are ready for real visitors.
=====================================================================
EOF
}

cmd_update() {
  [ -L "$APP/current" ] || die "The shop is not installed. Run:  bash platform.sh install"
  check_docroot; check_php; lock
  local old; old=$(readlink -f "$APP/current")
  say "Downloading the newest release"
  local rel; rel=$(fetch_release)
  if [ "$rel" = "$old" ] && [ -z "${FORCE:-}" ]; then echo "    Already on the newest version ($(basename "$rel"))."; copy_self "$rel/deploy/platform.sh"; return 0; fi
  echo "    $(basename "$old")  ->  $(basename "$rel")"
  link_shared "$rel"
  say "Backing up the database"
  db_backup
  say "Updating"
  artisan_in "$old" down --retry=20 -q || true
  if ! artisan_in "$rel" migrate --force; then
    artisan_in "$old" up -q || true
    die "Database update failed; the shop keeps running the old version ($(basename "$old")). Send Kiro the message above."
  fi
  cache_app "$rel"
  switch_to "$rel"
  sync_assets "$rel" "$old"
  artisan up -q || true
  if ! health; then
    echo "    The new version did not answer; going back to $(basename "$old")." >&2
    switch_to "$old"; sync_assets "$old" "$rel"; cache_app "$old"
    die "Rolled back to $(basename "$old"). Database changes (if any) were kept; backup in $APP/shared/backups/."
  fi
  install_cron >/dev/null
  # Keep the newest releases (never the active or the previous one)
  ls -1d "$APP"/releases/*/ 2>/dev/null | sed 's:/$::' | sort | head -n -"$KEEP_RELEASES" | while read -r r; do
    [ "$r" = "$rel" ] || [ "$r" = "$old" ] || rm -rf "$r"
  done
  copy_self "$rel/deploy/platform.sh"
  echo; echo "Updated to $(basename "$rel"). Previous version kept: bash $APP/platform.sh rollback"
}

cmd_rollback() {
  [ -L "$APP/current" ] || die "The shop is not installed."
  check_docroot; lock
  local cur prev; cur=$(readlink -f "$APP/current")
  prev=$(ls -1d "$APP"/releases/*/ | sed 's:/$::' | sort | awk -v c="$cur" '$0 == c { print p; exit } { p = $0 }')
  [ -n "$prev" ] && [ -d "$prev" ] || die "There is no older release to go back to."
  echo "    $(basename "$cur")  ->  $(basename "$prev")"
  yes_no "Go back? Database changes made by the newer version are not undone. y/n" y || die "Cancelled."
  link_shared "$prev"; cache_app "$prev"; switch_to "$prev"; sync_assets "$prev" "$cur"
  health || echo "    warning: $HEALTH_URL did not answer 200." >&2
  echo "Now running $(basename "$prev"). Database backups: $APP/shared/backups/"
}

restore_wordpress_from() { # $1 backup folder
  local bk=$1 off="$APP/shared/docroot-shop-$TS" e
  [ -f "$bk/files/wp-config.php" ] || die "No WordPress files in $bk/files."
  mkdir -p "$off"
  while IFS= read -r e; do mv "$DOCROOT/$e" "$off/"; done < <(docroot_entries)
  while IFS= read -r e; do
    [ ! -e "$DOCROOT/$e" ] || { echo "    kept existing $e (WordPress copy stays in $bk/files)"; continue; }
    mv "$bk/files/$e" "$DOCROOT/"
  done < <(find "$bk/files" -mindepth 1 -maxdepth 1 -printf '%f\n')
  rmdir "$bk/files" 2>/dev/null || true
  remove_cron
}

cmd_restore_wordpress() {
  check_docroot; lock
  [ "$(docroot_mode)" = shop ] || die "$DOMAIN is not running the shop right now; nothing to switch."
  local bk; bk=$(ls -1d "$HOME"/pm-wordpress-backup-*/ 2>/dev/null | sed 's:/$::' | sort | while read -r d; do [ -f "$d/files/wp-config.php" ] && echo "$d"; done | tail -1)
  [ -n "$bk" ] || die "No WordPress backup with files found in $HOME/pm-wordpress-backup-*."
  yes_no "Put the WordPress site from $bk back on $DOMAIN? The shop and its data stay in $APP. y/n" n || die "Cancelled."
  restore_wordpress_from "$bk"
  echo "WordPress is back on $DOMAIN. To switch to the shop again:  bash $APP/platform.sh publish"
}

cmd_publish() {
  [ -L "$APP/current" ] || die "The shop is not installed."
  check_docroot; lock
  local mode bk=""; mode=$(docroot_mode)
  case "$mode" in
    shop) echo "Already running the shop."; return 0;;
    wordpress) grep -q "PM_STAGING_HOST" "$DOCROOT/wp-config.php" || die "Not the Perfumarathi staging WordPress. Not touching it."
               yes_no "Move WordPress to a backup folder and show the shop on $DOMAIN? y/n" y || die "Cancelled."
               bk=$(move_wordpress_out);;
    empty) ;;
    *) die "$DOCROOT has other files; not overwriting.";;
  esac
  write_docroot "${bk:+$bk/files/.htaccess}"
  health || echo "    warning: $HEALTH_URL did not answer 200." >&2
  install_cron
  echo "The shop is on $DOMAIN.${bk:+ WordPress backup: $bk}"
}

cmd_open() {
  [ -f "$ENVF" ] || die "The shop is not installed."; lock
  env_set SHOP_GATE_KEY ""; cache_app "$APP/current"
  echo "The shop is now PUBLIC on $SCHEME://$DOMAIN (no preview key needed). Make it private again: bash $APP/platform.sh lock"
}
cmd_lock() {
  [ -f "$ENVF" ] || die "The shop is not installed."; lock
  local gate; gate=$(openssl rand -hex 12 2>/dev/null || "$PHP_BIN" -r 'echo bin2hex(random_bytes(12));')
  env_set SHOP_GATE_KEY "$gate"; cache_app "$APP/current"
  echo "The shop is PRIVATE. New preview key (old ones stop working):  $gate"
}
cmd_mail() {
  [ -f "$ENVF" ] || die "The shop is not installed."; lock
  local host port user pass from
  host=$(ask "SMTP server" "$(env_get MAIL_HOST | sed 's/^127.0.0.1$//')"); host=${host:-mail.${DOMAIN#*.}}
  port=$(ask "SMTP port (465 = SSL, 587 = TLS)" 465)
  user=$(ask "SMTP user (full email address)" "$(env_get MAIL_USERNAME | sed 's/^null$//')")
  pass=$(ask_secret "SMTP password for $user (hidden, empty = keep)")
  from=$(ask "Send emails from" "$user")
  env_set MAIL_MAILER smtp MAIL_SCHEME "$([ "$port" = 465 ] && echo smtps || echo smtp)" MAIL_HOST "$host" MAIL_PORT "$port" MAIL_USERNAME "$user" MAIL_FROM_ADDRESS "$from"
  [ -z "$pass" ] || env_set MAIL_PASSWORD "$pass"
  unset pass
  cache_app "$APP/current"
  echo "Email settings saved. Place a test order to check that the confirmation email arrives."
}
cmd_status() {
  echo "Site:      $SCHEME://$DOMAIN  (web folder: $(docroot_mode))"
  echo "Version:   $(current_version)"
  echo "Releases:  $(ls -1 "$APP/releases" 2>/dev/null | tr '\n' ' ')"
  if [ -f "$ENVF" ]; then [ -n "$(env_get SHOP_GATE_KEY)" ] && echo "Access:    private (preview key)" || echo "Access:    PUBLIC"; fi
  (crontab -l 2>/dev/null | grep -q pmshop-scheduler) && echo "Cron:      on" || echo "Cron:      not found (cPanel > Cron Jobs)"
  echo "PHP:       $("$PHP_BIN" -r 'echo PHP_VERSION;')"
  health >/dev/null 2>&1 && echo "Health:    OK" || echo "Health:    $HEALTH_URL is not answering 200"
}

case "${1:-}" in
  install) cmd_install;;
  update) cmd_update;;
  rollback) cmd_rollback;;
  restore-wordpress) cmd_restore_wordpress;;
  publish) cmd_publish;;
  open) cmd_open;;
  lock) cmd_lock;;
  mail) cmd_mail;;
  status) cmd_status;;
  *) sed -n '2,16p' "$0"; exit 1;;
esac
