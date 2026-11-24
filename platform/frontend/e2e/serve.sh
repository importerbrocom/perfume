#!/usr/bin/env bash
# Starts Laravel with a fresh SQLite database and demo products for the browser tests.
# Expects `npm run build` to have run (the built shop is served by Laravel, as in production).
set -euo pipefail
cd "$(dirname "$0")/../../backend"
export APP_ENV=testing APP_DEBUG=false DB_CONNECTION=sqlite DB_DATABASE="${E2E_DB:-$PWD/storage/e2e.sqlite}"
export SESSION_SECURE_COOKIE=false SANCTUM_STATEFUL_DOMAINS=127.0.0.1:8099 APP_URL=http://127.0.0.1:8099 SHOP_GATE_KEY=
export CACHE_STORE=file QUEUE_CONNECTION=sync MAIL_MAILER=array RATE_LIMIT_SCALE=50
[ -f .env ] || { cp .env.example .env; php artisan key:generate -q; }
rm -f "$DB_DATABASE" && touch "$DB_DATABASE"
php artisan migrate --force -q
php artisan cache:clear -q # rate-limit counters from earlier runs
php artisan db:seed --class=DemoSeeder --force -q
php artisan shop:import-fixnote -q # the 378 Fixnote perfumes (F01...)
# Test-only staff accounts (this throwaway SQLite database only)
php artisan shop:admin admin@example.test --name="Store Admin" --role=admin --password=E2eAdmin12345 -q
php artisan shop:admin manager@example.test --name="Shop Manager" --role=manager --password=E2eManager12345 -q
cd public
exec env PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8099 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php 2>>../storage/logs/e2e-server.log
