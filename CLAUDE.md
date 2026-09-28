# CLAUDE.md

LotLink: a multi-dealer car lot platform for Nigeria first (NGN, Paystack, WhatsApp), configurable
for the UK later. Three faces: customer marketplace, dealer dashboard (with Lot Manager for walk-ins),
and a per-lot mini-site. The Product Spec, Technical Design Document (TDD) and "LotLink — MVP
Screens" design canvas are the source of truth; build what they say, sprint by sprint.

## Stack

Laravel 12 (PHP 8.3+), Inertia 2 + Vue 3 `<script setup lang="ts">`, Tailwind CSS 4, MySQL 8,
Filament 3 admin at `/admin`, Pest. Ziggy provides `route()` in Vue.

## Commands

- `composer test` runs Pest (SQLite in memory; CI also runs migrations on MySQL 8)
- `composer lint` runs Pint (`--test`) and Larastan level 6
- `npm run typecheck` and `npm run build`

Run all four before pushing.

## Conventions (from the TDD)

- **Domain folders**: `app/Domain/<Module>/{Models,Actions,Enums,Policies,...}`. Business logic
  lives in single-purpose Action classes with a `run()` method, called from controllers, jobs and
  the API. Controllers stay thin.
- **Validation** in Form Requests; authorisation via Policies (`LotPolicy`: view = any member,
  update = owner/manager, manageStaff/submit = owner).
- **Enums** are PHP backed enums, cast on models. Add `@property` docblocks for new columns so
  Larastan knows the types.
- **Money**: unsigned bigint minor units (kobo) plus a `char(3)` currency. Never floats.
- **Time**: stored UTC, shown in `lots.timezone` (default Africa/Lagos).
- **Public IDs**: ULIDs (`ulid` column) in URLs; lots route by `slug`. Never expose auto-increment
  ids (models hide `id`).
- **Phone numbers**: always E.164 via `App\Domain\Support\PhoneNumber`; mask them in dealer UI
  until the customer engages.

## Lot Manager (S5)

- Sales are `SalesOrder`s; every payment, void, status change and cancel goes through its Action,
  which locks the order row. `OrderLedger` recalculates totals from `order_payments` and keeps the
  car's status in step (past draft = reserved, delivered = sold). Never set `vehicles.status` to
  sold anywhere else.
- Payments are never deleted: void with a reason. Refunds are negative payments.
- Order and receipt numbers come from `LotCounter::next()` inside the transaction.
- Offline-capable writes (walk-ins, orders, payments) take a `client_uuid` and must stay idempotent.
- Customers are only messaged with `consent_whatsapp`. Changes to money or status go in `AuditLog`.

## Multi-lot tenancy

- Dealer routes live under `/dealer/{lot}` with the `lot.member` middleware (`SetCurrentLot`),
  which checks membership and sets `CurrentLot`.
- Every lot-owned model uses the `BelongsToLot` trait: it stamps `lot_id` on create and scopes
  queries to the current lot. Use `withoutGlobalScopes()` only in cross-lot code (admin, public
  marketplace) and in Actions that are given the lot explicitly.
- Nested route params use `scopeBindings()`, so `{invitation}` must belong to `{lot}`.
- **Every new lot-owned model needs a tenancy test** (member of lot A gets 403/404 on lot B).

## Location

`lots.latitude`/`longitude` are the source of truth. On MySQL a stored generated
`location POINT SRID 4326` column (`ST_SRID(POINT(lng, lat), 4326)`) has a spatial index for
"lots near me" with `ST_Distance_Sphere`. Lots with no pin sit at 0,0 there, so always filter
`latitude IS NOT NULL`.

## Inventory (S2)

- `Vehicle` status changes go through `VehicleStateMachine`; never set `status` directly.
  `PublishVehicle` checks completeness and the plan listing limit; selling happens via Lot Manager
  orders (S5), not the stock list.
- Prices are kobo on the model; forms and `VehicleResource` use whole naira. `SaveVehiclePrice`
  writes `vehicle_price_history` once a car has been listed and fires `VehiclePriceDropped`.
- Photos: browser → `MediaUploads` target (pre-signed R2 PUT, or the app's upload endpoint on a
  local disk) → `AttachVehicleMedia` → `ProcessVehicleMedia` (queue `media`) → WebP 1600/800/400
  on the media disk. Originals never reach the public disk.
- `new arrival` (7 days) and `ageing` (45 days) are computed from `listed_at`, not stored.

## Marketplace (S3)

- Buyers only see `Vehicle::marketplace()`: available or reserved cars at active lots. Public
  pages use `MarketplacePresenter`, never model `toArray()`, so dealer-only fields don't leak
  (VIN shows as its last four characters).
- Search goes through the `VehicleSearch` interface: `MeilisearchVehicleSearch` (Scout,
  production) or `DatabaseVehicleSearch` (SCOUT_DRIVER=null, Laragon). Any new filter must be
  added to both and to the shared engine tests in `tests/Feature/Marketplace/VehicleSearchTest.php`
  (set `MEILISEARCH_TEST_HOST` to run the Meilisearch side locally).
- Share previews: controllers pass `->withViewData(['meta' => [...]])`; `app.blade.php` renders
  the Open Graph tags on the server.
- The buyer's location lives in localStorage (`useLocation`) and is only sent as query params
  for distance sorting; it is never stored server-side.

## Appointments (S4)

- Slots come only from `SlotGenerator` (lot timezone in, UTC `starts_at` out). Bookings and moves go
  through `BookAppointment` / `RescheduleAppointment`, which lock the lot row before checking
  capacity; never insert appointments directly.
- `Appointment::fromDateTime()` stores dates as UTC; keep it that way for any new date column.
- Messages to buyers and lots use `App\Domain\Messaging\Message` (a Meta template name plus SMS
  text) via the `phone` notification channel or `Messenger`: WhatsApp first, SMS fallback. New
  business-initiated WhatsApp messages need a new approved template (list in README).
- Links in messages are signed routes so they work without signing in.
- Scheduler: `appointments:remind`, `appointments:mark-no-shows`, `appointments:escalate-pending`.

## External services

Each sits behind an interface so it can be swapped by market: `SmsGateway` (log | Termii), `WhatsAppGateway` (log | Meta Cloud API), `VinDecoder` (NHTSA vPIC);
Paystack, WhatsApp Cloud API and others follow the same pattern. Tests bind fakes
(`tests/Support/FakeSmsGateway`) and never call real services.

## Design

Follow the MVP Screens canvas. Tokens are in `resources/css/app.css` (`@theme`): ivory `#F6F4EF`
background, forest `#16302B`, clay `#C2410C` primary actions, DM Sans body text, Bricolage Grotesque
headings. Customer screens are phone-first (390 px) with a bottom tab bar; dealer screens use the
forest sidebar. Use real `<button>`/`<a>`/`<label>` elements, 44 px touch targets and no emoji
icons (use `components/Icon.vue`). Features from later sprints appear as disabled "Soon" items
rather than fake data.

## Sprint plan (TDD)

S1 Foundations ✅ · S2 Inventory ✅ · S3 Marketplace ✅ · S4 Appointments ✅ · S5 Lot Manager lite ✅ ·
S6 Sharing + budgeting (MVP launch) · S7 Billing + spotlight · S8 Leads + chat · S9 Offers ·
S10 Lot Manager pro · S11 Location + analytics · S12 Trust + admin · S13 SEO · S14 Integrations.

Deferred from S1: admin 2FA (TOTP), Sanctum API endpoints (the package is installed),
Redis/Horizon (the database queue for now).
