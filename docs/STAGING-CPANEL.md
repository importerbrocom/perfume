# Staging store on cPanel: https://perfume.nokkoo.in

A separate test store on the same hosting account. **perfumarathi.com is never touched**: the installer refuses to run
if the folder is the live site, `public_html`, not empty, or if the database already contains tables.

| Item | Value |
|---|---|
| Domain | `perfume.nokkoo.in` (Cloudflare: A record to 190.92.174.35, proxied) |
| Folder | `~/perfume.nokkoo.in` |
| Database / user | `uddjzwrz_perfume` / `uddjzwrz_perfume`, table prefix `pmstg_` |

## 1. Create the domain in cPanel (one time)
cPanel > **Domains** > **Create A New Domain** > `perfume.nokkoo.in`, untick "Share document root", document root `perfume.nokkoo.in` > Submit.
Make sure the database user has **All Privileges** on the database (cPanel > MySQL Databases > Add User To Database).

**HTTPS with Cloudflare:** set Cloudflare > SSL/TLS to **Full (strict)** once cPanel AutoSSL has issued a certificate for the subdomain
(cPanel > SSL/TLS Status > Run AutoSSL). If you see Cloudflare error 525/526 the origin certificate is missing; run AutoSSL or temporarily use **Full**.

## 2. Install (cPanel > Terminal)
```
curl -fsSL https://raw.githubusercontent.com/importerbrocom/perfume/main/server/install-staging.sh -o ~/install-staging.sh
bash ~/install-staging.sh
```
It asks for the database password (hidden) and the email address that should receive **all** staging emails.
It then installs WordPress + WooCommerce + perfumarathi-shop from GitHub, creates the shop pages, two `[TEST]` products,
classic cart/checkout, COD, AED, and prints the **staging key** and **admin password** once. Save them in a password manager.

## What protects the staging site
- **Staging key gate**: visitors see a "PERFUMARATHI STAGING" page until they enter the key (30-day cookie). Staff can log in via "Staff login".
- **All email goes to your staging inbox**, subject prefixed `[STAGING to original@address]`; CC/BCC removed. Customers are never emailed.
- `noindex` everywhere, STAGING banner, `WP_ENVIRONMENT_TYPE=staging`, `DISALLOW_FILE_EDIT`, `wp-config.php` mode 600.

## Afterwards
- Replace secrets any time (staging key, `pmadmin` password, and the database password after changing it in cPanel > MySQL Databases): `bash ~/rotate-staging-secrets.sh`. On this server use the installer's WP-CLI copy (`php ~/.wp-cli/pm-staging-wp-cli.phar`), not the system `wp`, which gets killed.
- Run the browser tests against it:
  `BASE_URL=https://perfume.nokkoo.in PM_GATE=<staging key> npx playwright test` (from `tests/e2e`). Admin email checks use your inbox, not Mailpit, so the admin spec is skipped.
- Remove it completely: delete the folder contents and drop all `pmstg_` tables (cPanel > phpMyAdmin), or remove the domain in cPanel.

## Tested
`.github/workflows/staging-script.yml` runs the installer on real PHP 8.2 + MySQL 8 in a fake cPanel home on every PR:
it must refuse the live folder, a wrong password, a second run and a non-empty database; then the installed site is checked
(plugins, pages, products, prefix, mail redirect incl. CC removal, Cloudflare IP handling) and the gate is tested over HTTP.
Not tested: the real cPanel server, LiteSpeed and Cloudflare in front of it.

## Brand homepage (same design as perfumarathi.com, with the shop added)
```
curl -fsSL https://raw.githubusercontent.com/importerbrocom/perfume/main/server/setup-brand-home.sh -o ~/setup-brand-home.sh
bash ~/setup-brand-home.sh
```
Updates perfumarathi-shop from GitHub, copies the two logos and the homepage video into
`wp-content/uploads/pm-brand/` (download only, perfumarathi.com is not changed), and makes the homepage the
`[pm_brand_home]` page on the "Perfumarathi Canvas" template (no theme header/footer, like Elementor Canvas):
video section, Perfumarathi and Fixnote halves (Enter + Shop buttons go to the staging brand shops),
then featured / best sellers / new arrivals / offers / collections / testimonials / Instagram / newsletter,
then the original social section and footer, with the live bag icon top right. Safe to run again.
