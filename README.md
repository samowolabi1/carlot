# LotLink (Car Lot App)

LotLink gives local car lots a ready-made online showroom: stock online, test-drive bookings,
one-tap social sharing and directions to the lot. Buyers browse every lot nearby, check what
they can afford and book a visit. Lot Manager handles walk-in customers, orders and payments.

Source documents (claude.ai artifacts):

- **Product Spec**: features, roles, plans
- **Technical Design Document**: 19 modules, schema, routes, jobs, 14-sprint delivery plan
- **LotLink — MVP Screens**: the design canvas (customer app, customer web, dealer, admin)

Stack: Laravel 12 · PHP 8.3+ · Inertia 2 + Vue 3 + TypeScript · Tailwind CSS 4 · MySQL 8 · Filament 3 (admin) · Pest.

## Status: Sprint S1 (Foundations) ✅

| Area | What works |
| --- | --- |
| Accounts (M1) | Phone OTP sign-in (6 digits, 5-min expiry, 5 attempts, 3 sends per 15 min), E.164 normalisation, customer / staff / admin roles |
| Lots (M2) | Six-step onboarding: business details → logo and cover → map pin (GPS + Google Maps) → opening hours and booking rules → invite staff → submit for approval |
| Staff | Invite by phone (SMS) or email, 7-day links, owner / manager / sales roles, plan seat limits, change role, remove |
| Tenancy | `/dealer/{lot}` routes check membership; `BelongsToLot` scopes lot-owned models to the current lot |
| Dealer UI | Dashboard with setup checklist, Staff, Settings, lot switcher; later modules show as "Soon" |
| Admin | Filament panel at `/admin`: approve or suspend lots, manage users |
| Quality | 53 Pest tests (OTP limits, tenancy isolation, invitations, onboarding, admin), Pint, Larastan level 6, vue-tsc, GitHub Actions CI |

Next is **S2 Inventory**: vehicle CRUD, the 4-step add-car flow, VIN decode, photo pipeline.

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

- **Sign in** at `/login` with any Nigerian mobile number. With `SMS_DRIVER=log` the code is
  written to `storage/logs/laravel.log` (search for "Your LotLink code").
- **List a lot**: after signing in, open `/dealer` and follow the onboarding wizard.
- **Admin**: `/admin`, signing in with `admin@lotlink.test` / `password`. Change these in `.env`
  (`ADMIN_EMAIL`, `ADMIN_PASSWORD`) before seeding anywhere public.
- **Map**: set `GOOGLE_MAPS_BROWSER_KEY` for the interactive Google map with a draggable pin.
  Without it, "Use my current location" (phone GPS) and manual coordinates still work.
  Geolocation needs HTTPS or localhost; enable SSL in Laragon to test on a phone.

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
