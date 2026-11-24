# Installing the React + Laravel shop on perfume.nokkoo.in (cPanel)

This puts the new shop on **https://perfume.nokkoo.in** only. **perfumarathi.com is never touched.**
The WordPress staging site on perfume.nokkoo.in is moved into a backup folder (not deleted) and can be put back with one command.

## How it works

- **GitHub builds, the server unpacks.** Every change merged to `main` runs the *Platform release* workflow. It runs the tests, builds the shop and the Laravel app (production packages only), and publishes `pmshop.tar.gz` plus its SHA-256 checksum as a GitHub release. The server never runs Composer or npm.
- **Folders on the server**

  | Folder | What |
  |---|---|
  | `~/pmshop/releases/<version>/` | One folder per release (last 5 kept) |
  | `~/pmshop/current` | Link to the active release (switching is instant) |
  | `~/pmshop/shared/.env` | Settings and passwords (mode 600, outside the web folder) |
  | `~/pmshop/shared/storage/` | Uploaded photos, logs, sessions (kept across releases) |
  | `~/pmshop/shared/backups/` | Database backup made before every update |
  | `~/perfume.nokkoo.in/` | Web folder: only `index.php`, `.htaccess`, `assets/`, `storage` (link to uploaded photos), plus cPanel's own files (`.well-known`, `php.ini`, `.user.ini`) |
  | `~/pm-wordpress-backup-<date>/` | The WordPress staging files and a copy of its database |

  Code, `.env` and logs are outside the web folder, so they can't be downloaded.
- **Cron job.** One cron job runs every minute (`schedule:run`). It cancels unpaid card orders after the timeout and puts their stock back.
- **Preview lock.** The shop starts private: visitors need the preview key. `/admin` and Stripe webhooks are not behind the lock.
- **Stripe live keys** are refused until `PAYMENTS_ALLOW_LIVE=true` is set in `~/pmshop/shared/.env`.

## Before you start

1. **Database.** cPanel > MySQL Databases: the database `uddjzwrz_perfumarathi1` exists and is empty. A user is added to it with **ALL PRIVILEGES**. If you pasted that password in a chat, change it here first.
2. **PHP.** cPanel > MultiPHP Manager: `perfume.nokkoo.in` uses PHP 8.3. Select PHP Version > Extensions: `pdo_mysql`, `mbstring`, `curl`, `fileinfo`, `gd`, `xml` are on. They usually are.
3. **Release.** GitHub > Actions > *Platform release (build for cPanel)* has a green run, and a release named `Shop release …` exists. To start one by hand: Actions > the workflow > *Run workflow*.
4. **Optional: email.** The SMTP details of an email account (cPanel > Email Accounts > Connect Devices) for order emails. You can also add them later.

## Install (once)

In **cPanel > Terminal**:

```bash
curl -fsSL https://raw.githubusercontent.com/importerbrocom/perfume/main/server/platform.sh -o ~/platform.sh
bash ~/platform.sh install
```

It asks for:

| Question | Answer |
|---|---|
| Database name | press Enter (`uddjzwrz_perfumarathi1`) |
| Database user | press Enter, or the user name shown in cPanel |
| Database password | typed hidden |
| Your email for the shop admin login | your email |
| Set up order emails now? | `n`, or `y` and the SMTP details |

What it does, in order:
1. Safety checks. It stops without changing anything if the folder is the live site or public_html, if the database is not empty, or if the database is the WordPress one.
2. Downloads the release and checks its SHA-256.
3. Reads the products, photos and reviews from WordPress (read-only).
4. Creates the tables and imports the 15 products with their prices, stock, photos and reviews.
5. Copies the homepage video and logos into the shop.
6. Creates your admin login. The password is shown **once**.
7. Copies the WordPress database to the backup folder and moves the WordPress files there.
8. Switches perfume.nokkoo.in to the shop, checks it answers, and adds the cron job.

At the end it shows the **preview key** and the **admin password**. Save both in your password manager and do not paste them into chat.

Then:
1. Open `https://perfume.nokkoo.in/admin` and log in.
2. Go to **Payment methods**. Paste the Stripe **test** keys and the webhook secret. In Stripe > Developers > Webhooks, add the webhook link shown there.
3. Go to **Store settings** and check the contact details, delivery charge and countries, and policy pages.
4. Visit `https://perfume.nokkoo.in`, enter the preview key, and place a test order with COD and with a Stripe test card (`4242 4242 4242 4242`).

## Day to day

| Task | Command |
|---|---|
| Install the newest release (database backup first, short maintenance page, goes back automatically if it doesn't start) | `bash ~/pmshop/platform.sh update` |
| Go back to the previous release | `bash ~/pmshop/platform.sh rollback` |
| Make the shop public | `bash ~/pmshop/platform.sh open` |
| Make it private again (prints a new preview key) | `bash ~/pmshop/platform.sh lock` |
| Set up or change order emails | `bash ~/pmshop/platform.sh mail` |
| Version, lock state, cron, health | `bash ~/pmshop/platform.sh status` |
| Put the WordPress staging site back (the shop and its data stay in `~/pmshop`) | `bash ~/pmshop/platform.sh restore-wordpress` |
| Switch back to the shop after that | `bash ~/pmshop/platform.sh publish` |
| New admin password / extra staff login | `cd ~/pmshop/current && php artisan shop:admin you@example.com --role=admin` |

Prices, stock, products, orders and settings are all managed in `/admin`. No commands are needed for those.

## Fixnote page

SHOP FIXNOTE opens `/shop-fixnote`, built from `FIXNOT_formatted.html`. Customers choose a region, pick 30 / 50 / 100 ML and press **ADD TO CART** or **BUY NOW** (WhatsApp is still offered in the bar):

- **UAE** and **Other GCC**: store prices (AED), COD or card. UAE delivery for Fixnote: 14 / 9 / 4 AED by size (the highest size in the order), free from 5 pieces.
- **India** and **Saudi**: their own prices and currency (INR / SAR), delivery to that country only, Fixnote perfumes only, no coupons, no card. Payment: cash on delivery (India, switchable) or **Pay after confirmation**: send the customer your UPI / Google Pay / bank details on WhatsApp, then press **Payment received** on the order before shipping.

The 378 perfumes (F01…) come from `platform/backend/database/data/fixnote-catalogue.json`. `update` adds them once; perfumes already in the shop (same code) or deleted in admin are not touched. To add missing ones again: `cd ~/pmshop/current && php artisan shop:import-fixnote`.

Admin › **Fixnote**:
- **Fixnote page**: one price per size for every perfume (only sizes you change are updated), UAE delivery, India / Saudi prices, delivery and cash on delivery, page texts, offer banners, pictures.
- Dashboard sales count store-currency (AED) orders only; INR / SAR orders are in the order list.
- **Fixnote perfumes**: the Fixnote products (search by code, name or inspired-by brand), add a perfume with the three sizes.
- **Fixnote orders**: orders with at least one Fixnote perfume.

## Apple Pay

Stripe > Settings > Payment methods > Apple Pay > Add domain `perfume.nokkoo.in`. Download the file Stripe gives you, paste its contents into Admin > Store settings > Orders & stock > *Apple Pay domain association file*, save, then press *Verify* in Stripe. Google Pay needs no setup.

## If something goes wrong

- **The server stopped PHP.** This means the hosting account's process or memory limits stopped the script. Check cPanel > Resource Usage and send Kiro the message.
- **The site shows an error.** Read the last lines of the newest file in `~/pmshop/shared/storage/logs/`. Never paste passwords or keys.
- **The installer says the shop did not answer.** It offers to put WordPress back right away. The shop files stay in `~/pmshop`, so nothing is lost.
- **A database backup is needed.** `~/pmshop/shared/backups/` keeps the last 10 (made before each update). cPanel > Backup also works.
- **Starting over.** Run `bash ~/pmshop/platform.sh restore-wordpress`. Then delete `~/pmshop`, and in cPanel > phpMyAdmin empty `uddjzwrz_perfumarathi1`. Run the install again.

## Tested in CI

The *cPanel staging installer* workflow builds a fake cPanel home folder with the real WordPress staging site, a separate "live" folder and MySQL. On it, it checks:
- **Refusals:** a wrong password and the WordPress database are refused and leave everything unchanged.
- **Install:** the database password (with quotes, `$` and a backslash) is never printed and is stored exactly.
- **After install:**
  - The web folder contains no code or `.env`.
  - The preview lock works.
  - All 15 products and their photos are served.
  - The admin login works with the printed password.
  - The cron job is added.
- **Commands:**
  - `update` and `rollback`; `update` makes a database backup first.
  - `open` and `lock`; the old key stops working after `lock`.
  - `mail`, with a password containing special characters.
  - `restore-wordpress` brings WordPress back with its products, and `publish` switches to the shop again.
- **The live folder** is unchanged at every step.
