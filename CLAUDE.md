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

## Lot Manager Pro (S10)

- Instalments are a schedule, not money: `SetInstalmentPlan` creates them and `InstalmentSchedule::apply()`
  recomputes paid amounts and status from `total_paid − instalments_from_paid` (oldest first). It is
  called from `OrderLedger`, so payments, voids and refunds keep it right; never edit `paid_amount` directly.
- Papers: `OrderDocuments::ensure()` gives each order its checklist; papers_ready needs the mandatory
  items in; delivery marks received items handed over. Scans and cost receipts use the private `local` disk.
- Costs and profit (`VehicleCost`, `Profit`) are gated by `LotPolicy::viewCosts` (owner/manager + Pro).
  Never add them to customer pages, receipts, presenters for buyers or API resources.
- Reports come from `ManagerReports` (one shape for the page and `ReportExport` to Excel).
- Time-of-day jobs (reminders 09:00, summary 19:00) run hourly and check each lot's local time.

## Location and analytics (S11)

- Record analytics only through `Tracker` (`view()` dedupes and filters bots; `record()` for saves,
  shares, leads, bookings), which queues `TrackEvent`. Dashboards read `daily_vehicle_stats`
  (`stats:rollup`, idempotent per local day), never the raw events.
- `DealerAnalytics` builds the analytics page; `PricingGuide` needs 5+ comparables (same model, year ±1).
- Live location: `StartLocationSession` / `UpdateLocation` / `EndLocationSession`. Only the latest point
  is stored and it is cleared on end; only the two sides of the appointment may view it (the
  `location-session.{ulid}` channel uses the same rule). End sessions whenever a visit ends.

## Trust and admin (S12)

- Trust lives in `app/Domain/Trust`. Verifications: `SubmitLotVerification` / `DecideLotVerification`
  (sets `lots.verified_at`); CAC files stay on the private `local` disk behind signed `verifications.file` links.
- Inspections: `SaveInspection` (40 checks in `InspectionChecklist`, score = pass 1 / advisory ½ / fail 0)
  sets `vehicles.inspection_id`; a dealer check never replaces a signed independent one. PDFs via `InspectionPdf`.
- Reviews only through `SubmitReview` (completed visits, one per appointment, 14-day edits) and `ReplyToReview`
  (once). Call `RefreshLotRating::run()` whenever a review's visibility changes; ratings show from 3 reviews.
- Reports only through `SubmitReport`. Held cars (`vehicles.held_at`) are off `Vehicle::marketplace()` and
  can't be published; only `ModerateListing` (admin) clears them. Fraud signals come from `DetectFraudSignals`
  (on publish and price drops) and never hide anything themselves.
- Filament closures are injected by parameter name: use `$query`, `$record`, `$state`, `$search`.
- "Log in as" goes through `Impersonation` (audit-logged); never log in as another user any other way.

## SEO and growth (S13)

- Landing pages resolve through `Seo\Landing` (make slug first, else a live lot's city) and render the search
  page via `SearchController::render()`; pages without stock are `noindex`. Keep titles/intros data-driven.
- Structured data only from `Seo\StructuredData` (printed with `encode()`, which escapes `<`/`>`); pass it as
  `meta['jsonld']`. Never put dealer-only fields in it.
- Saved searches store `SavedSearches::filters()` (no lat/lng/radius: buyer location is never stored).
  `SendPriceAlerts` runs on first publish and on price drops; matching uses `DatabaseVehicleSearch::matches()`.
- Every printed QR goes through `CreateShareLink` with platform `qr` (`Printables`). PDFs use DejaVu with font subsetting.
- Bulk import: `ImportVehicles` creates drafts only through the add-car Actions; plan feature `bulk_import` (Enterprise).

## Integrations and hardening (S14)

- Social auto-post: `SocialPublisher` (log | meta Graph API). Posts only through `PublishToSocial` (first publish, once
  per car and account, JPEG copy for Instagram, tracked share link in the caption). Tokens use the `encrypted` cast.
- Custom domains: `CustomDomains` (TXT `_lotlink.{domain}` = `lotlink-verify={token}`, `DnsLookup` faked in tests);
  a verified domain's `/` renders the mini-site; Caddy asks `/internal/domains/allowed`. Enterprise (`custom_domain`).
- Finance hand-off: `FinancePartner` (log | http). `SubmitFinanceApplication` needs consent; applicant data is
  encrypted and never shown to lots; partner updates arrive at `/webhooks/finance` (HMAC-SHA256).
- `SecurityHeaders` sets CSP (nonce via `Vite::useCspNonce()`; keep inline scripts out of Blade), HSTS and framing
  rules; `/l/*` stays embeddable. Admin 2FA: `RequireAdminTwoFactor` + `Totp`. Config: `LOTLINK_CSP`, `ADMIN_2FA`.
- Account deletion: `DeleteAccount` (soft delete, 30-day grace, sign-in restores) then `AnonymiseAccount`.
- See `docs/security-review.md` and `loadtest/` (k6) before changing auth, headers or hot paths.

## Sharing and budgets (S6)

- Shares go through `CreateShareLink` and `/c/{code}` (never raw car URLs from share buttons), so
  clicks can be attributed. Share cards come only from `RenderShareCard` / `ShareCard`; call
  `RenderShareCard::refresh()` wherever something on the card changes. Prices on images use the
  Bricolage font (DM Sans has no ₦ glyph). Tests keep cards off (`LOTLINK_SHARE_CARDS=false`)
  unless they test them.
- Budget maths lives in `FinanceCalculator` (PHP) and `resources/js/lib/finance.ts`; change both
  together and keep `tests/Unit/FinanceCalculatorTest.php` matching the design. The server
  recomputes `max_price`; never trust the browser's. Rates are in `config('lotlink.finance')`.
- The service worker (`public/sw.js`) caches only built assets and Lot Manager pages; bump its
  `VERSION` when changing caching rules.

## Billing and spotlight (S7)

- Money paid through Paystack is `Billing\Models\Payment` (not Lot Manager's `OrderPayment`). It only
  changes state in `FulfilPayment`, which re-verifies with the `PaymentGateway` and checks the
  amount; never mark a payment paid from a redirect or webhook body. Webhooks are stored in
  `webhook_events` (unique body hash), so each delivery is handled once.
- `lots.plan_id` is the effective plan used by limits; `subscriptions` says how it's paid for.
  Downgrades go through `DowngradeToFree` (hides extra cars via the state machine, never deletes).
- Spotlights set `vehicles.spotlight_until` / `lots.featured_until` via `ActivateSpotlight`; use
  `save()` on vehicles so search re-indexes. Sponsored results come from `VehicleSearch::sponsored()`
  (both engines).
- Tests bind `Tests\Support\FakePaymentGateway` (`$this->payments`); never call Paystack.

## Leads and chat (S8)

- Every enquiry goes through `CaptureLead` (dedupes lot + buyer + car over 30 days, adds the buyer to
  the customer book, alerts the lot). New lead sources (offers, trade-ins, reservations) call it too.
- Chat messages only go through `SendMessage`: it updates read markers, moves a new lead to
  contacted on the lot's first reply and broadcasts `MessageSent`. Chat must keep working without
  Reverb (`useChat` polls when `VITE_REVERB_APP_KEY` is empty).
- Channel rules in `routes/channels.php` must match the page policies (`ConversationPolicy`).
- Notifications that go by WhatsApp/SMS or email pass their channels through
  `NotificationPreferences::filter()` with their type, and add `database` with a `toArray()`
  (`kind`, `text`, `url`) so they show in the notification centre.

## Offers and deals (S9)

- Offers, trade-ins and reservations live in `app/Domain/Deals`. Each goes through its Action
  (`MakeOffer`, `RespondToOffer`, `AnswerCounterOffer`, `SubmitTradeIn`, `ValueTradeIn`,
  `StartReservation`, `ActivateReservation`, `EndReservation`). These capture a lead, post a line
  into the lead's chat via `DealTimeline`, and notify the buyer (`DealUpdate`) or the lot (`DealAlert`).
- Buyer deposits (reservations, test drives) are `Billing\Models\Payment` rows with `user_id` = the
  buyer and purpose `reservation` / `deposit`. They are made by `BuyerCheckout` and only take effect in
  `FulfilPayment` (verified). The Billing page and invoices use `PaymentPurpose::billing()` only.
- One active reservation per car. `OrderLedger` never frees a car that has one. `CreateOrder`
  converts it for the same buyer and records the deposit as a Paystack order payment.
- Test drives with a deposit start as `awaiting_deposit` (holds the slot; `holdingSlot()` scope).
  Refunds go through `RefundDeposit` (check-in, cancel); a no-show keeps it.
- Offers and deposits are plan features (`Lot::takesOffers()`, `reservationDeposit()`,
  `testDriveDeposit()`); trade-ins are on every plan. Trade-in photos stay on the private `local` disk.

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
S6 Sharing + budgeting (MVP launch) ✅ · S7 Billing + spotlight ✅ · S8 Leads + chat ✅ · S9 Offers ✅ ·
S10 Lot Manager pro ✅ · S11 Location + analytics ✅ · S12 Trust + admin ✅ · S13 SEO ✅ · S14 Integrations ✅ (full release).

Still deferred: Sanctum API endpoints (the package is installed), Redis/Horizon (the database queue for now),
admin-editable finance rates and message templates, web push.
