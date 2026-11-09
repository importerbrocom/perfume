# Perfumarathi & Fixnote – E-commerce Implementation Guide

## 1. Phase 1 audit (what exists today)

| Item | Finding (from `H.HTML`) |
|---|---|
| Platform | **WordPress** + **Elementor** (canvas template, page id 1053), theme `skt-fragrance` |
| E-commerce | **WooCommerce already installed**. Also: YayCurrency Pro, a WooCommerce-WhatsApp-Order plugin, Contact Form 7, Yoast SEO v26.9, a Twitter feed plugin |
| Homepage | A custom Elementor HTML widget (`#pf-brand-home`): looping video, two brand halves, social section, footer |
| Brand | Logos (`IMG_2610.png` Perfumarathi, `IMG_2609.jpg` Fixnote), orange `#ec7d28` / `#d96513`, brown `#3e2a21`, cream `#fff8f2`, peach `#ffe7d2`, Arial/Helvetica |
| Existing links | Brand pages `?page_id=990` (Perfumarathi), `?page_id=1074` (Fixnote); Instagram, Facebook, TikTok, YouTube, WhatsApp +971 52 820 9716 |
| Market | UAE / GCC / Kerala / India (affects payment provider choice) |

**Decision:** keep WordPress + WooCommerce. WooCommerce already owns products, variations (sizes), stock,
cart, checkout, coupons, shipping, tax, orders, customers, reviews, reports and the admin dashboard.
Laravel/React would duplicate all of that and create a second database, which the brief forbids.
So there is no Laravel app and no new product/cart/order tables.

The original file is backed up at `backup/H.original.HTML` (identical SHA-256 to the supplied file).

## 2. Architecture

```
WordPress / Elementor / skt-fragrance theme (unchanged)
 └─ WooCommerce (products, variations, cart, checkout, orders, coupons, shipping, tax, reviews, users)
     └─ plugin: perfumarathi-shop  (this repo)
         ├─ pm_brand taxonomy (Perfumarathi, Fixnote) + pm_family taxonomy
         ├─ fragrance notes (top/middle/base) meta box + product page output
         ├─ order statuses + tracking number/carrier + customer emails + timeline
         ├─ REST /wp-json/pm/v1/*  (read-only catalog helpers, guest tracking)
         ├─ Shortcodes + JS/CSS (grid, quick view, wishlist, cart drawer, toasts, skeletons)
         └─ Newsletter subscribers (private CPT)
```

Cart, coupons and totals are handled by the **WooCommerce Store API** (`/wp-json/wc/store/v1/`), so every
calculation is performed and validated server-side. The JS only displays what the server returns.

### Requirement -> where it is handled

| Requirement | Implementation |
|---|---|
| Products, sizes/prices, SKU, stock, images, sale price, featured, enable/disable, import/export | Native WooCommerce (variable products with a "Size" attribute; Products > Import/Export) |
| Product video | Add in description (Elementor/video block) or gallery; native WC has no video field |
| Brands per product | `Products > Brands` (assigned on the product edit screen) |
| 10 categories | Created on plugin activation; admin can add more in `Products > Categories` |
| Fragrance notes / family | Product edit screen "Fragrance Notes" box; `Products > Fragrance Families` |
| Shop grid, search, sort, load-more, quick view, wishlist, skeletons, toasts | `[pm_shop]` shortcode + `pm-shop.js` |
| Cart drawer, qty, remove, coupon, persistent cart | Store API (session/cookie persistent; logged-in carts persist in DB) |
| Shipping, tax, coupons, payment config | WooCommerce > Settings, Marketing > Coupons |
| Checkout, guest checkout, accounts | WooCommerce checkout + My Account (enable guest checkout and registration in WC settings) |
| Order tracking + timeline | `[pm_order_tracking]` page, plus My Account order view |
| Admin dashboard, customers, reports, invoices, refunds | WooCommerce Analytics / Orders / Customers; invoices via a plugin (see Gaps) |
| Roles and permissions | WordPress roles (Administrator, Shop manager); admin login is `/wp-admin`, separate from customers (customers have no admin access) |
| SEO / schema / sitemap | Yoast (already installed) + WooCommerce emits Product JSON-LD; Yoast generates the XML sitemap and canonicals |
| Brand pages | `/brand/perfumarathi/`, `/brand/fixnote/` (taxonomy archives) and `[pm_shop brand="fixnote"]` |

## 3. Installation

1. Back up the site (files + DB). Keep `backup/H.original.HTML`.
2. Copy `wp-content/plugins/perfumarathi-shop/` to the server and activate it (WooCommerce must be active). Activation creates the two brands and ten categories.
3. Settings > Permalinks > Save (refreshes `/brand/...` URLs).
4. Create pages:
   - **Shop** page: `[pm_shop]`; add brand pages with `[pm_shop brand="perfumarathi"]` and `[pm_shop brand="fixnote"]`.
   - **Track Order** page: `[pm_order_tracking]`. Save its ID: `wp option update pm_tracking_page_id <ID>` (used in emails).
5. Homepage: replace the Elementor HTML widget contents with the updated `H.HTML` markup (adds a bag icon and "Shop" buttons, nothing removed), then add an Elementor **Shortcode** widget below it with `[pm_home_shop]`.
6. Add the bag icon to the site navigation (Elementor header: Shortcode widget `[pm_cart_icon]`).
7. WooCommerce settings: enable guest checkout, account registration, coupons, reviews (verified owners recommended), set currency (YayCurrency is present), shipping zones/rates, tax.
8. Optional: `wp option update pm_instagram_shortcode '[instagram-feed]'` (Smash Balloon etc.) for the Instagram gallery.

## 4. Admin setup

- Create the shop-owner login: Users > Add New, role **Shop manager** (day-to-day store) or **Administrator** (full). Do not share the admin account.
- Use strong passwords and enable 2FA (e.g. Wordfence/WP 2FA). Limit login attempts.
- Adding a perfume: Products > Add New > Product type "Variable product" > Attributes: `Size` = `30ml | 50ml | 100ml` (used for variations) > Variations: set regular/sale price, stock, SKU per size > Brand, Categories, Fragrance family, Fragrance notes > images/gallery > Publish.
- Order progress: Orders > open order > change status (Confirmed, Packed, Shipped, Out for Delivery, Delivered) and fill the "Shipment Tracking" box. The customer is emailed automatically.

### Order status mapping

| Requested | WooCommerce status |
|---|---|
| Pending Payment | `pending` |
| Processing | `processing` |
| Confirmed / Packed / Shipped / Out for Delivery | `pm-confirmed` / `pm-packed` / `pm-shipped` / `pm-outfordel` (added by plugin) |
| Delivered | `completed` (relabelled "Delivered") |
| Cancelled / Refunded | `cancelled` / `refunded` |

## 5. Payment gateway configuration

The business is UAE-based (Dubai, +971). Pick one gateway your merchant account is approved for; none is
hard-coded and **no card data touches this site** (hosted/redirect or tokenised fields only).

Options commonly used in the UAE (verify eligibility and fees with each provider): Stripe (UAE), Telr, PayTabs,
Network International (N-Genius), Checkout.com; and Tabby/Tamara for instalments. For India/Kerala customers,
Razorpay requires an Indian entity.

Steps (any provider):
1. Install the provider's official WooCommerce plugin; enter keys **in WooCommerce > Settings > Payments**, or
   define them as constants in `wp-config.php` loaded from environment variables (see `.env.example`). Never commit keys.
2. Use the provider's **test mode** first. Place a successful, a declined and a cancelled payment.
3. Register the provider's webhook URL (shown in the plugin) and paste the **webhook signing secret**. The official plugins verify the signature; do not use a gateway plugin that does not.
4. Enable **Cash on Delivery** (WooCommerce > Settings > Payments) and restrict it by shipping zone if needed.
5. Success / failure / cancel pages are WooCommerce's order-received and pay-for-order endpoints; the gateway plugin returns the customer there and the order stays `pending` until the webhook/verification confirms payment.
6. Switch to live keys only after the checklist in section 8 passes. Refunds: Order > Refund (works if the gateway plugin supports refunds).

## 6. REST API

Base: `https://perfumarathi.com/wp-json/`

### `GET pm/v1/products`
Query: `brand` (slug), `category` (slug), `collection` (`featured|on_sale|best_selling`), `search`, `orderby` (`latest|popularity|price_asc|price_desc|rating`), `page`, `per_page` (max 48), `ids` (comma list).
Returns `{items:[{id,name,url,brand,brand_slug,image,price_html,on_sale,rating,reviews,in_stock,stock_label,type,purchasable,simple}], total, pages}`. Only published, catalog-visible products. Cached 60s.

### `GET pm/v1/products/{id}`
Card fields plus `excerpt`, `notes{top,middle,base}`, `gallery[]`, `sizes[{variation_id,label,price_html,in_stock}]`. 404 if hidden/unpublished.

### `POST pm/v1/track`
Body `{order, email}`. Requires the order number **and** billing email; identical error for either mismatch; limit 10 attempts / 10 min / IP (429). Returns `{order,status,html}`.

### `GET|POST pm/v1/wishlist` (logged-in customers only; `X-WP-Nonce` header)
`GET` returns `{ids:[...]}`; `POST {ids:[...]}` replaces the list (max 200, published products only) and returns the saved list. Anonymous requests get 401.

### WooCommerce Store API (cart / checkout) – used by the drawer
`GET cart`, `POST cart/add-item {id,quantity,variation}`, `POST cart/update-item {key,quantity}`, `POST cart/remove-item {key}`,
`POST cart/apply-coupon {code}`, `POST cart/remove-coupon {code}`, `POST checkout`. Mutations need the `Nonce` header returned by earlier responses (handled in JS).
See the WooCommerce Store API documentation for full schemas.

## 7. Database

No new tables for products, carts, orders, customers, coupons, shipping, inventory or reviews: WooCommerce owns them
(HPOS-compatible; the plugin declares compatibility and uses CRUD APIs). Plugin additions are limited to:
two taxonomies (`pm_brand`, `pm_family`), post meta `_pm_notes_top|middle|base`, order meta
`_pm_carrier|_pm_tracking_number|_pm_tracking_url`, a private post type `pm_subscriber`, and two options.
"Migrations" are therefore the activation routine in `PM_Catalog::activate()` (idempotent).

**Backups:** daily DB dump (`mysqldump --single-transaction`) + weekly `wp-content/uploads` copy to off-server storage, retained 30 days,
with a monthly restore test (UpdraftPlus or host snapshots both work). Always back up before updating plugins.

## 8. Security notes

Implemented in the plugin: capability + nonce checks on all admin saves; output escaping (PHP `esc_*`, JS `esc()`); no direct SQL
(WP_Query/WC CRUD only); input sanitisation on all REST args; rate-limited tracking and newsletter; honeypot + nonce on newsletter;
public catalog endpoints return only published/visible products.
Provided by WordPress/WooCommerce: password hashing (phpass/bcrypt), roles/capabilities, CSRF nonces, Store API nonce, upload validation.
You must configure: HTTPS + HSTS, 2FA for staff, login-attempt limiting/WAF, `DISALLOW_FILE_EDIT`, keep core/plugins updated, secrets only in environment/`wp-config.php`.

## 9. Remaining gaps / things to configure

Built in this repo since the first version: account-synced wishlist + `[pm_wishlist]` page (My Account menu link), frequently bought
together and product video fields (Product > Linked Products), printable/PDF-saveable invoices (admin order screen and customer "Invoice"
button), CSV order report, Store Dashboard (WooCommerce > Store Dashboard: sales, charts, best sellers, low stock, recent orders),
WhatsApp "Notify" button, brand in Product schema, customer lockout from wp-admin, Playwright E2E tests.

Still your responsibility / not built:
- **Payment gateway**: choose and install one (`docs/PAYMENTS.md`). Nothing can take card payments until you do.
- **Gallery zoom/lightbox, size-selector look, cart and checkout page styling**: provided by WooCommerce + the `skt-fragrance` theme; review on staging.
- **Homepage banners, website content, testimonials moderation, review moderation, coupons, shipping, tax**: managed with Elementor and native WooCommerce screens (no code edits needed).
- **Automatic WhatsApp/SMS sending**: the button opens a prefilled WhatsApp chat; true automation needs the WhatsApp Business API provider.
- **Dashboard totals** cover the last 12 months and are cached 5 minutes; "Customers" counts the `customer` role.
- Stock and best-seller figures count simple/parent products; variation-level low stock is visible in WooCommerce > Products.
- Admin roles: use WordPress roles (Administrator, Shop manager). Finer-grained custom roles need a role-editor plugin.

## 10. Testing report

Automated runs on GitHub Actions against a real WordPress (PHP 8.2, MySQL 8.0) + **WooCommerce 11.2.2** in Docker
(`.github/workflows/e2e.yml`), with the plugin activated and two `[TEST]` variable products seeded.

| Check | Result |
|---|---|
| CI: PHP lint (real `php -l`), JS/shell syntax, backup untouched, no secrets | Pass |
| Plugin activation on WP + WooCommerce 11.2.2 | Pass (no fatal) |
| Playwright, desktop (Chrome) + mobile (Pixel 7) + rate-limit project | **21 / 21 pass** |
| PHP errors/warnings from the plugin in the server log | None |

Covered by the suite: brand shop listing and search + empty state; Perfumarathi/Fixnote separation; product page size selection and
add to cart; live bag counter; cart drawer quantity and remove; invalid coupon rejected by the server; **guest checkout with Cash on
Delivery creates an order**; tracking with wrong email rejected and correct email showing the timeline; tracking rate limit (429);
anonymous access to `wc/v3/orders`, `pm/v1/wishlist` and the admin dashboard denied; registered customer redirected away from wp-admin;
empty wishlist state; reduced motion disables transitions.

Bugs the runs found and fixed: bag counter missing outside the homepage (added a sitewide floating bag); staging used the block
checkout (switched to classic shortcodes to match the live classic theme); setup readiness check; several test-locator issues.

**Still not tested** (manual, before go-live):
- Card payments: no gateway chosen yet (`docs/PAYMENTS.md` matrix, incl. failure, cancel and webhook signature).
- Admin flows by hand: create product with images, change order statuses and receive emails (staging has no mail server), tracking number, invoice print, CSV export, Store Dashboard figures, valid coupon discounts, shipping/tax rules.
- The real `skt-fragrance` theme, Elementor homepage and existing plugins (YayCurrency, WhatsApp order): staging uses the default WordPress theme. Test on a clone of the live site.
- Block-based checkout (if the live site uses it instead of the classic shortcode).

## 11. Local staging (Docker)

```
cd dev && cp .env.dev.example .env      # edit the passwords
docker compose up -d db wordpress
./setup.sh                              # installs WP + WooCommerce, activates plugin, creates pages
docker compose run --rm -T wpcli eval-file /seed-staging.php   # optional [TEST] products (staging only)
```
Site: http://localhost:8080, admin: `/wp-admin` (user `admin`, password from `.env`). The seed script refuses to run
when `WP_ENVIRONMENT_TYPE=production`. These scripts were written but **not executed** in the authoring environment
(no container runtime available), so expect to fix small issues on first run.
