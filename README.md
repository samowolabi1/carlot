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

**Sprint S14 (Integrations and full release) ✅**

| Area | What works |
| --- | --- |
| Facebook and Instagram | Settings → **Social media**: connect a Facebook Page (and the Instagram Business account linked to it) through Meta. Every newly published car is posted once to each, with its cover photo (a JPEG copy for Instagram), price, key specs and a tracked link, so clicks show in Analytics by platform. Auto-post can be turned off per account; failed posts show why and can be retried. `SOCIAL_DRIVER=log` (default) gives a demo Page and writes posts to the log. |
| Custom domains | Mini-site & QR → **Custom domain** (Enterprise, owners): enter a domain, add a CNAME and a TXT record, press Check. Once verified, the domain opens the lot's mini-site. For HTTPS, Caddy's on-demand TLS asks `/internal/domains/allowed` before issuing a certificate, so only LotLink and verified lot domains get one. |
| Finance pre-qualification | On a car's "Pay monthly" card, **Check if you qualify**: deposit, term, income, commitments and work, plus an explicit consent box naming the lender. The details go to the finance partner (`FINANCE_PARTNER_DRIVER=log` is a demo lender using the budget rule; `http` posts to a partner API). The buyer sees the answer under Account → Finance applications and gets a notification; later updates arrive by signed webhook. Lots never see the buyer's financial details. |
| Security | New security headers (CSP with per-request nonces in production, HSTS, no framing except mini-sites, nosniff, referrer and permissions policies), secure session cookies in production, trusted-proxy support, and **two-step sign-in for admins** with an authenticator app and recovery codes. Full write-up in `docs/security-review.md`. |
| Privacy | Account → Privacy and my data → **Delete my account**: closes it now; personal data is removed after 30 days (`accounts:anonymise`, daily); signing in before then cancels it. Lots keep their own sales records. |
| Load test | `loadtest/marketplace.js` (k6) ramps 100 buyers through home, search, landing pages, cars, lots and slots with the TDD's thresholds; `loadtest/smoke.mjs` is a quick check without k6. Results: see `loadtest/README.md`. |
| Quality | 460 tests, including OAuth state checks, encrypted tokens, one post per car, failure and retry, DNS verification and the TLS ask endpoint, consent and signed finance webhooks, CSP nonces, the RFC 6238 test vector, the admin 2FA flow, and account deletion and restore. |

With S14 every sprint in the TDD plan is built. Still open, by choice: Redis/Horizon (the database queue is enough for launch) and automatic CAC lookups. Admin-editable finance rates and message templates, web push and the mobile API (`docs/api.md`) have since been added.

**Sprint S13 (SEO and growth tools) ✅**

| Area | What works |
| --- | --- |
| SEO landing pages | `/cars/{city}`, `/cars/{make}`, `/cars/{make}/{model}`, `/cars/{make}/{city}` and `/cars/{make}/{model}/{city}` (e.g. `/cars/toyota/camry/ikeja`): the search page with a unique title, heading and intro written from live stock (count, lots, price range), links to narrower pages, a canonical URL, and `noindex` when there is no stock. |
| Structured data | Car pages carry schema.org `Car` + `Offer` (price, currency, availability, seller); lot pages carry `AutoDealer` (address, map point, opening hours, rating once shown). No full VIN or dealer-only data. |
| Sitemap | `sitemap:generate` (nightly at 03:00) writes the home and search pages, landing pages with stock, live lots and marketplace cars; served at `/sitemap.xml`. `/robots.txt` points to it in production and blocks everything elsewhere, so test sites stay out of Google. |
| Saved searches (12) | **Save search** on any search with filters. Saved → Searches lists them with the alert channel (WhatsApp, email or in-app). When a matching car is published, or a car drops into the search, the buyer hears about it, at most once per search every 6 hours. Distance and "near me" aren't saved: we don't keep buyers' locations. Saved now has Cars · Searches · Lots tabs. |
| Price-drop alerts | Buyers who saved a car are told when its price drops ("now ₦14,200,000, ₦300,000 less"). The lot's own team is never alerted about its own cars. Both alerts follow the new "Price drops and saved-search matches" notification setting. |
| Mini-site and QR | **Mini-site & QR** in the sidebar: the lot's link with copy and WhatsApp share, branding, a live preview, an A4 or A3 gate poster PDF, and a sheet of windscreen stickers (one QR per car on the lot). Every QR is a tracked share link, so scans show in Analytics as "QR code". Custom domains show as Enterprise · Soon. |
| Bulk import | Stock → **Bulk import** (Enterprise, as in the spec; owners and managers): download the .xlsx template, fill in up to 500 cars, upload .xlsx or .csv. Each good row becomes a draft through the add-car steps (labels like "Tokunbo", "Automatic" and "₦12,500,000" are understood); bad rows are listed with what to fix; a VIN already in stock is skipped. |
| Quality | 447 tests, including every landing-page shape, 404s and noindex, JSON-LD content and escaping, the sitemap, saved-search matching, the 6-hour limit, alert channels, price drops, poster and sticker PDFs with tracked QR links, and imports with bad rows, duplicates, plan and role gating. |

**Sprint S12 (Trust and admin) ✅**

| Area | What works |
| --- | --- |
| Lot verification | The last onboarding step (and Settings → Verification) takes the CAC number, the CAC certificate and a photo of the lot frontage. The files stay private: only the owner and admins open them, through short-lived links. An admin approves (the lot gets the **Verified lot** badge) or sends it back with a note; the owner is told either way. Lots can list cars while they wait. |
| Inspection reports | Stock → **Inspect** on any car: a 40-point checklist in 7 groups (engine, gearbox, body, electrics, tyres/brakes/suspension, interior, documents). Every check starts as a pass, so you only change what isn't; a fail needs a note, and photos can be added. The score counts an advisory as half. Buyers see the score, the issues and photos on the car page, an **Inspected** tag in search and compare, and a PDF of the full report. A registered independent inspector (set by an admin) can sign a report from the car page; it shows **Independently inspected**, and the lot's own later checks don't replace it. |
| Reviews (17) | Two hours after a visit is marked complete, the buyer gets a WhatsApp/SMS invite (`reviews:invite`, every 15 minutes) with a link that works without signing in; past bookings also show **Leave a review**. Stars, "what went well" chips and a comment; editable for 14 days; shown as first name and initial. The lot's owner and managers are told, and can reply once from **Reviews** in the sidebar. The star rating shows on the lot page, mini-site and car pages once there are 3 reviews. |
| Reports | **Report this listing**, **Report this lot**, **Report this review** and **Report** on chat messages. One report per person per item. A review a buyer reports is hidden until an admin looks (a lot reporting a review of itself doesn't hide it). A car with 3 open reports comes off LotLink until an admin decides; the lot sees why and can't republish it meanwhile. |
| Fraud signals | When a car is published or its price drops: the same VIN on another lot, a cover photo matching another lot's (a perceptual hash, so re-saved copies still match), a price under 40% of the pricing-guide median, or a lot under 30 days old publishing more than 30 cars in a day. Signals go to the admin queue; nothing is hidden automatically. |
| Support desk | **Help & support** in the dealer sidebar (any team member): open a ticket with a topic, urgency, optional car and a screenshot or PDF; follow the conversation with LotLink; mark it solved or reopen it. A badge shows unread replies, and replies also arrive as a notification and email. In `/admin` → **Support tickets** (badge = tickets needing a reply, also on the dashboard queue): filter by status, priority, topic or "assigned to me"; open a ticket to reply (then wait for the lot, resolve or close), add internal notes the lot never sees, assign it and change priority. Lots see admins as "Ada, LotLink Support". Status, priority and assignment changes are audit-logged. |
| Advertise | **Advertise** in the dealer sidebar (owners and managers) lists every paid promotion: **Homepage banner** (a rotating banner at the top of the home page, up to 5 lots at once), **Search banner** (among search results and on city/make pages, optionally aimed at a make, body type or city; aimed banners win when they fit the search), plus links to car spotlights and featured lots. A banner has a headline, a short line, a button, and opens the lot page or one car; the image is uploaded (cropped to 1600×600 or 1200×300 and re-encoded as WebP) or taken from the car's photo, with a live preview. Pick 7, 14 or 30 days and a start date (up to 60 days ahead); full slots suggest the next free start. It's paid to LotLink like a spotlight, then **checked by an admin** (`/admin` → Adverts, badge and dashboard queue): approve (runs from the date asked, or from approval so no days are lost), reject (refunded in full, with a note), or take down. Views (once on screen) and clicks are counted once per visit, not for bots or the lot's own staff, and shown with the click rate. Prices and slots: **`/admin` → Settings → Advert prices** sets the 7/14/30-day price of homepage banners, search banners, car spotlights and featured lots (with the price a day), and how many banners run at once; new bookings use them straight away, paid ones keep their price, changes are audit-logged, and **Reset to defaults** goes back to `config/lotlink.php`. |
| Lots in every state | Lots pick their state from Nigeria's 36 states and the FCT (`config('lotlink.regions')`, swappable per market); map pins and free text like "Lagos State" or "Abuja" are matched to the list. Buyers can filter search by state (both search engines), saved searches keep it, and admins filter lots by state. |
| Admin onboarding | `/admin` → Lots → **Onboard a lot**: owner's name and how they'll sign in (WhatsApp number or email), lot name, state, area, address, buyers' phone, plan, and optionally approve it on the spot. It creates (or reuses) the owner's account and the lot, records who onboarded it (filter "Onboarded by LotLink"), audit-logs it, and welcomes the owner by email or WhatsApp (`lot_welcome` template) with how to sign in. |
| Admin settings | **Settings → Finance rates**: the affordability share, interest rate, default deposit and loan length, the loan lengths buyers can pick, and the cost-of-ownership figures (insurance, papers, fuel price, distance, fuel economy by engine size, servicing by car age), with a live preview. Saved rates apply at once to "What can I afford?", "From ₦X/mo" and the running-costs card; **Reset to defaults** goes back to `config/lotlink.php`. **Settings → Message templates**: every WhatsApp/SMS message the app sends. Per message: which approved Meta template and language to use (so a newly approved version can replace the old one), the SMS wording with placeholders (`{1}`, `{2}`… for the variables, `{link}` for the button's URL; checked before saving, with a preview and SMS length), on/off (sign-in codes and staff invitations stay on), and **Send a test**. Every change is audit-logged. |
| Admin (A1) | The dashboard shows the review queue (lots to verify, flagged listings, reports, reported reviews) and platform metrics for the last 30 days (active lots, live listings, new users, bookings, recorded sales, MRR, churn). Admins approve or reject verifications, hide or approve listings, message the lots involved, restore or remove reviews, close reports, browse every listing and the audit log, mark users as inspectors, and **Log in as** a user for support (both ends go in the audit log; a banner shows "Back to admin"). |
| Quality | 432 tests, including verification files and decisions, the checklist score and PDF, independent reports, review invites, editing and replies, the 3-review rule, report limits and auto-hold, every fraud signal, the admin queue actions, impersonation, and tenancy checks for verifications, inspections and reviews. |

Not in S12: admin 2FA (still deferred), admin-editable finance rates and message templates (they stay in `config/lotlink.php` and Meta), and automatic CAC lookups (no registry API yet).

**Sprint S11 (Location and analytics) ✅**

| Area | What works |
| --- | --- |
| Live location (14) | A buyer with a visit in the next 3 hours can tap **Share live location** on their booking for 15, 30, 60 or 120 minutes. The lot's team gets a notification and follows along from the calendar: a map, the distance and a rough time, and a call button. A team member can also share their location with the buyer, who gets a WhatsApp link. Only the two sides of the booking can see it. Only the latest point is stored, and it is cleared when sharing stops, runs out (`location:end-expired`, every minute), or the visit is completed, marked a no-show or cancelled. The browser sends a point every 10 seconds while the page is open; phones pause sharing when locked. With a Google Maps browser key the map is Google's; without one, a simple map shows both points. |
| Event tracking | Car views (once per visitor per 30 minutes, not bots or link previews, not the lot's own staff), saves, shares, leads and bookings are recorded by a queued `TrackEvent`. Views that came from a share link are credited to that platform. `stats:rollup` (hourly) turns them into daily per-car numbers in each lot's timezone; raw events are kept 90 days. |
| Analytics (D8) | **Analytics** in the dealer sidebar (owners and managers): listing views, leads, visits booked, cars sold and average days in stock, each with the change on the previous period. Also the view → lead → visit → sale funnel, views per day, and every car's views and leads. Cars are flagged "Ageing" at 45 days and "Stale" at 90, with **Reduce price** and **Spotlight** shortcuts. On Pro: where leads came from, shares by platform, staff (leads, average first reply, visits, cars sold) and CSV exports of stock, leads and sales. Basic analytics come with Starter. |
| Pricing guide | On a car's price step, dealers see the median and range of what the same make, model and year (±1) is listed for across LotLink, when there are 5 or more to compare. Analytics flags cars priced well above similar ones. |
| Quality | 404 tests, including visitor counting and bot filtering, rollups across midnight in Lagos, analytics figures, plan and role gating, CSV exports, the pricing guide, and live location access, expiry and clearing. |

**Sprint S10 (Lot Manager Pro) ✅**

| Area | What works |
| --- | --- |
| Instalment plans | On an order, owners and managers split what is left into up to 24 dated amounts (weekly, every 2 weeks or monthly). Payments go to the oldest instalment first, and voids and refunds put the schedule right again. Customers who agreed to WhatsApp get a reminder 3 days before and on the day, from 09:00 lot time (`manager:instalment-reminders`). Unpaid instalments turn overdue after their date (`manager:mark-overdue`) and show on **Today** and in the balances report. The tracking page shows the plan and the next amount due. LotLink doesn't lend money: the plan is the lot's own arrangement. |
| Papers and handover | Every order has a checklist: customs papers, proof of ownership and plate number (required), plus registration and spare key. You can add your own items. Each item can be marked received or handed over and have a scan attached; scans are private. "Papers are ready" waits for the required items. Handing over the car marks everything received as handed over. The customer sees the checklist on the tracking page. |
| Car costs and profit | **Costs** on each car in Stock records the purchase price and extras (clearing, repairs, transport, cleaning), with an optional receipt. The order page shows profit (agreed price − discount − costs). Only owners and managers see costs; they never appear on customer pages, receipts or the API. |
| Daily summary | At 19:00 lot time the owner gets one WhatsApp message covering the day's walk-ins, new orders, money received by method, balances still owed and overdue instalments, with a link to the report (`manager:daily-summary`). |
| Reports | **Lot Manager → Reports**: sales by month, outstanding balances, walk-ins by source with conversion, and staff performance, for the last 30 or 90 days, this year or last year. Owners and managers only. Profit, the staff report and Excel export are Pro. |
| Referrals | **Billing → Refer a lot**: each lot has a code and a link (`/dealer/start?ref=CODE`). When a lot that signed up with it pays for its first plan, the referrer gets a free month. It is added to the end of their trial or plan; a lot on Free gets a month of Starter. |
| Plan gating | Instalments and the daily summary from Starter; costs, profit, the staff report and Excel export on Pro (TDD M19). |
| Quality | 390 tests, including instalment allocation, voids, overdue marking and reminders in lot time, the papers rule, the sales role and costs, report figures, Excel export, the daily summary and referral rewards. |

**Sprint S9 (Offers and deals) ✅**

| Area | What works |
| --- | --- |
| Offers (09) | "Offer" on negotiable cars. The offer must be between half and the full asking price. The sheet shows where it sits among similar LotLink listings. A new offer replaces the buyer's open one. The lot accepts, declines or counters, and the buyer can accept a counter ("Accept and reserve"), decline it or counter again. Anything unanswered expires after 48 hours (`offers:expire`, hourly). Every step appears in the lead's chat, and accepting moves the lead to Negotiating. |
| Trade-ins (10) | "Trade in my car" on car and lot pages takes the make, model, year, mileage, condition, notes and up to 8 photos. Photos are re-encoded and kept private; the lot sees them through short-lived signed links. The lot sends a low–high estimate, and the buyer gets it on WhatsApp and can accept it. A valued trade-in can be added to a Lot Manager order, which reduces the balance. Booking a trade-in visit links to it. |
| Reservations (11) | "Reserve with deposit": the buyer picks 24, 48 or 72 hours and asks to reserve. They see the lot's bank details, the deposit and a reference (`RES-…`) to transfer **directly to the lot**: LotLink never touches the money. "I've sent the transfer" nudges the lot. Owners and managers confirm **Deposit received** in Offers & trade-ins → Reservations, and the car is held from then; other buyers' requests for the car lapse. Requests the lot doesn't confirm within 12 hours lapse (`reservations:expire`). When a hold ends or the lot cancels, the lot refunds the buyer from its own account (per its refund policy) and marks it refunded. An order for the same buyer converts the reservation and records the deposit as a bank transfer. |
| Bank details | **Settings → Bank details** (owner only; up to 3 accounts, one shown first): the accounts customers pay into. Every change is audit-logged and the owner and managers are told. Staff share them from a Lot Manager order (**Share bank details**: copy, or WhatsApp with the balance and order number as reference), from a lead's chat (**Send bank details**), and customers see them on their order tracking page and reservation page, with a warning to only pay the account shown. |
| Dealer page (D7) | **Offers & trade-ins** in the sidebar (with a badge) has tabs for offers (Accept / Counter / Decline, with a nudge on old stock), trade-ins (estimate, "Ask for more photos") and reservations (Create order, Cancel and refund). |
| Buyer page (19) | **Bookings and offers** lists counters to answer, accepted offers, reservations, valuations and past deals. |
| Settings | **Settings → Offers and reservations**: take offers on/off, the reservation deposit (needs bank details) and refund on expiry. Offers and reservations are Pro features (spec). LotLink takes no test-drive deposits. |
| Money | **LotLink never receives money for cars.** Buyers pay lots directly by transfer, cash or POS, recorded in Lot Manager. The only payments to LotLink are the lot's own: subscriptions and renewals, car spotlights, featured-lot promotions and banner adverts (Billing). |
| Quality | 365 tests, including offer limits and the counter flow, expiry, private photos, reservation races and refunds, conversion into orders, deposits, the webhook path, settings and tenancy. |

**Sprint S8 (Leads and chat) ✅**

| Area | What works |
| --- | --- |
| Lead capture (M11) | Every chat, booking, WhatsApp tap and call tap from a signed-in buyer becomes a lead. The same buyer asking about the same car within 30 days stays one lead. The buyer is added to the lot's customer book (matched by phone), and everyone at the lot gets a notification. A booking moves the lead to "Test drive". Delivering a car in Lot Manager marks that buyer's lead Won and closes other open leads on the car as Lost ("Car sold"). |
| Leads board | `/dealer/{lot}/leads`: New, Contacted, Test drive, Negotiating and Won columns. Drag cards between columns, search by name, phone or car, and filter to "Mine" or "Unassigned". The sidebar badge counts new leads and unread chats. |
| Lead page (D6) | The chat with presets ("Send lot location", "Suggest similar cars") and photos. Also stage (Lost asks why), assignment, follow-up date, team-only notes, the customer book link and "Continue on WhatsApp". The first reply moves a New lead to Contacted and assigns it to whoever replied. Sales reps can take a lead; owners and managers assign anyone. |
| Buyer chat (15) | "Chat" on car pages and "Message the lot" on lot pages open a thread with the lot, with quick replies, photos and read markers. Guests sign in and land back in the thread. "Messages" in Account lists their chats. |
| Real time | With Laravel Reverb running, messages and typing arrive instantly. Without it, chat checks for new messages every 4 seconds, so everything works on Laragon as is. |
| Alerts | If a message is still unread after 10 minutes, the other side gets one WhatsApp message (SMS fallback) (`chat:notify-unread`, every 5 minutes). Due follow-ups go to the assigned rep, or else the owner (`leads:follow-up-reminders`, every 15 minutes). |
| Notification centre (20) | The bell in the header and dealer sidebar opens every notification (leads, bookings, new stock, billing, follow-ups). **Account → Notifications** turns WhatsApp/SMS, email and push on or off per type. Sign-in codes and receipts are always sent. |
| Broadcasts to lots | **Admin → Engagement → Broadcasts**: write an announcement, tip or promo (title, message, button and link), choose the lots (states, plans, live or waiting for approval, CAC-verified or not, activity: owner away 30 days / no cars live / has cars live, owners or owners and managers) with a live "Reaches N people" count, and how it goes (always in the notification centre; email, push and optionally WhatsApp with the `lot_announcement` template). **Send now** (delivered on the queue) or **Schedule**; **Copy** to reuse. The list shows who it reached and how many opened the link (every link is tracked through `/e/…`); **Who got it** lists each person and whether they opened it. Emails carry an unsubscribe link; lots can also turn "LotLink news" off in Notification settings. |
| Automated emails to lots | **Admin → Engagement → Automated emails**: behaviour-based reminders to lot owners, each on/off with its timing, cooldown, subject and opening line ({name}, {lot}, {count}), at a chosen hour in each lot's time zone: owner hasn't signed in for 30 days (with the lot's views, enquiries and waiting chats), no cars uploaded, cars left as drafts (listed), setup not finished, and a daily digest when buyers are waiting (unanswered chats, bookings to confirm, offers, reservation deposits, trade-ins). Each shows last-30-day sent/opened stats, **Who gets it now** and **Send me a test**. Sign-in activity is recorded for this (at most every 15 minutes, never during "Log in as"). |
| Mobile API | `/api/v1` with Sanctum bearer tokens, for a future LotLink app (full reference in `docs/api.md`): sign in with a WhatsApp/email code or password, search and view cars and lots, saved cars, chat with lots, book/move/cancel visits, the notification centre, and for lot staff their leads (with chat and stage/assignee updates), stock and calendar. Same Actions and rules as the website; public IDs only, no costs. Tokens last 90 days; **Account → Sign-in and security** lists signed-in phones with **Sign out**, and a password reset signs them all out. |
| Push notifications | **Notification settings → Push notifications on this device → Turn on** (the notification centre offers it too). Every notification that goes by WhatsApp/email/in-app can also arrive as a phone or desktop push (title by kind, the notification text, tap opens its page), and chat messages are pushed the moment they're sent (the WhatsApp reminder still follows after 10 minutes unread; a new lead's first message comes as the "New lead" push only). Per-type switches, a device list with **Remove**, **Send a test**, and signing out stops that device. Works in Chrome, Edge, Firefox and Samsung Internet on Android and desktop, and Safari on Mac; on iPhone (iOS 16.4+) only after **Add to Home Screen**. Needs HTTPS and VAPID keys (`php artisan push:vapid`). Pushes are end-to-end encrypted to each browser; devices the push service reports gone are deleted. |
| Quality | 330 tests (also on MySQL), including dedupe, chat permissions and channel auth, unread counts and alerts, board filters, assignment rules, follow-up reminders, closing leads on delivery, notification preferences and lead tenancy. |

Deferred: offers, trade-ins and reservations as lead sources (S9), the inspection report preset (S12), web push (later), and response-time analytics (S11).

**Sprint S7 (Billing and spotlight) ✅**

| Area | What works |
| --- | --- |
| Plans and trials (M16) | Every new lot starts a 14-day Starter trial. Existing lots started theirs when this sprint's migration ran. The owner gets a WhatsApp reminder 3 days before the end. When a trial ends unpaid, or a renewal doesn't arrive, the lot gets 7 days of grace. After that it moves to Free: cars above Free's 10-car limit are **hidden, not deleted** (the newest and spotlighted stay live, reserved cars always stay). Choosing Free on a paid plan keeps it until the paid month ends. |
| Paystack | Checkout goes to Paystack with the plan's Paystack code, so the card is charged monthly. Every payment is verified with Paystack's API before anything changes, and the amount must match. The webhook (`/webhooks/paystack`) checks the HMAC SHA-512 signature, stores each event and handles it once. It covers first payments, renewals, the subscription code, failed renewals (past due and grace) and cancellations. Admins refund from `/admin/payments`. |
| Paystack or Flutterwave | **Admin → Settings → Payments** chooses which provider takes new payments (plans, spotlights, adverts); a provider can only be chosen once its keys are set. Each payment and subscription stays with the provider that took it: verification, refunds, card updates (Paystack's hosted page), cancelling and renewals always go back to it, so switching strands nobody. Plans can have a Paystack plan code and a Flutterwave payment plan id (**Create on Paystack / Flutterwave** in Plans); **Change price** updates both (Flutterwave can't reprice, so it gets a new plan for new subscribers, and "everyone" is refused while Flutterwave bills anyone on that plan). Flutterwave's webhooks (`/webhooks/flutterwave`, `verif-hash`) only carry a shared secret, so every charge, renewals included, is verified with its API first. |
| Sandbox | Without provider keys (`PAYMENT_DRIVER=sandbox`, the default locally) payments go to a LotLink test page with "Pay" and "Decline" buttons, and then through the same verification code. It is refused in production. |
| Billing page (D11) | Current plan, trial days left, card, this month's usage (listings, staff, free spotlights), the four plans with choose/switch, a coupon box, featuring the lot, running spotlights, and payments with PDF invoices. Only the owner can change the plan; managers can view it. |
| Coupons | `LAUNCH3` (seeded, 20 uses) gives 90 days free on Starter, per the spec's launch offer. In `/admin/coupons` admins create codes (or generate one), change the plan, free days, limit and expiry, pause/resume, see which lots used a code, and delete unused ones. Changes apply to future redemptions only; every change is in the audit log. |
| Spotlight (M5) | Stock → **Spotlight** on a live car: 7, 14 or 30 days. The car shows in a "Sponsored" row (at most 3) above matching search results, in both search engines, and in the home page's Spotlight carousel. Pro includes 2 free 7-day spotlights a month. Buying more adds days to the end. The hourly `spotlights:expire` ends them. |
| Featured lots | From Billing, owners and managers can pay to put the lot in the home page's "Featured lots" row for 7, 14 or 30 days. |
| Follow a lot | "Follow for new stock" on lot pages, and "Lots I follow" in Account. Every 30 minutes followers get one WhatsApp message per lot about cars listed since the last one. |
| Plan limits | Listings (Free 10, Starter 50), staff (1/3/10) and open orders (Free 10) are enforced. Share-card images come with Starter and up. |
| Quality | 305 tests (also on MySQL), including webhook signature and idempotency, verification and amount checks, trial, grace and downgrade, the free spotlight allowance, sponsored search on both engines, and follower batching. |

Prices are placeholders (Starter ₦15,000, Pro ₦45,000 a month; spotlights from ₦5,000). Set real ones in `/admin/plans` → **Change price** (updates the Paystack plan too; choose whether current subscribers keep their price or pay the new one from their next renewal, in which case they're told) and in `/admin` → Advert prices, after talking to your first lots.

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
| Search (M4) | `/cars` with text search, make, body type, price, year, mileage, gearbox, condition and fuel filters, sorting, and removable filter chips. Filter sheet on phones, sidebar on desktop. **Photos** on each card opens a quick look at all the car's photos (from `/car/{ulid}/photos`, live cars only) without leaving the list. |
| Near me | Uses the phone's location (asked first, never stored on the server) to sort by distance or limit to 5–100 km, with distances on every card. |
| Search engines | Meilisearch in production (typo-tolerant, geo filters) via Laravel Scout; plain MySQL on Laragon. Both pass the same tests. |
| Car page | `/car/{id}-{slug}`: photo gallery (tap a photo or **Zoom** for a full-screen viewer: swipe or arrow keys, pinch, double-tap, mouse wheel or +/− to zoom up to 4×, drag to look around; thumbnails on desktop), specs, features, lot card with open/closed status and directions, WhatsApp (message pre-filled with the car and link) and Call buttons, share menu, similar cars. Link previews (Open Graph) are rendered on the server. Sold cars stay reachable but marked sold. |
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
| Photos | Up to 12 per car. Drag several in on a computer, or choose or take them on a phone; two upload at a time with overall progress, and failed ones can be retried. Large photos are shrunk in the browser, uploaded (directly to Cloudflare R2 in production), then converted to WebP at 1600, 800 and 400 px with location data removed. Empty slots name the recommended shot (front ¾, sides, dashboard & odometer, engine bay…) and a photo guide (shot list and tips) sits beside the grid on big screens and folds away on phones. Tap or click a photo to rotate it, make it the cover, move it or remove it; drag to reorder on a computer. |
| Stock (D3) | Status tabs and counts, search by make, model or VIN, ageing flag at 45 days, new-arrival badge for 7 days, hide, unhide and reserve. |
| Rules | Status state machine (draft → available → reserved → sold, plus hidden), plan listing limits, price history with price-drop event, VIN unique per lot, sales staff can't change live prices or delete. |
| Quality | 114 Pest tests (also run on MySQL 8), Pint, Larastan level 6, vue-tsc |

Not in S2 (scheduled later in the TDD): bulk CSV/Excel import (S13), duplicate/fraud detection (S12), marking cars sold (Lot Manager orders, S5), views and leads per car (S11), share cards (S6), spotlight (S7), video and 360° photos (phase 2).

**Sprint S1 (Foundations) ✅**

| Area | What works |
| --- | --- |
| Accounts (M1) | One-time-code sign-in and sign-up (6 digits, 5-min expiry, 5 attempts, 3 sends per 15 min). People pick **WhatsApp number** or **email address**: WhatsApp codes don't fall back to paid SMS unless `OTP_SMS_FALLBACK=true`, and email codes are free. Email-only accounts have no phone; lots' customer books match them by email. E.164 normalisation, customer / staff / admin roles. **Optional password**: once signed in, **Account → Sign-in and security** (also in the dealer sidebar) adds, changes or removes a password (8+ characters with letters and numbers; changing or removing needs the current one). Then `/login` → "Sign in with a password" takes the email **or** WhatsApp number and the password (5 tries a minute per account, one message for every failure, never creates accounts). **Forgot password?** (`/forgot-password`, linked from the password form and the security page) emails a one-hour, single-use reset link (Laravel's password broker, one email a minute, same answer whether or not the email has an account); saving the new password signs the person in and signs out remembered devices. No email on the account? Sign in with a WhatsApp code and set a new one. **Keep me signed in for a week** (ticked by default on `/login`, for codes, passwords and Google; also the admin login's remember box): a remember cookie lasting `AUTH_REMEMBER_MINUTES` (10080 = 7 days). Unticked, people are signed out after `SESSION_LIFETIME` (2 hours) without using LotLink. **Continue with Google** (when `GOOGLE_CLIENT_ID`/`GOOGLE_CLIENT_SECRET` are set): creates the account, or signs in to the one with the same Google-verified email and links it; signed-in people can connect or disconnect Google on the same page (WhatsApp sign-ups get Google's email added). Every change is audit-logged and emailed/notified to the person. |
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

- **Sign in** at `/login` with a WhatsApp number or an email address. With `WHATSAPP_DRIVER=log` the WhatsApp
  code is written to `storage/logs/laravel.log` (search for "login_code"); with `MAIL_MAILER=log` the email code is
  in the same log (search for "Your LotLink code").
  After signing in you can add a password in Account → Sign-in and security. For **Continue with Google**, create an
  OAuth client (Google Cloud console → APIs & Services → Credentials → OAuth client ID, type "Web application"),
  add `http://carlot.test/auth/google/callback` (your `APP_URL` + `/auth/google/callback`) as an authorised redirect
  URI, and put the ID and secret in `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET`. Without them the button is hidden.
- **Push notifications**: run `php artisan push:vapid` once (writes `VAPID_PUBLIC_KEY` / `VAPID_PRIVATE_KEY` to `.env`),
  then `php artisan config:clear`. Browsers only allow push over HTTPS (or `localhost`), so in Laragon turn on SSL
  (Menu → Apache → SSL → Enabled) and use `https://carlot.test`, with `npm run build` (the service worker only runs in
  a production build). Never regenerate the keys once people use push: every device would have to turn it on again.
  Optional but faster: enable PHP's `gmp` extension (Laragon: Menu → PHP → Extensions → gmp; servers: `php8.3-gmp`).
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
- **SEO and alerts**: try `/cars/toyota`, `/cars/lekki` or `/sitemap.xml`. Save a search on `/cars`,
  then lower a matching car's price in the dealer dashboard to see the alert in
  `storage/logs/laravel.log`. Bulk import needs the Enterprise plan: in `/admin` → Lots, use **Set plan** on the lot.
- **Trust**: `php artisan db:seed --class=DemoTrustSeeder` (after DemoMarketplaceSeeder) adds 3 reviews
  and an inspection at Demo Lot Ikeja, and an independent inspector: sign in as `08000000200`
  and open any car to add a signed report. Verification files and inspection PDFs are stored
  under `storage/app/private`.
- **Map**: set `GOOGLE_MAPS_BROWSER_KEY` for the interactive Google map with a draggable pin.
  Without it, "Use my current location" (phone GPS) and manual coordinates still work.
  Geolocation needs HTTPS or localhost; enable SSL in Laragon to test on a phone.

### Scheduled jobs (reminders)

Reminders, no-shows, escalations, follow-up reminders, chat alerts and share-link pruning run on Laravel's scheduler. On Laragon, keep
`php artisan schedule:work` running in a terminal while testing bookings. In production add one
cron entry: `* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1`.

### WhatsApp in production

Set `WHATSAPP_DRIVER=meta`, `WHATSAPP_TOKEN` and `WHATSAPP_PHONE_NUMBER_ID` from Meta's WhatsApp
Business Cloud API, and create these templates (body variables in order; the URL button's base is
your `APP_URL` with a `{{1}}` suffix). To change wording later, get a new version approved with the
same variables and button, then enter its name in `/admin` → Message templates:

| Template | Category | Body variables | Button |
| --- | --- | --- | --- |
| `login_code` | Authentication | code | copy code |
| `staff_invitation` | Utility | lot name, role | invitation link |
| `lot_welcome` | Utility | owner name, lot name | sign in |
| `booking_confirmed` | Utility | name, what, lot, when | manage booking |
| `booking_pending` | Utility | name, what, lot, when | manage booking |
| `appointment_reminder` | Utility | what, lot, when, directions | manage booking |
| `appointment_update` | Utility | what, lot, change | manage booking |
| `dealer_booking_alert` | Utility | event, buyer, what, when | open calendar |
| `payment_receipt` | Utility | name, amount, lot, car, receipt no, balance | track order |
| `order_update` | Utility | name, car, lot, update | track order |
| `billing_update` | Utility | lot, message | open billing |
| `lot_new_stock` | Marketing | lot, number of cars, example car | open the lot |
| `follow_up_due` | Utility | staff name, type, customer, lot | open Today / the lead |
| `new_message` | Utility | sender, message snippet | open the chat |
| `offer_update` | Utility | name, car, lot, update | open bookings and offers |
| `trade_in_update` | Utility | name, car, lot, update | open bookings and offers |
| `reservation_update` | Utility | name, car, lot, update | open bookings and offers |
| `instalment_reminder` | Utility | name, amount, car, lot, due date | track order |
| `daily_summary` | Utility | lot, date, walk-ins, new orders, money received, balances due, overdue instalments | open the report |
| `review_invite` | Utility | lot, what (visit type and car) | leave a review |
| `saved_search_match` | Marketing | search name, car, price, lot | see the car |
| `price_drop` | Marketing | car, new price, amount off, lot | see the car |

### Going live checklist

1. `APP_ENV=production`, `APP_DEBUG=false`, a real `APP_URL` on HTTPS, `TRUSTED_PROXIES` for your proxy.
2. `PAYMENT_DRIVER=live`, `WHATSAPP_DRIVER=meta`, `SMS_DRIVER=termii`, `SCOUT_DRIVER=meilisearch`, R2 disks.
3. Optional integrations: `SOCIAL_DRIVER=meta` with `META_APP_ID`/`META_APP_SECRET` (redirect URL
   `https://your-domain/dealer/social/callback`); `FINANCE_PARTNER_DRIVER=http` with the partner's URL, key and
   `FINANCE_PARTNER_WEBHOOK_SECRET` (webhook `https://your-domain/webhooks/finance`).
4. Caddy in front with on-demand TLS: `on_demand_tls { ask https://your-domain/internal/domains/allowed }`.
5. Sign in to `/admin`, set up two-step sign-in, change the seeded admin password.
6. Cron `* * * * * php artisan schedule:run` and a queue worker (`php artisan queue:work --queue=critical,notifications,media,default`).
7. Run `k6 run -e BASE_URL=https://staging… loadtest/marketplace.js` against staging and read `docs/security-review.md`.

Until a template is approved, messages fall back to SMS automatically.

### Live chat (Laravel Reverb, optional)

Chat works without this: it polls every 4 seconds. For instant messages:

1. In `.env`, set `BROADCAST_CONNECTION=reverb` and give `REVERB_APP_KEY` and `REVERB_APP_SECRET`
   any random strings (e.g. `php -r "echo bin2hex(random_bytes(16));"`). Keep `REVERB_HOST=127.0.0.1` and `REVERB_PORT=8080` locally.
2. Run `npm run build` again so the browser picks up the `VITE_REVERB_*` values.
3. Keep `php artisan reverb:start` running in a terminal (in production, under Supervisor behind your web server's WebSocket proxy with `REVERB_SCHEME=https`).

### Paystack and Flutterwave in production

Set up one or both, then choose which takes new payments in `/admin` → Settings → Payments (existing subscriptions stay
with the provider they started on).

1. Set `PAYMENT_DRIVER=live`, plus `PAYSTACK_PUBLIC_KEY` / `PAYSTACK_SECRET_KEY` and/or `FLUTTERWAVE_PUBLIC_KEY` /
   `FLUTTERWAVE_SECRET_KEY` / `FLUTTERWAVE_SECRET_HASH`.
2. In `/admin/plans`, set real prices, then press "Create on Paystack" and/or "Create on Flutterwave" on each paid plan so it renews monthly.
3. Webhooks: in the Paystack dashboard set `https://your-domain/webhooks/paystack`. In the Flutterwave dashboard → Settings →
   Webhooks set `https://your-domain/webhooks/flutterwave`, turn on failed-payment and subscription events, and set a secret
   hash (the same value as `FLUTTERWAVE_SECRET_HASH`).
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
  Accounts/              Users, OTP codes, SendOtp / VerifyOtp, SetPassword, LogInWithPassword, SignInWithGoogle
  Lots/                  Lots, members, invitations, hours; BelongsToLot tenancy
  LotManager/            Walk-ins, customers, orders, payments, receipts, follow-ups
  Sharing/               Share links (/c/{code}), share cards, QR codes
  Finance/               Budgets and the FinanceCalculator (same formulas in resources/js/lib/finance.ts)
  Billing/               Plans, subscriptions, payments, coupons, spotlights; PaymentGateway (Paystack | sandbox)
  Leads/                 Leads, notes, conversations and chat messages; CaptureLead, SendMessage, UpdateLead
  Deals/                 Offers, trade-ins and reservations; MakeOffer, RespondToOffer, ValueTradeIn, StartReservation
  Messaging/             SmsGateway (log, Termii)
  Support/               PhoneNumber (E.164)
app/Http/Controllers/    Thin controllers calling Actions; Dealer/ for /dealer/{lot}
app/Filament/            Admin panel resources
resources/js/Pages/      Inertia pages (Home, Auth/*, Dealer/*)
resources/js/components/ Shared UI; lot/ holds the forms shared by onboarding and settings
```

See `CLAUDE.md` for conventions.
