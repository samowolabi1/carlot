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

**Sprint S7 (Billing and spotlight) ✅**

| Area | What works |
| --- | --- |
| Plans and trials (M16) | Every new lot starts a 14-day Starter trial. Existing lots started theirs when this sprint's migration ran. The owner gets a WhatsApp reminder 3 days before the end. When a trial ends unpaid, or a renewal doesn't arrive, the lot gets 7 days of grace. After that it moves to Free: cars above Free's 10-car limit are **hidden, not deleted** (the newest and spotlighted stay live, reserved cars always stay). Choosing Free on a paid plan keeps it until the paid month ends. |
| Paystack | Checkout goes to Paystack with the plan's Paystack code, so the card is charged monthly. Every payment is verified with Paystack's API before anything changes, and the amount must match. The webhook (`/webhooks/paystack`) checks the HMAC SHA-512 signature, stores each event and handles it once. It covers first payments, renewals, the subscription code, failed renewals (past due and grace) and cancellations. Admins refund from `/admin/payments`. |
| Sandbox | Without Paystack keys (`PAYMENT_DRIVER=sandbox`, the default locally) payments go to a LotLink test page with "Pay" and "Decline" buttons, and then through the same verification code. It is refused in production. |
| Billing page (D11) | Current plan, trial days left, card, this month's usage (listings, staff, free spotlights), the four plans with choose/switch, a coupon box, featuring the lot, running spotlights, and payments with PDF invoices. Only the owner can change the plan; managers can view it. |
| Coupons | `LAUNCH3` (seeded, 20 uses) gives 90 days free on Starter, per the spec's launch offer. Create more in `/admin/coupons`. |
| Spotlight (M5) | Stock → **Spotlight** on a live car: 7, 14 or 30 days. The car shows in a "Sponsored" row (at most 3) above matching search results, in both search engines, and in the home page's Spotlight carousel. Pro includes 2 free 7-day spotlights a month. Buying more adds days to the end. The hourly `spotlights:expire` ends them. |
| Featured lots | From Billing, owners and managers can pay to put the lot in the home page's "Featured lots" row for 7, 14 or 30 days. |
| Follow a lot | "Follow for new stock" on lot pages, and "Lots I follow" in Account. Every 30 minutes followers get one WhatsApp message per lot about cars listed since the last one. |
| Plan limits | Listings (Free 10, Starter 50), staff (1/3/10) and open orders (Free 10) are enforced. Share-card images come with Starter and up. |
| Quality | 305 tests (also on MySQL), including webhook signature and idempotency, verification and amount checks, trial, grace and downgrade, the free spotlight allowance, sponsored search on both engines, and follower batching. |

Prices are placeholders (Starter ₦15,000, Pro ₦45,000 a month; spotlights from ₦5,000). Set real ones in `/admin/plans` and `config/lotlink.php` after talking to your first lots. Deferred: test-drive and reservation deposits (S9), web push (S8 notification centre), admin-editable spotlight prices (S12).

**Sprint S6 (Sharing and budgeting, MVP launch) ✅**

| Area | What works |
| --- | --- |
| Share links (M9) | Every share goes through a short tracked link, `/c/{code}`. It redirects to the car or lot page with `?ref=` so later analytics can credit the platform. Clicks are counted, but link-preview bots (WhatsApp, Facebook) are not. The same person sharing the same car to the same app reuses one link. Unused links are pruned after 90 days; QR links are kept. |
| Share cards | Each live car gets two branded PNG images: 1080×1080 for posts and 1080×1920 for WhatsApp Status and Stories. They show the photo, price, title, specs, the lot and a QR code that opens the car. Cards are re-rendered when the price, details or cover photo change, or when the lot's name, phone or logo change. They are also the image in link previews (`og:image`). |
| Sharing | On the car page the phone's share sheet sends the card image and link where supported. Otherwise a menu offers WhatsApp, Facebook, X, Telegram, Instagram (saves the Story image) and copy link. Dealers can share from the stock list and download the card images. |
| Budget (M10) | "What can I afford?" (design 08): income, commitments, deposit, loan length and rate give a maximum price, a monthly payment and the loan amount, plus "Show N cars within budget". The budget saves to the account, or to the phone for guests. Search results and cards show a "Within budget" tag, and Home and Search have a "Within my budget" filter. |
| Car page finance | "From ₦343,500/mo" at the default terms, a loan calculator (deposit, length, rate) and yearly running costs (insurance, papers, fuel, servicing). All figures are labelled estimates. Compare adds a monthly row. |
| Location | "Share location" on lot pages and booking pages sends the lot's name, address and a Google Maps link by share sheet, WhatsApp, SMS or copy. |
| Installable app (PWA) | Manifest, icons, an install button (Home, Account, dealer sidebar) and "Add to Home Screen" help for iPhone. A service worker caches the app's files and keeps Lot Manager pages that were opened before, so they reopen with no signal; other pages show an offline screen. |
| Account (design 21) | Budget, bookings, saved cars, and orders and receipts from lots that recorded this phone number. Staff get links to their dealer dashboards. |
| Quality | 267 tests (also on MySQL), including the budget formula checked against the design, share-link tracking, share-card rendering and re-rendering, and the manifest. |

Deferred as planned: live location sessions (S11), social auto-posting (phase 3), QR posters and windscreen stickers (S13), shares-by-platform analytics (S11), web push (with notifications), admin-editable finance rates (S12).

**Sprint S5 (Lot Manager lite) ✅**

| Area | What works |
| --- | --- |
| Walk-in register (M19) | Dealer sidebar → Lot Manager → Today or Walk-ins → "Walk-in". Only name and phone are required; add the cars viewed (searched from your stock), interest, next step, source, budget, a note and WhatsApp consent. Returning visitors are matched by phone (E.164), so one person is one customer. "Call back" creates a follow-up for the next working day at 10:00, and the attending staff member gets a reminder when it is due. |
| Customer book | One record per person per lot with tags (hot, cash buyer, instalment, trade-in, repeat), budget, notes and consent, plus a timeline of visits, orders, payments, follow-ups and LotLink bookings made with the same phone. Customers who already use LotLink are linked. Only that lot can see its customers. |
| Orders (M13) | Start an order from a customer and an available or reserved car, or use "Mark sold" on the stock list, which opens an order with the car filled in. Numbers run per lot (`PM-2026-00001`). Status: draft → deposit paid → fully paid → papers ready → delivered, or cancelled. The first payment reserves the car and delivery marks it sold. Handing over a car that isn't fully paid needs the owner and a reason, which goes in the audit log. Cancelling with money paid records a refund or keeps it as credit, and puts the car back in stock. Free lots can have 10 open orders. |
| Payments and receipts | Cash, transfer, POS or other. The order row is locked while totals are recalculated, so payments made at the same time can't corrupt the balance. Each payment gets a numbered PDF receipt that links to the order tracking page. With consent, the receipt goes to the customer on WhatsApp (SMS fallback) and by email when there is an address. Mistakes are voided with a reason, never deleted. |
| Order tracking | `/o/{order}` is a signed link (no sign-in) showing progress, payments, balance and receipts, with "Powered by LotLink". |
| Offline queue | If the network drops, walk-ins and payments are saved in the browser (IndexedDB), and a "N items waiting to sync" banner shows. They are sent when the connection returns. The server ignores repeats of the same `client_uuid`, so nothing is saved twice. |
| List on LotLink | The stock list's actions now read "List on LotLink" / "Take off LotLink". A car that a paid order holds can't be un-reserved by hand. |
| Quality | 245 tests (also on MySQL), including idempotent sync, payment balances, status flow, signed links, receipt numbering, consent, plan limit and tenancy. |

Deferred as planned: instalment plans, papers checklist, car costs and profit, daily summary and reports (S10); trade-ins and reservations (S9); Paystack payments (S7); installing the PWA with an offline app shell (S6). The offline queue works when the page is already open and the signal drops.

**Sprint S4 (Appointments) ✅**

| Area | What works |
| --- | --- |
| Booking (M7) | Buyers book a viewing, test drive, inspection or trade-in valuation from a car or lot page: pick a day and a free slot (next 14 days, from opening hours, minus closures, full slots and the lot's minimum notice). Capacity is enforced with a row lock, so two buyers can't take the last place. |
| Confirmation | Auto-confirm or manual confirm per lot. Buyers get WhatsApp (or SMS) plus email with a calendar file (.ics). Their booking page has directions, add to calendar, reschedule and cancel; links in messages work without signing in (signed URLs). |
| Dealer calendar (D4) | Week grid by visit type; drag a booking to move it; Today panel with check-in, no-show and complete; "Needs confirmation" queue; drawer with call/WhatsApp, rep assignment, reschedule and cancel. Every change messages the buyer. |
| Reminders and jobs | 24-hour and 2-hour reminders, no-shows 30 minutes after an unchecked start, unconfirmed requests escalated to the owner after 4 hours, visits auto-completed after check-in. |
| WhatsApp | WhatsApp Business Cloud API behind an interface with SMS fallback. Sign-in codes and staff invites now go by WhatsApp first ("Send by SMS instead" on the code screen). |
| Settings | Booking rules (auto-confirm, minimum notice) and closure days. |
| Roles | Sales reps work their own and unassigned bookings; owners and managers assign reps and work all bookings. |
| Quality | 188 tests (also on MySQL), including capacity, timezones, signed links, reminders and tenancy |

Deferred as planned: test-drive deposits (S9, needs Paystack), lead records from bookings (S8), live location (S11), reviews after visits (S12), web push (S6).

**Sprint S3 (Marketplace) ✅**

| Area | What works |
| --- | --- |
| Search (M4) | `/cars` with text search, make, body type, price, year, mileage, gearbox, condition and fuel filters, sorting, and removable filter chips. Filter sheet on phones, sidebar on desktop. |
| Near me | Uses the phone's location (asked first, never stored on the server) to sort by distance or limit to 5–100 km, with distances on every card. |
| Search engines | Meilisearch in production (typo-tolerant, geo filters) via Laravel Scout; plain MySQL on Laragon. Both pass the same tests. |
| Car page | `/car/{id}-{slug}`: photo gallery, specs, features, lot card with open/closed status and directions, WhatsApp (message pre-filled with the car and link) and Call buttons, share menu, similar cars. Link previews (Open Graph) are rendered on the server. Sold cars stay reachable but marked sold. |
| Compare | Up to 3 cars side by side, best value in each row highlighted. |
| Favourites | Heart on every car; guests sign in and the car is saved when they return. `/saved` shows price drops. |
| Lot mini-site (M6) | `/l/{slug}`: branded page with logo, cover and colour, stock with quick filters, opening hours, directions, call, WhatsApp and share. Unapproved lots can preview their own page. |
| Quality | 149 tests, including the search suite on both engines against a real Meilisearch |

Try it on Laragon with demo data: `php artisan db:seed --class=DemoMarketplaceSeeder` (3 demo lots, 10 cars).

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

Next is **S8 Leads and chat**: lead capture from every source into the customer book, a kanban board, follow-ups, live chat and the notification centre.

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
- **Lot Manager**: Dealer dashboard → Lot Manager → Today. Record a walk-in, then Stock → "Mark sold"
  on an available car to start an order and record payments. Receipts and messages are written to
  `storage/logs/laravel.log` while `WHATSAPP_DRIVER=log`. To test the offline queue, turn off the
  network in the browser's DevTools and record a payment.
- **Billing and spotlight**: Dealer dashboard → Billing. With `PAYMENT_DRIVER=sandbox` (the
  default) "Choose Pro" opens a test checkout; press Pay and you're back on Pro. Try the code
  `LAUNCH3`, then Stock → Spotlight. To take real payments, see "Paystack in production" below.
- **Budget and sharing**: open `/budget`, then any car page. Share cards are saved under
  `storage/app/public/share-cards`, so run `php artisan storage:link` once if images don't load.
- **Install the app**: the service worker only runs over HTTPS or on `localhost`/`127.0.0.1`,
  and only with the built files (`npm run build`), not `npm run dev`. On `http://carlot.test`
  the site works normally without it; enable SSL in Laragon to try installing on a phone.
- **Map**: set `GOOGLE_MAPS_BROWSER_KEY` for the interactive Google map with a draggable pin.
  Without it, "Use my current location" (phone GPS) and manual coordinates still work.
  Geolocation needs HTTPS or localhost; enable SSL in Laragon to test on a phone.

### Scheduled jobs (reminders)

Reminders, no-shows, escalations, follow-up reminders and share-link pruning run on Laravel's scheduler. On Laragon, keep
`php artisan schedule:work` running in a terminal while testing bookings. In production add one
cron entry: `* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1`.

### WhatsApp in production

Set `WHATSAPP_DRIVER=meta`, `WHATSAPP_TOKEN` and `WHATSAPP_PHONE_NUMBER_ID` from Meta's WhatsApp
Business Cloud API, and create these templates (body variables in order; the URL button's base is
your `APP_URL` with a `{{1}}` suffix):

| Template | Category | Body variables | Button |
| --- | --- | --- | --- |
| `login_code` | Authentication | code | copy code |
| `staff_invitation` | Utility | lot name, role | invitation link |
| `booking_confirmed` | Utility | name, what, lot, when | manage booking |
| `booking_pending` | Utility | name, what, lot, when | manage booking |
| `appointment_reminder` | Utility | what, lot, when, directions | manage booking |
| `appointment_update` | Utility | what, lot, change | manage booking |
| `dealer_booking_alert` | Utility | event, buyer, what, when | open calendar |
| `payment_receipt` | Utility | name, amount, lot, car, receipt no, balance | track order |
| `order_update` | Utility | name, car, lot, update | track order |
| `billing_update` | Utility | lot, message | open billing |
| `lot_new_stock` | Marketing | lot, number of cars, example car | open the lot |
| `follow_up_due` | Utility | staff name, type, customer, lot | open Today |

Until a template is approved, messages fall back to SMS automatically.

### Paystack in production

1. Set `PAYMENT_DRIVER=paystack`, `PAYSTACK_PUBLIC_KEY` and `PAYSTACK_SECRET_KEY`.
2. In `/admin/plans`, set real prices, then press "Create on Paystack" on each paid plan so it renews monthly.
3. In the Paystack dashboard, set the webhook URL to `https://your-domain/webhooks/paystack`.
4. Keep the scheduler running: `subscriptions:enforce-limits` runs daily at 02:00, `spotlights:expire` hourly, and `followers:notify` every 30 minutes.

### Search in production (Meilisearch)

Run Meilisearch (Laravel Forge can install it, or use Meilisearch Cloud), then set
`SCOUT_DRIVER=meilisearch`, `MEILISEARCH_HOST` and `MEILISEARCH_KEY`, and run:

```bash
php artisan scout:sync-index-settings
php artisan scout:import "App\Domain\Inventory\Models\Vehicle"
```

Cars are added to and removed from the index automatically as they are published, hidden or
sold, and when a lot is approved or suspended.

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
  LotManager/            Walk-ins, customers, orders, payments, receipts, follow-ups
  Sharing/               Share links (/c/{code}), share cards, QR codes
  Finance/               Budgets and the FinanceCalculator (same formulas in resources/js/lib/finance.ts)
  Billing/               Plans, subscriptions, payments, coupons, spotlights; PaymentGateway (Paystack | sandbox)
  Messaging/             SmsGateway (log, Termii)
  Support/               PhoneNumber (E.164)
app/Http/Controllers/    Thin controllers calling Actions; Dealer/ for /dealer/{lot}
app/Filament/            Admin panel resources
resources/js/Pages/      Inertia pages (Home, Auth/*, Dealer/*)
resources/js/components/ Shared UI; lot/ holds the forms shared by onboarding and settings
```

See `CLAUDE.md` for conventions.
