---
inclusion: always
---
# Perfumarathi & Fixnote – project rules

## LIVE SITE IS OFF-LIMITS (owner's instruction)
- **Never change the live site** (https://perfumarathi.com, hosted on cPanel; Cloudflare also used by the owner). No deploys, uploads, plugin
  installs/activation, database writes, setting changes, cache purges, or DNS/Cloudflare changes, unless the owner explicitly asks for that specific action.
- Allowed against live: read-only public HTTP requests (page/HEAD fetches, public read APIs). Nothing that logs in or writes.
- All work happens in this GitHub repo and local/CI Docker staging. Merging a PR must not deploy: do not add deploy workflows,
  `.cpanel.yml`, FTP/SSH/rsync steps, or Cloudflare/wrangler config.
- When giving the owner commands to run on the server, only give read-only ones (e.g. `wp db export`, `zip`, `wp option get`) and say so.

## Stack (do not change without asking)
- Existing **WordPress + Elementor + WooCommerce** site (theme `skt-fragrance`). Extend WooCommerce; never create parallel product/cart/order/customer tables, and no Laravel/React backend.
- Custom code lives in `wp-content/plugins/perfumarathi-shop/` (PHP 7.4+, vanilla JS, no build step).
- REST namespace `pm/v1`; cart/checkout/coupons use the WooCommerce Store API (server-side validation).

## Brand (must be preserved)
- Colours: orange `#ec7d28` / hover `#d96513`, brown `#3e2a21`, cream `#fff8f2`, peach `#ffe7d2`, border `#f2d4bc`, amber `#ffb46c`. Font: Arial/Helvetica (inherit theme elsewhere).
- Never alter logos, existing homepage sections, social links, or the video section. `backup/H.original.HTML` is the untouched original: never edit it.
- `H.HTML` uses **CRLF** line endings: preserve them when editing (use `newline=''` in scripts).

## Code rules
- Escape all output (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`); in JS use `esc()` for any inserted data.
- Every state-changing handler: nonce + capability check; sanitize all input; no raw SQL (use WP_Query / WC CRUD).
- No secrets in the repo; use env/`wp-config.php`. Never store or log card data. Never fake payment success outside tests.
- No fake product data except `dev/seed-staging.php` (staging only, items prefixed `[TEST]`).
- Respect `prefers-reduced-motion`, keep controls keyboard accessible.

## Workflow
- Never commit to `main`; use feature branches and PRs. CI must pass (`.github/workflows/ci.yml`).
- PHP cannot run in the Kiro sandbox: syntax-check with the CI workflow, and say so honestly instead of claiming runtime tests passed.
- Do not claim a feature is verified unless it was actually executed (see `docs/IMPLEMENTATION.md` section 10).
- Docs: `docs/IMPLEMENTATION.md` (architecture/API), `docs/PAYMENTS.md`, `docs/DEPLOYMENT.md`. Update them when behaviour changes.
