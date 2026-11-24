# Perfumarathi platform (React + Laravel)

Replacement for the WordPress/WooCommerce staging shop on **perfume.nokkoo.in**. perfumarathi.com is not touched.

| Part | Folder | Status |
|---|---|---|
| Laravel 13 API + database (PHP 8.3, MySQL/MariaDB) | `backend/` | **Phase 1: done** |
| React shop (current design) | `frontend/` | Phase 2 |
| React admin panel | `frontend/` | Phase 3 |
| Stripe checkout UI (cards, Apple Pay, Google Pay) + COD | `frontend/` | Phase 4 (backend done) |
| Build on GitHub, install on cPanel, import from WordPress | `server/` | Phase 5 (export/import done) |

## Backend design

- **Products** have one or more **sizes** (`product_variants`): price, sale price, stock, status. Empty price = "price on request" (shown, not orderable).
- **Brands** (Perfumarathi, Fixnote), **categories** and **collections** (`categories.kind`), combo contents / related products, gallery images, reviews (approved by staff).
- **Prices, totals, discounts, shipping and payment fees are computed on the server** (`App\Services\CartPricing`). The browser only sends size ids and quantities.
- **Stock**: reserved when the order is placed, under row locks (`SELECT … FOR UPDATE`), so parallel orders cannot oversell
  (`tests/ci/concurrency.php` proves it on MySQL and MariaDB). Cancelled / returned / unpaid-expired orders give stock back.
  Every change is logged in `stock_movements`.
- **Payments** (`payment_methods`, switched on/off in the admin): Cash on Delivery and Stripe (one PaymentIntent with automatic
  payment methods = cards + Apple Pay + Google Pay). Secret key and webhook secret are **encrypted in the database** and never
  returned by any API. An order is marked paid only by a **signature-verified webhook** or a server-side PaymentIntent lookup
  that checks amount and currency. Unpaid card orders are cancelled after the timeout (default 60 min) by the scheduler.
  Payment statuses: `pending`, `paid`, `failed`, `refunded`, `cod_pending`.
- **Order statuses**: pending → confirmed → processing → shipped → delivered, plus cancelled / returned. Unpaid card orders cannot be shipped;
  COD orders become paid when delivered. Customers track with order number + email.
- **Admin auth**: session cookie + CSRF (Laravel Sanctum, same domain), login rate limit, roles `admin` (everything) and `manager`
  (no settings, payment methods, refunds or staff).
- **Security**: all input validated on the server; Eloquent / bound parameters only; HTML stripped from customer text; image uploads
  re-encoded with GD (strips metadata and payloads) with type/size/dimension checks; rate limits on login, checkout, tracking, reviews;
  Cloudflare-only trusted proxies; security headers; optional private-preview lock (`SHOP_GATE_KEY`).

## API

Public (`/api`): `GET store`, `GET home`, `GET pages/{about|returns|privacy|terms|shipping}`, `GET products`, `GET products/{slug}`,
`POST products/{slug}/reviews`, `POST cart/quote`, `POST checkout`, `POST orders/{number}/pay`, `GET orders/{number}?token=`,
`POST track`, `POST webhooks/stripe`.

Admin (`/api/admin`, staff login): `login`, `logout`, `me`, `me/password`, `dashboard`, `products` (CRUD, restore, duplicate, images),
`inventory` (list, bulk save prices/stock, adjust, movements), `brands`, `categories`, `reviews`, `orders` (list, show, status, tracking,
notes, export CSV, refund*), `customers`, `banners`, `announcements`, `home-sections`, `coupons`, `settings`*, `payment-methods`*, `staff`*.
(* admin role only)

## Commands

```
php artisan migrate --seed                 # tables + defaults (brands, categories, COD on, Stripe off, homepage sections)
php artisan shop:admin you@example.com     # create / reset an admin login (prints a generated password once)
php artisan shop:import-wp /path/export    # import from server/export-wp.php output (existing products are kept)
php artisan schedule:run                   # cron every minute: expires unpaid card orders
php artisan test                           # 38 feature tests
```

## Tests

`.github/workflows/platform.yml`: the full test suite on SQLite, MySQL 8 and MariaDB 10.11, migrations up/down, seeding twice, a
12-way concurrent checkout race for 3 bottles, and Pint. `.github/workflows/staging-script.yml` exports the real WordPress staging
catalogue with `server/export-wp.php` (checks WordPress is unchanged) and imports it into Laravel (15 products, images, notes,
prices, stock, combos, reviews).
