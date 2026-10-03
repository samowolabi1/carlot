# Deploying CarYard on cPanel

CarYard runs on ordinary cPanel shared hosting. Nothing has to stay running in the background: the website is plain PHP,
and **one cron job** every minute runs the scheduler, which also processes the queue (photo processing, notifications,
broadcasts). No Redis, no search server, no websocket server and no `storage:link` symlink are needed.

| Piece | On cPanel | Where it's set |
|---|---|---|
| Website | PHP 8.3 (MultiPHP / Select PHP Version) | document root → `lotlink/public` |
| Database, sessions, cache, queue | MySQL (`QUEUE_CONNECTION=database`, `CACHE_STORE=database`, `SESSION_DRIVER=database`) | `.env` |
| Background jobs and schedules | the cron job, every minute (`QUEUE_VIA_CRON=true`) | cPanel → Cron Jobs |
| Images (car photos, logos, adverts, chat photos, share cards) | plain files in `public/media` (the image repository) | `LOTLINK_MEDIA_DISK=media` |
| Private files (CAC certificates, licences, IDs, loan documents, receipts) | `storage/app/private`, opened only through short-lived signed links | automatic |
| Search | MySQL (`SCOUT_DRIVER=null`) | `.env` |
| Live chat | polls every few seconds (no Reverb) | `VITE_REVERB_APP_KEY` empty at build time |
| Email | a cPanel email account over SMTP | `.env` |

Check everything at any time with **/admin → System health** or `php artisan lotlink:doctor`.

## 1. Build the package (on your PC or GitHub)

The server doesn't need Node or Composer. Build one zip that has everything:

- **GitHub:** Actions → **cPanel package** → Run workflow, then download `lotlink-cpanel` from the run's Artifacts.
- **Your PC** (PHP 8.3, Composer, Node 20+): `./scripts/build-cpanel.sh` → `build/lotlink-cpanel.zip` (about 90 MB).

The browser's settings are built in at this step: if you use a Google Maps key, put `VITE_GOOGLE_MAPS_BROWSER_KEY=` in
your local `.env` before building.

## 2. Prepare cPanel

1. **PHP:** cPanel → *MultiPHP Manager* (or *Select PHP Version*) → **PHP 8.3** for the domain. Extensions:
   `pdo_mysql, mbstring, openssl, intl, gd (with WebP), fileinfo, curl, xml, dom, ctype, tokenizer, iconv, zip`, and
   ideally `exif` and `gmp`.
2. **PHP options** (*MultiPHP INI Editor* or *Select PHP Version → Options*): `memory_limit 256M`,
   `upload_max_filesize 16M`, `post_max_size 20M`, `max_execution_time 120`. Leave `proc_open` enabled (the scheduler uses it).
3. **Database:** *MySQL Databases* → create a database and a user, and add the user to the database with **All Privileges**.
   MySQL 8 is recommended. MariaDB 10.6+ also works (the optional spatial index is skipped there), but our automated
   tests run on MySQL 8.
4. **Email:** *Email Accounts* → create e.g. `no-reply@caryardng.com`. *Connect Devices* shows the SMTP host and port.
5. **SSL:** *SSL/TLS Status* → run AutoSSL so the site is on `https://`.

## 3. Upload

1. *File Manager* → your home folder (e.g. `/home/myuser`, **not** inside `public_html`) → Upload `lotlink-cpanel.zip`
   → Extract. You now have `/home/myuser/lotlink`.
2. Point the website at `lotlink/public`. Choose one:
   - **A. Document root (best).** *Domains* → your domain → Manage → **Document Root** = `lotlink/public`. Addon domains
     and subdomains can always do this; on many servers the main domain can too.
   - **B. Symlink public_html.** In *Terminal*: `mv ~/public_html ~/public_html.old && ln -s ~/lotlink/public ~/public_html`.
   - **C. Copy into public_html** (if A and B aren't allowed): copy everything *inside* `lotlink/public` into
     `public_html`, then copy `lotlink/deploy/cpanel/public_html-index.php` over `public_html/index.php`, and add
     `MEDIA_ROOT=/home/myuser/public_html/media` to `.env` so uploaded images land in the web root.
   - **D. Last resort:** if the app had to go inside `public_html`, copy `deploy/cpanel/root.htaccess` to
     `public_html/.htaccess`. It sends everything to `public/` and blocks `.env`, `storage` and `vendor`.
3. Folder permissions: folders **755**, files **644**. `storage`, `bootstrap/cache` and `public/media` must be writable by
   your cPanel user (they are by default).

## 4. Configure

1. In `lotlink`, copy `.env.cpanel.example` to `.env` and fill it in: `APP_URL`, the database, SMTP, the `LEGAL_*`
   details, `ADMIN_EMAIL` / `ADMIN_PASSWORD` (a strong one), and later WhatsApp, Termii, Paystack/Flutterwave.
2. *Terminal* (or *Cron Jobs* → a one-off job if you have no terminal), in `~/lotlink`:

   ```bash
   php artisan key:generate
   php artisan lotlink:deploy --seed
   ```

   `lotlink:deploy` runs the migrations, seeds plans, the car catalogue and your admin (`--seed`, first time only),
   prepares `public/media`, caches config, routes and views, and ends with the health check.

   If `php` is the wrong version in the terminal, use the full path of the version you picked, e.g.
   `/opt/cpanel/ea-php83/root/usr/bin/php` or `/usr/local/bin/ea-php83`.

## 5. The cron job (required)

*Cron Jobs* → Add New Cron Job → **Once Per Minute** (`* * * * *`), command:

```bash
cd /home/myuser/lotlink && /usr/local/bin/ea-php83 artisan schedule:run >> /dev/null 2>&1
```

(Use the same PHP path as above.) This one line runs reminders, expiries, summaries, sitemap, data clean-up, **and the
queue**. After two minutes, /admin → System health should show "Cron job running: last run … seconds ago".

## 6. Services and webhooks

Set these in each provider's dashboard once the site is live (replace the domain):

| Service | URL |
|---|---|
| Paystack webhook | `https://caryardng.com/webhooks/paystack` |
| Flutterwave webhook | `https://caryardng.com/webhooks/flutterwave` |
| WhatsApp (Meta) | templates listed in the README; no webhook needed for sending |
| Google sign-in redirect | `https://caryardng.com/auth/google/callback` |
| Lenders using their own API | `https://caryardng.com/webhooks/finance/{lender}` (shown in the lender's settings) |

Web push: run `php artisan push:vapid` once (writes the keys to `.env`), then `php artisan config:cache`.

## 7. Updating to a new version

1. Build a new package (step 1) and upload/extract it over `~/lotlink`. Keep your `.env`, `storage/` and `public/media/`.
   Extracting the zip doesn't overwrite them, because the zip doesn't contain `.env` or any uploaded images.
2. `php artisan lotlink:deploy` (no `--seed`).

## 8. Backups

- *Backup* (or JetBackup): include the **database**, `lotlink/.env`, `lotlink/storage/app/private` (private documents)
  and `lotlink/public/media` (all images).
- `storage/logs` holds 14 days of logs (`LOG_CHANNEL=daily`).

## Troubleshooting

| Symptom | Fix |
|---|---|
| 500 error straight after upload | `storage` or `bootstrap/cache` not writable; or `APP_KEY` empty (`php artisan key:generate`). Look in `storage/logs`. |
| Photos stay "processing" | The cron job isn't running (System health → Cron), or `proc_open` is disabled. |
| Emails/sign-in codes by email don't arrive | Check SMTP settings; use port 465 with `MAIL_SCHEME=smtps`, or 587 with `MAIL_SCHEME=smtp`. |
| Images 404 | `LOTLINK_MEDIA_DISK=media`; with method C set `MEDIA_ROOT`. Run `php artisan media:move-to-public` if older images were saved in `storage/app/public`. |
| Page styles missing | `public/build` wasn't uploaded: rebuild the package. Admin styles: `php artisan filament:assets`. |
| After changing `.env` nothing changes | `php artisan config:cache` (or `php artisan lotlink:deploy`). |
