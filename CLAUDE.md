# CLAUDE.md

LotLink: a multi-dealer car lot platform for Nigeria first (NGN, Paystack/Flutterwave, WhatsApp), configurable
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
- **Field rules**: build rules from `App\Domain\Support\Fields` (`personName`, `businessName`, `place`, `model`, `phone`
  (libphonenumber, current lot's region), `email`, `reference`, `code`, `money`, `count`, `text($max)`, `newPassword`, `ulid`),
  never bare `'string'`: every text field needs a type, a `max` within its column, and a pattern where the data has a shape.
  Money is whole naira through `Fields::cleanMoney()` (never strip all non-digits: "1500.50" must fail, not become 150,050).
  In Vue, every text input gets `v-field` (`directives/field.ts`, rules in `lib/fields.ts`, kept in step with PHP by
  `tests/Unit/FieldsTest.php`); plain wording for Laravel's own messages and field names is in `lang/en/validation.php`.
- **Enums** are PHP backed enums, cast on models. Add `@property` docblocks for new columns so
  Larastan knows the types.
- **Money**: unsigned bigint minor units (kobo) plus a `char(3)` currency. Never floats.
- **Time**: stored UTC, shown in `lots.timezone` (default Africa/Lagos).
- **Public IDs**: ULIDs (`ulid` column) in URLs; lots route by `slug`. Never expose auto-increment
  ids (models hide `id`).
- **Phone numbers**: always E.164 via `App\Domain\Support\PhoneNumber`; mask them in dealer UI
  until the customer engages. Users (and lot customers from the marketplace) may have **no phone** when they
  signed up by email: never assume `$user->phone`; `PhoneNumber::display()/mask()` accept null.
- **Sign-in**: one-time codes by WhatsApp (`SendOtp::run`, no SMS fallback unless `otp.sms_fallback`) or email
  (`SendOtp::toEmail` / `VerifyOtp::forEmail`) always work and create accounts. Optional extras: a password (`SetPassword`,
  `LogInWithPassword` by email or phone; never creates accounts) and Google (`SignInWithGoogle` via Socialite; links by
  email only when Google verified it). Forgotten passwords: `PasswordResetController` (broker, `ResetPasswordLink` mail, `SetPassword::reset()`). Every sign-in path calls `User::reopenForSignIn()`. Admins must keep a password.
  Every sign-in path passes the "Keep me signed in for a week" choice to `Auth::login($user, $remember)` (cookie length:
  `auth.guards.web.remember`); never hard-code `remember: true`.
- **States**: lots' `state` is one of `config('lotlink.regions')` (36 states + FCT); normalise input with `Regions::normalize()`.
  Admins create lots for owners with `OnboardLot`.

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
- CAC lookups: `CompanyRegistry` (none | dojah; `COMPANY_REGISTRY_DRIVER`) through `CheckCompanyRegistry`, queued by
  `SubmitLotVerification` as `LookUpCompany` (retries when the registry is down). It stores the registry's answer and a
  `NameMatch` score on the verification (`registrySummary()` / `registryConcern()` in the admin queue) and never decides.
- Inspections: `SaveInspection` (40 checks in `InspectionChecklist`, score = pass 1 / advisory ½ / fail 0)
  sets `vehicles.inspection_id`; a dealer check never replaces a signed independent one. PDFs via `InspectionPdf`.
- Reviews only through `SubmitReview` (completed visits, one per appointment, 14-day edits) and `ReplyToReview`
  (once). Call `RefreshLotRating::run()` whenever a review's visibility changes; ratings show from 3 reviews.
- Reports only through `SubmitReport`. Held cars (`vehicles.held_at`) are off `Vehicle::marketplace()` and
  can't be published; only `ModerateListing` (admin) clears them. Fraud signals come from `DetectFraudSignals`
  (on publish and price drops) and never hide anything themselves.
- Filament closures are injected by parameter name: use `$query`, `$record`, `$state`, `$search`.
- Every admin resource uses `Concerns\AdminsOnly`: authorisation by admin role (never the dealer-side model policies, which
  gave admins 403s and a membership query per row) and record URLs built from the resource's `$recordRouteKeyName`.
  Put new admin pages in one of the panel's navigation groups (order set in `AdminPanelProvider`). Menu badges and the review
  queue read `AdminCounters` (30-second cache, cleared when a queued model changes); don't add per-page count queries.
- "Log in as" goes through `Impersonation` (audit-logged); never log in as another user any other way. While it's on, every
  `AuditLog` row gets `impersonator_id`; "Back to admin" and Sign out end it with a full page load (`Inertia::location`),
  and it clears the panel's `password_hash_*` session key on both switches. The bar is `components/SupportViewBar.vue` (in every layout).

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
- Car loans (`app/Domain/Finance`): lenders are accounts (`Lender`, team in `lender_members`, `LenderRole` admin/officer). They sign up
  at `/lenders` (`ApplyToBeLender`, licence on the private disk) or an admin onboards them (`OnboardLender`); only `DecideLender` changes
  their status and only active lenders are offered to buyers (`Lender::lendsFor()`: state, amount, deposit, term). Profile/product/API
  settings only through `SaveLender` (rules in `LenderRules`). The buyer picks a lender and consents to that one (`SubmitFinanceApplication`);
  it reaches the lender through `LenderConnections::for()` (portal | api | demo). Every status change goes through
  `UpdateFinanceApplication` (row lock, `FinanceStatus::canMoveTo()`, thread line, audit, buyer `FinanceUpdate` / lender `LenderAlert`),
  whether from the portal, the lender's signed webhook `/webhooks/finance/{lender}` (its own `webhook_secret`) or a buyer withdrawing.
  Messages and documents only through `SendFinanceMessage` (private disk, signed `finance.file` links). The portal is `/lender/{lender}`
  (`lender.member` → `SetCurrentLender`, `scopeBindings()`); admins see it only in /admin (Car loans). Applicant data is encrypted, shown
  only to the chosen lender (`FinancePresenter` with the lender side), never to lots, never in /admin. The lot hears only through
  `FinanceLeadNotice`: a `LeadSource::Finance` lead, chat lines and `DealAlert`s when the buyer applies, is pre-approved, approved
  and paid for. Never income, commitments, employer, messages, declines or withdrawals.
- Lenders may be commercial banks, microfinance banks, finance companies, licensed money lenders or individuals (`LenderType`); say
  "lender", not "bank", in the UI. Loans start on LotLink and continue with the lender: after a pre-approval/approval the buyer sees
  the lender's `next_steps` (application's, else the lender's default) and contact details; KYC, agreement and payment happen off-platform.
  `finance:prune` (daily) clears applicants' details, messages and documents 24 months after an application closes (the Privacy Policy says so).
- `SecurityHeaders` sets CSP (nonce via `Vite::useCspNonce()`; keep inline scripts out of Blade), HSTS and framing
  rules; `/l/*` stays embeddable. Admin 2FA: `RequireAdminTwoFactor` + `Totp`. Config: `LOTLINK_CSP`, `ADMIN_2FA`.
- Account deletion: `DeleteAccount` (soft delete, 30-day grace, sign-in restores) then `AnonymiseAccount`.
- See `docs/security-review.md` and `loadtest/` (k6) before changing auth, headers or hot paths.

## Legal (whole platform)

- Terms of Use, Privacy Policy (NDPA 2023 / GAID 2025), Lender Terms and Security and safety live in `resources/legal/*.md`, rendered by
  `Legal\LegalDocuments` (raw HTML stripped; `{company}` etc. from `config('lotlink.legal')`) at `/terms`, `/privacy`, `/lender-terms`,
  `/security`; `/.well-known/security.txt`. Bump `lotlink.legal.versions.*` when a document changes materially. When you change what the
  platform does with data, money or loans, update the matching document in the same change.
- Acceptance only through `Legal\Actions\AcceptTerms` (`legal_acceptances` rows with version, IP and browser; `users.terms_version`).
  New accounts accept on the sign-in page (`VerifyOtp`, `SignInWithGoogle`); everyone else meets `terms.accepted` (`EnsureTermsAccepted`:
  web redirect to `/legal/accept`, API 403 `terms_not_accepted`) — never for admins, never accepted during "Log in as". Lenders' admins
  accept the Lender Terms (`forLender()`; `SetCurrentLender` holds applications until they do). Every layout shows `LegalFooter.vue`.
  Tests: `UserFactory` accepts by default (`withoutTerms()`), `LenderFixtures::lender()` too.

## Support desk

- Lots' tickets to LotLink live in `app/Domain/Helpdesk` (`SupportTicket` is lot-owned; `SupportMessage` hangs off it).
  Open with `OpenTicket`, add messages only with `ReplyToTicket::fromLot()` / `fromAdmin()` (internal notes never
  reach the lot), change status/assignee with `ChangeTicketStatus` (audit-logged). Dealer routes bind `{supportTicket}`
  through `Lot::supportTickets()`. Attachments use the private `local` disk behind signed `support.attachment` links.
- Admins show to lots as "{first name}, LotLink Support". Unread = `lot_read_at` / `admin_read_at` cleared by the other side's reply.

## Sharing and budgets (S6)

- Shares go through `CreateShareLink` and `/c/{code}` (never raw car URLs from share buttons), so
  clicks can be attributed. Share cards come only from `RenderShareCard` / `ShareCard`; call
  `RenderShareCard::refresh()` wherever something on the card changes. Prices on images use the
  Bricolage font (DM Sans has no ₦ glyph). Tests keep cards off (`LOTLINK_SHARE_CARDS=false`)
  unless they test them.
- Budget maths lives in `FinanceCalculator` (PHP) and `resources/js/lib/finance.ts`; change both
  together and keep `tests/Unit/FinanceCalculatorTest.php` matching the design. The server
  recomputes `max_price`; never trust the browser's. Rates are in `config('lotlink.finance')`: the
  file holds defaults and `FinanceRates::apply()` lays the admin's overrides (/admin → Finance rates,
  `platform_settings`) over it at boot and before each queued job, so keep reading config.
- The service worker (`public/sw.js`) caches only built assets and Lot Manager pages; bump its
  `VERSION` when changing caching rules.

## Billing and spotlight (S7)

- Money paid through Paystack or Flutterwave is `Billing\Models\Payment` (not Lot Manager's `OrderPayment`). It only
  changes state in `FulfilPayment`, which re-verifies with the provider that took it and checks the
  amount; never mark a payment paid from a redirect or webhook body. Webhooks are stored in
  `webhook_events` (unique body hash), so each delivery is handled once.
- Providers: `PaymentGateways` (`for($payment->provider)` / `for($subscription->provider)` for anything existing; the injected
  `PaymentGateway` is the admin's choice for new payments, /admin → Settings → Payments). Never use the injected gateway to
  verify, refund or cancel an existing record. Renewals are recorded only through `RecordRenewal` (both webhooks); Flutterwave
  charges are always verified with its API (its webhook only has a shared hash). Plans: `Plan::codeFor($provider)`.
- Plan prices change only through `ChangePlanPrice` (updates each provider's plan first, then the plan; optionally notifies
  current subscribers). Never edit `plans.price` directly, or the providers and LotLink will disagree and checkouts fail.
- `lots.plan_id` is the effective plan used by limits; `subscriptions` says how it's paid for.
  Downgrades go through `DowngradeToFree` (hides extra cars via the state machine, never deletes).
- Spotlights set `vehicles.spotlight_until` / `lots.featured_until` via `ActivateSpotlight`; use
  `save()` on vehicles so search re-indexes. Sponsored results come from `VehicleSearch::sponsored()`
  (both engines).
- Tests bind `Tests\Support\FakePaymentGateway` (`$this->payments` for Paystack, `$this->flutterwave`); never call a real provider.

## Adverts

- Lots' paid banners live in `app/Domain/Advertising` (`AdCampaign`, lot-owned). Placements: `home_banner`, `search_banner`
  (targetable by make, body type, city). Book with `CreateAdCampaign` (slot check under a lock via `AdSchedule`, image via
  `AdImage`, a `Payment` with purpose `advert`); `FulfilPayment` calls `SubmitAdCampaign` (in review); admins use
  `ReviewAdCampaign` (approve schedules it, reject refunds, remove takes down). Serve only through `AdServer`; count views and
  clicks only through `AdController` (`ads.seen`/`ads.click`, once per visit, no bots or lot staff). Never run an advert unreviewed.
  Prices and slots (banners and spotlights) are config defaults overlaid by `AdvertPricing` (/admin → Advert prices), like
  `FinanceRates`: keep reading them through `AdSchedule` / `SpotlightPricing` / `AdPlacement::slots()`.

## Leads and chat (S8)

- Every enquiry goes through `CaptureLead` (dedupes lot + buyer + car over 30 days, adds the buyer to
  the customer book, alerts the lot). New lead sources (offers, trade-ins, reservations) call it too.
- Lead quick replies (`LeadController::message()` presets: location, similar, bank, inspection) build the text on the server
  from live data (the inspection one uses the car's current `inspection` and the public `inspections.pdf` link).
- Chat messages only go through `SendMessage`: it updates read markers, moves a new lead to
  contacted on the lot's first reply and broadcasts `MessageSent`. Chat must keep working without
  Reverb (`useChat` polls when `VITE_REVERB_APP_KEY` is empty).
- Channel rules in `routes/channels.php` must match the page policies (`ConversationPolicy`).
- Notifications that go by WhatsApp/SMS or email pass their channels through
  `NotificationPreferences::filter()` with their type, and add `database` with a `toArray()`
  (`kind`, `text`, `url`) so they show in the notification centre.
- Web push lives in `app/Domain/Push`. `filter()` adds the `push` channel for people with a device (`PushSubscription`,
  saved by `SavePushSubscription`) unless that type's push is off; `PushChannel` sends `toPush()` or else the `toArray()`
  through `PushGateway` (webpush with VAPID keys from `push:vapid` | log; tests use `$this->push`). Chat is pushed on send
  (`ChatMessagePush` from `SendMessage`); `UnreadMessages` drops `push`. Sign-out deletes that device's subscription.
  `public/sw.js` shows pushes and opens their URL on tap.

## Offers and deals (S9)

- Offers, trade-ins and reservations live in `app/Domain/Deals`. Each goes through its Action
  (`MakeOffer`, `RespondToOffer`, `AnswerCounterOffer`, `SubmitTradeIn`, `ValueTradeIn`,
  `StartReservation`, `ActivateReservation`, `EndReservation`). These capture a lead, post a line
  into the lead's chat via `DealTimeline`, and notify the buyer (`DealUpdate`) or the lot (`DealAlert`).
- **LotLink never receives money for car transactions.** Buyers pay the lot directly (transfer, cash, POS). The only
  payments to LotLink are the lot's own: subscriptions/renewals, spotlights and featured-lot promotions (`PaymentPurpose::billing()`).
  Never add a buyer checkout; `PaymentPurpose::Reservation`/`Deposit` exist only for legacy payments in `FulfilPayment`.
- Bank details: `LotBankAccount` (owner-managed via `SaveBankAccount`: audit-logged, owner and managers notified). Share them with
  `shareText()` / `components/BankDetailsCard.vue` (orders, order tracking, chat preset, reservation page).
- Reservations: `StartReservation` records a pending request (reference `RES-…`, `pay_by` 12h); the buyer transfers to the lot;
  owners/managers confirm with `ActivateReservation` (holds the car, lapses rival requests). `ReservationDeposits` records
  buyer-sent, decline, lapse and refunded; the lot refunds from its own account (`refund_due`). One active reservation per car;
  `OrderLedger` never frees a car that has one. `CreateOrder` converts it for the same buyer and records the deposit as a transfer.
- No test-drive deposits: bookings are confirmed or pending, never `awaiting_deposit` (kept only for old rows).
- Offers and reservations are plan features (`Lot::takesOffers()`, `reservationDeposit()`, which also needs a bank
  account); trade-ins are on every plan. Trade-in photos stay on the private `local` disk.
- Owners can switch off buyer trade-ins and car loan applications (`lots.accepts_trade_ins` / `accepts_finance`, Settings → Offers and
  deals). Check `Lot::takesTradeIns()` / `takesFinance()`: `SubmitTradeIn` and `SubmitFinanceApplication` refuse, the pages hide the
  buttons (`DealsPresenter` `trade_ins`/`finance`, `MarketplacePresenter::lot()` `trade_ins`). The monthly estimate always shows;
  dealer-entered trade-ins on Lot Manager orders are unaffected.

## Engagement (lots)

- `app/Domain/Engagement`. Admin broadcasts (`Broadcast`, Filament `BroadcastResource`): audience from `BroadcastAudience` (one message per
  person), sent only through `SendBroadcast` (queued `DeliverBroadcast`, idempotent; scheduled ones by `engagement:send-broadcasts`).
  Automated emails: rules and defaults in `EngagementRules::RULES`, admin overrides in `platform_settings` (/admin → Automated emails),
  run hourly by `RunEngagementRules` (`engagement:run`) at each lot's local send hour, with per-lot cooldowns.
- Every message is an `EngagementMessage` (cooldown record and click tracking via `/e/{ulid}`) delivered by `EngagementNotice`
  (types `news` / `nudges` in `NotificationPreferences`; emails carry a signed unsubscribe link). Add new rules there, never ad-hoc mailers.
- `users.last_seen_at` is kept by `TouchLastSeen` (web + API, 15-minute granularity, not while impersonating).

## Mobile API

- `/api/v1` (`routes/api.php`, `app/Http/Controllers/Api/V1`), Sanctum bearer tokens (`sanctum.expiration`, 90 days). Documented in
  `docs/api.md`; keep it in step. Controllers call the same Actions as the web (`SaveCar`, `StartConversation`, `SendMessage`,
  `BookAppointment`, `UpdateLead`, `LogInWithPassword::attempt()`…) and shape output with `MarketplacePresenter` / `ApiPresenter` /
  `LeadPresenter` / `VehicleResource`: never `toArray()`, internal ids, costs or profit. Dealer routes use `lot.member` + `scopeBindings()`
  like the web, and every new one needs a tenancy test (`tests/Feature/Api`). `api/*` always renders JSON errors.
- Conversation `messages()` is ordered oldest first: use `reorder()` before asking for the latest.

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
- Photos (max `Vehicle::MAX_PHOTOS` = 12): browser → `MediaUploads` target (pre-signed R2 PUT, or the app's upload endpoint on a
  local disk) → `AttachVehicleMedia` → `ProcessVehicleMedia` (queue `media`) → WebP 1600/800/400
  on the media disk. Originals never reach the public disk.
  `RotateVehicleMedia` writes renditions under new names (`{media}-{rand}-{w}.webp`, the CDN caches forever),
  so always derive files from `VehicleMedia::variantPaths()` / `urls()`, not `variantPath()`. Buyers see photos in
  `PhotoViewer.vue` (quick look via `cars.photos`, zoom on the car page), using the `full` (1600) URL.
- `new arrival` (7 days) and `ageing` (45 days) are computed from `listed_at`, not stored.

## Marketplace (S3)

- Buyers only see `Vehicle::marketplace()`: available or reserved cars at active lots. Public
  pages use `MarketplacePresenter`, never model `toArray()`, so dealer-only fields don't leak
  (VIN shows as its last four characters).
- Search goes through the `VehicleSearch` interface: `MeilisearchVehicleSearch` (Scout,
  production) or `DatabaseVehicleSearch` (SCOUT_DRIVER=null, Laragon). Any new filter must be
  added to both and to the shared engine tests in `tests/Feature/Marketplace/VehicleSearchTest.php`
  (set `MEILISEARCH_TEST_HOST` to run the Meilisearch side locally).
  Meilisearch must never take the marketplace or dealers down: `MeilisearchVehicleSearch` falls back to
  `DatabaseVehicleSearch` on any Meilisearch error (sponsored cars are just left out), and `Vehicle::syncMakeSearchable()` /
  `syncRemoveFromSearch()` report index failures instead of failing the save (`scout:import` catches the index up).
- Search is live everywhere. The marketplace box (`components/marketplace/LiveSearchInput.vue`) suggests as people type from
  `/search/suggest` (`SearchSuggestions`: makes/models/places/lots from a small cached catalogue of what's on sale, plus cars from
  `VehicleSearch`; `throttle:suggest`); the search page and `FiltersPanel live` apply typing and filter changes with partial reloads
  (`only` the result props). Dealer lists use `composables/useLiveReload` (partial reloads, 250 ms pause). Wrap props a live reload
  doesn't need in closures so they aren't computed; Filament tables and global search wait 250 ms.
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
  business-initiated WhatsApp messages need a new approved template (list in README) and an entry in
  `MessageCatalogue::TEMPLATES`. `Messenger` runs every message through `MessageCatalogue::apply()`
  (admin's template version, language, SMS wording with {1}…/{link}, on/off), so never call the gateways directly.
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

Queues: the database queue on Laragon and in tests; Redis + Horizon on the server (`QUEUE_CONNECTION=redis` registers Horizon in
`AppServiceProvider`; `HorizonServiceProvider` gates `/horizon` to admins with no local bypass; supervisors `app` and `media` in
`config/horizon.php`). Keep every queue's `retry_after` above the longest job `timeout` (broadcasts: 900 s).
