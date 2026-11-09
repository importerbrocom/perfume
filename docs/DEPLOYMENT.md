# Deployment checklist

Never deploy straight to production. Order of operations:

1. **Backup** production DB and `wp-content` (store off-server). Confirm you can restore.
2. **Staging**: `cd dev && ./setup.sh`, or clone production to the host's staging. Install the plugin.
3. **Run tests on staging** (gateway in test mode): `cd tests/e2e && npm i && npx playwright install chromium && BASE_URL=<staging> npx playwright test`; then the manual checks in `docs/IMPLEMENTATION.md` section 10. Fix all failures.
4. Payment test matrix in `docs/PAYMENTS.md` passes.
5. **Production**: maintenance window; copy `wp-content/plugins/perfumarathi-shop/`; activate; Settings > Permalinks > Save; create pages (Track Order, Wishlist, brand shops) and run
   `wp option update pm_tracking_page_id <id>` and `wp option update pm_wishlist_page_id <id>`; update the Elementor homepage with `H.HTML` and add the `[pm_home_shop]` shortcode widget.
6. Hardening (wp-config.php / host): `define('DISALLOW_FILE_EDIT', true);`, HTTPS + HSTS, force SSL admin, 2FA for staff, login-attempt limiting, automatic security updates, WAF/CDN.
7. Backups: nightly DB dump (`mysqldump --single-transaction`), weekly uploads copy, 30-day retention, monthly restore test.
8. Post-deploy smoke test: place one COD order, track it, change its status, confirm the email, then cancel it.
9. **Rollback**: restore the DB + files backup; or deactivate the plugin (products/orders remain intact since only WooCommerce data is used) and restore `backup/H.original.HTML`.
