# LotLink (Car Lot App)

LotLink gives local car lots a ready-made online showroom: stock online, test-drive bookings,
one-tap social sharing and directions to the lot. Buyers browse every lot nearby, check what
they can afford and book a visit. Lot Manager handles walk-in customers, orders and payments.

Source documents (claude.ai artifacts):

- **Product Spec**: features, roles, plans
- **Technical Design Document**: 19 modules, schema, routes, jobs, 14-sprint delivery plan
- **LotLink — MVP Screens**: the design canvas (customer app, customer web, dealer, admin)

Stack: Laravel 12 · PHP 8.3+ · Inertia 2 + Vue 3 + TypeScript · Tailwind CSS 4 · MySQL 8 · Filament 3 (admin) · Pest.

## Status

**Sprint S2 (Inventory) ✅**

| Area | What works |
| --- | --- |
| Add a car (M3) | Four phone-first steps from the designs: VIN & model → details → photos → price & publish. Every step saves the draft. |
| VIN decode | NHTSA vPIC lookup fills make, model, year, trim, engine, fuel, drivetrain and body type, cached 30 days. Without a VIN (or if the lookup fails), pick the make and model by hand. |
| Catalogue | 25 makes and 213 models common on Nigerian lots, plus 33 features. Models a dealer types in wait for admin review in `/admin`. |
| Photos | Up to 20 per car. Large photos are shrunk in the browser, uploaded (directly to Cloudflare R2 in production), then converted to WebP at 1600, 800 and 400 px with location data removed. Reorder, set cover, remove. |
| Stock (D3) | Status tabs and counts, search by make, model or VIN, ageing flag at 45 days, new-arrival badge for 7 days, hide, unhide and reserve. |
| Rules | Status state machine (draft → available → reserved → sold, plus hidden), plan listing limits, price history with price-drop event, VIN unique per lot, sales staff can't change live prices or delete. |
| Quality | 114 Pest tests (also run on MySQL 8), Pint, Larastan level 6, vue-tsc |

Not in S2 (scheduled later in the TDD): bulk CSV/Excel import (S13), duplicate/fraud detection (S12), marking cars sold (Lot Manager orders, S5), views and leads per car (S11), share cards (S6), spotlight (S7), video and 360° photos (phase 2).

**Sprint S1 (Foundations) ✅**

| Area | What works |
| --- | --- |
| Accounts (M1) | Phone OTP sign-in (6 digits, 5-min expiry, 5 attempts, 3 sends per 15 min), E.164 normalisation, customer / staff / admin roles |
| Lots (M2) | Six-step onboarding: business details → logo and cover → map pin (GPS + Google Maps) → opening hours and booking rules → invite staff → submit for approval |
| Staff | Invite by phone (SMS) or email, 7-day links, owner / manager / sales roles, plan seat limits, change role, remove |
| Tenancy | `/dealer/{lot}` routes check membership; `BelongsToLot` scopes lot-owned models to the current lot |
| Dealer UI | Dashboard with setup checklist, Staff, Settings, lot switcher; later modules show as "Soon" |
| Admin | Filament panel at `/admin`: approve or suspend lots, manage users |
| Quality | 53 Pest tests (OTP limits, tenancy isolation, invitations, onboarding, admin), Pint, Larastan level 6, vue-tsc, GitHub Actions CI |

Next is **S3 Marketplace**: Meilisearch search and filters, "near me", car pages with Open Graph tags, compare, favourites and the lot mini-site.

## Run it on Windows with Laragon

Requirements: Laragon with **PHP 8.3+**, **MySQL 8**, **Node 20+** and Composer. PHP extensions
`intl`, `gd`, `zip`, `pdo_mysql` and `fileinfo` must be enabled (Laragon → Menu → PHP → Extensions).

```bash
cd C:\laragon\www
git clone -b claude/carlot-app-setup-sdtv6a https://github.com/samowolabi1/carlot.git carlot
cd carlot
```

1. Create an empty MySQL database called `carlot` (Laragon → Database, or HeidiSQL).
2. Install and set up:

   ```bash
   composer setup
   ```

   This installs PHP and JS dependencies, copies `.env.example` to `.env`, generates the app key,
   links storage, runs migrations with seed data and builds the frontend. If your MySQL root user
   has a password, set `DB_PASSWORD` in `.env` and run `php artisan migrate --seed`.

3. Laragon serves the app at **http://carlot.test** (Menu → Apache/Nginx → Reload if it doesn't
   appear). For hot reload while coding, run `npm run dev`.

### Trying it out

- **Add a car**: Dealer dashboard → Stock → Add car. Try VIN `4T1B11HK8JU654821` (a 2018 Toyota Camry SE).
  Photos are processed straight away because `.env.example` uses `QUEUE_CONNECTION=sync`.

- **Sign in** at `/login` with any Nigerian mobile number. With `SMS_DRIVER=log` the code is
  written to `storage/logs/laravel.log` (search for "Your LotLink code").
- **List a lot**: after signing in, open `/dealer` and follow the onboarding wizard.
- **Admin**: `/admin`, signing in with `admin@lotlink.test` / `password`. Change these in `.env`
  (`ADMIN_EMAIL`, `ADMIN_PASSWORD`) before seeding anywhere public.
- **Map**: set `GOOGLE_MAPS_BROWSER_KEY` for the interactive Google map with a draggable pin.
  Without it, "Use my current location" (phone GPS) and manual coordinates still work.
  Geolocation needs HTTPS or localhost; enable SSL in Laragon to test on a phone.

### Photos in production (Cloudflare R2)

1. Create two R2 buckets: `lotlink-uploads` (private) and `lotlink-media` (public, connected to a
   custom domain such as `media.yourdomain.com`), plus an API token with read/write on both.
2. Add a CORS rule to `lotlink-uploads` so browsers can upload directly: allowed origin your app
   URL, method `PUT`, header `Content-Type`.
3. Set the `R2_*` variables, then `LOTLINK_UPLOAD_DISK=r2_uploads` and `LOTLINK_MEDIA_DISK=r2_media`.
4. Run a queue worker for the `media` queue (`php artisan queue:work --queue=critical,notifications,media,default`).

## Commands

| Command | Does |
| --- | --- |
| `composer test` | Pest test suite (SQLite in memory) |
| `composer lint` | Pint style check and Larastan |
| `npm run typecheck` | vue-tsc |
| `npm run dev` / `npm run build` | Vite dev server / production build |
| `composer dev` | Queue worker, log tail and Vite together |

## Project layout

```
app/Domain/<Module>/     Models, Actions, Enums, Policies, Notifications per module
  Accounts/              Users, OTP codes, SendOtp / VerifyOtp
  Lots/                  Lots, members, invitations, hours; BelongsToLot tenancy
  Messaging/             SmsGateway (log, Termii)
  Support/               PhoneNumber (E.164)
app/Http/Controllers/    Thin controllers calling Actions; Dealer/ for /dealer/{lot}
app/Filament/            Admin panel resources
resources/js/Pages/      Inertia pages (Home, Auth/*, Dealer/*)
resources/js/components/ Shared UI; lot/ holds the forms shared by onboarding and settings
```

See `CLAUDE.md` for conventions.
