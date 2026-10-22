# Deploying CarYard on cPanel

CarYard runs on ordinary cPanel shared hosting. Nothing has to stay running in the background: the website is plain PHP,
and **one cron job** every minute runs the scheduler, which also processes the queue (photo processing, notifications,
broadcasts). No Redis, no search server, no websocket server and no `storage:link` symlink are needed.

| Piece | On cPanel | Where it's set |
|---|---|---|
| Website | PHP 8.3 (MultiPHP / Select PHP Version) | document root → `caryard/public` |
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

- **GitHub:** Actions → **cPanel package** → Run workflow, then download `caryard-cpanel` from the run's Artifacts.
- **Your PC** (PHP 8.3, Composer, Node 20+): `./scripts/build-cpanel.sh` → `build/caryard-cpanel.zip` (about 90 MB).

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

1. *File Manager* → your home folder (e.g. `/home/myuser`, **not** inside `public_html`) → Upload `caryard-cpanel.zip`
   → Extract. You now have `/home/myuser/caryard`.
2. Point the website at `caryard/public`. Choose one:
   - **A. Document root (best).** *Domains* → your domain → Manage → **Document Root** = `caryard/public`. Addon domains
     and subdomains can always do this; on many servers the main domain can too.
   - **B. Symlink public_html.** In *Terminal*: `mv ~/public_html ~/public_html.old && ln -s ~/caryard/public ~/public_html`.
   - **C. Copy into public_html** (if A and B aren't allowed): copy everything *inside* `caryard/public` into
     `public_html`, then copy `caryard/deploy/cpanel/public_html-index.php` over `public_html/index.php`, and add
     `MEDIA_ROOT=/home/myuser/public_html/media` to `.env` so uploaded images land in the web root.
   - **D. Last resort:** if the app had to go inside `public_html`, copy `deploy/cpanel/root.htaccess` to
     `public_html/.htaccess`. It sends everything to `public/` and blocks `.env`, `storage` and `vendor`.
3. Folder permissions: folders **755**, files **644**. `storage`, `bootstrap/cache` and `public/media` must be writable by
   your cPanel user (they are by default).

## 4. Configure

In *File Manager* → **Settings** (top right), tick **Show Hidden Files (dotfiles)**. In `caryard`, copy
`.env.cpanel.example` to `.env` and edit it: `APP_URL`, the database, SMTP, the `LEGAL_*` details and `ADMIN_EMAIL` /
`ADMIN_PASSWORD` (12+ characters; without them no admin is created). Leave `APP_KEY` empty: it's created for you. WhatsApp,
Termii and Paystack/Flutterwave can be added later.

## 5. The cron job (required, and it does the install)

*Cron Jobs* → Add New Cron Job → **Once Per Minute** (`* * * * *`), command:

```bash
cd $HOME/caryard && /usr/local/bin/ea-php83 artisan schedule:run >> /dev/null 2>&1
```

Use the PHP of the version you picked: usually `/usr/local/bin/ea-php83` or `/opt/cpanel/ea-php83/root/usr/bin/php`
(CloudLinux: `/opt/alt/php83/usr/bin/php`).

This one line runs reminders, expiries, summaries, sitemap, data clean-up and **the queue**, and it **installs and updates
the app by itself**: each package carries a `VERSION` file, and whenever it differs from the last version set up, the cron
job first creates `APP_KEY` if it's empty, runs `lotlink:deploy` (with `--seed` the first time: tables, plans, car catalogue,
your admin) and records the version (`lotlink:auto-update`, `App\Domain\System\AppVersion`). Meanwhile visitors see a short
"CarYard is updating" page instead of errors.

Within two minutes, open `caryard/storage/logs/auto-update.log` in File Manager: it ends with **Done: running …**. If it
says the update failed, the lines above it say why (usually the database details in `.env`); fix `.env` and it tries again
on its own the next minute. Then sign in at `/admin`; **System health** should show "Cron job running" and the version.

**With a terminal** you can do the same by hand: `php artisan key:generate`, then `php artisan lotlink:deploy --seed`.

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

1. Build a new package (step 1), upload it and extract it over `~/caryard`. Your `.env`, `storage/` and `public/media/` are
   kept: the zip doesn't contain `.env` or any uploaded images.
2. Wait a minute. The cron job sees the new `VERSION`, migrates, refreshes the caches and records it (see
   `storage/logs/auto-update.log`). No terminal needed. (With one, `php artisan lotlink:deploy` does it straight away.)

After changing `.env` (not uploading code), the settings cache needs refreshing: `php artisan config:cache`, or without a
terminal a one-off cron job `cd $HOME/caryard && /usr/local/bin/ea-php83 artisan config:cache`, deleted after a minute.

## 8. Backups

- *Backup* (or JetBackup): include the **database**, `caryard/.env`, `caryard/storage/app/private` (private documents)
  and `caryard/public/media` (all images).
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
| The site keeps saying "CarYard is updating" | The cron job isn't running, or the update fails: read `storage/logs/auto-update.log`. |
