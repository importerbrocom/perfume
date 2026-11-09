# Making a copy of the live site for testing

I (Kiro) have no access to the hosting account, so the copy has to start with an export from you.
Live site facts (checked 9 Nov 2026): served by **LiteSpeed**, nameservers `ns1-4.mysecurecloudhost.com`
(typical of cPanel-style hosting; confirm in your host's control panel). WooCommerce's public Store API returned
**0 published products**, so the live catalog appears empty: products will have to be added either way.

Pick ONE option.

## Option A: the host's own staging/clone tool (easiest, stays on the server)
Look in cPanel for **WP Toolkit -> Clone** or **Softaculous -> WordPress -> Staging**. Create the copy on a subdomain such as
`staging.perfumarathi.com`, then:
1. Protect it (WP Toolkit "Password protection" or cPanel "Directory Privacy") and enable "Discourage search engines".
2. Install an email blocker on the copy before anything else (e.g. the plugin "Disable Emails") so customers are never emailed.
3. Disable online payment gateways or switch them to **test mode**; pause WooCommerce webhooks (WooCommerce > Settings > Advanced > Webhooks).
4. Upload and activate `wp-content/plugins/perfumarathi-shop/`.

## Option B: local copy in Docker (built and tested here; safest)
Gives the live site on `http://localhost:8080` with our plugin, every email captured in Mailpit, online gateways and webhooks disabled.

### 1. Export from the host (cPanel)
- **Database:** cPanel > phpMyAdmin > select the WordPress database > Export > Quick > SQL > Go -> `live.sql` (or `.sql.gz`).
  (The database name is in `wp-config.php` as `DB_NAME`.)
- **Files:** cPanel > File Manager > `public_html` > right-click `wp-content` > Compress > Zip -> download `wp-content.zip`.
- Alternatively over SSH: `wp db export live.sql && zip -r wp-content.zip wp-content`.

These files contain customer data and password hashes: keep them on your computer, don't email or commit them
(`.gitignore` already blocks `*.sql`, `*.sql.gz`, `dev/import/`).

### 2. Import
Needs Docker Desktop (or Docker Engine + compose plugin).
```
cd dev
cp .env.dev.example .env          # set your own passwords
mkdir -p import && mv ~/Downloads/live.sql ~/Downloads/wp-content.zip import/
./import-live.sh import/live.sql import/wp-content.zip https://perfumarathi.com
./test-pages.sh --seed            # adds plugin pages + two [TEST] products (staging only)
```
What `import-live.sh` does:
- detects the table prefix, imports the DB into MySQL 8 (MariaDB dumps are cleaned automatically);
- copies themes/plugins/uploads, removing host cache drop-ins (`object-cache.php`, `advanced-cache.php`, LiteSpeed cache);
- rewrites `https://perfumarathi.com`, `www.` and `http://` variants (including Elementor's JSON-escaped URLs) to `http://localhost:8080`;
- deactivates cache / SMTP / force-SSL plugins; routes all mail to Mailpit; blocks indexing; adds a STAGING banner;
- disables online payment gateways (keeps COD/bank transfer) and pauses webhooks;
- creates admin `pmstaging` (password = `ADMIN_PASSWORD` in `.env`); your live admin logins also work;
- activates `perfumarathi-shop` against your real plugin set and prints WordPress/WooCommerce/theme versions,
  product/order counts and whether checkout is classic or block.

### 3. Test
- Site `http://localhost:8080`, admin `/wp-admin`, emails `http://localhost:8025`.
- Automated suite:
  ```
  cd tests/e2e && npm install && npx playwright install chromium
  BASE_URL=http://localhost:8080 ADMIN_USER=pmstaging ADMIN_PASSWORD=<from .env> MAILPIT_URL=http://localhost:8025 npx playwright test
  ```
  If the live site uses different slugs, set `PM_PATH_CHECKOUT`, `PM_PATH_ACCOUNT` (e.g. `/checkout-2/`).
- Then the manual checks in `docs/IMPLEMENTATION.md` section 10, with the real `skt-fragrance` theme and Elementor homepage.

### Reset / remove
`docker compose down -v` deletes the copy completely. Delete `dev/import/` afterwards.

## Option C: migration plugin
"All-in-One WP Migration" or "Duplicator" export -> import into a fresh site (`./setup.sh`, then install the same plugin and import).
Afterwards run `docker compose run --rm -T wpcli eval-file /clone-safety.php`. Option B is preferred because it applies every safety step automatically.
