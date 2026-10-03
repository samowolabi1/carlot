# CarYard API v1

JSON API for the CarYard mobile app, at `/api/v1`. Authentication is a Sanctum bearer token
(`Authorization: Bearer {token}`). Every endpoint answers in JSON, errors included:
`401` (no or expired token), `403` (not allowed), `404`, `422` (`{"message", "errors": {field: [..]}}`) and `429` (rate limit).

- Public IDs only: cars, chats, leads and bookings by ULID, lots by slug. No internal ids, costs or profit.
- Times are ISO 8601 in UTC. `timezone` fields give the seller's zone for display.
- Prices: `price` is formatted (`₦12,500,000`), `price_value` whole naira.
- Rate limit: `API_RATE_LIMIT` requests a minute (default 120) per user or address; sign-in endpoints have their own limits.
- Tokens last `SANCTUM_TOKEN_DAYS` (90). People see and revoke them under Account → Sign-in and security;
  a password reset or deleting the account signs every phone out.

## Sign in

| Method | Path | Body | Notes |
|---|---|---|---|
| POST | `/auth/code` | `phone` (e.g. `0803 123 4567`), or `method: "email"` + `email`; optional `channel: "sms"` | Sends a 6-digit code. `202 {sent_to, channel}` |
| POST | `/auth/token` | the same `phone`/`email` + `code` + `device_name` | Checks the code (creates the account if new). `201 {token, token_type, expires_at, data: user, needs_name}` |
| POST | `/auth/password` | `login` (email or phone), `password`, `device_name` | For accounts that added a password. 5 tries a minute. |
| DELETE | `/auth/token` | | Signs this device out. |
| GET | `/me` | | The user: `ulid, name, phone, email, role, has_password, google_connected, lots[{slug, name, role, status}]` |
| PATCH | `/me` | `name` | New accounts add their name (`needs_name`). |

## Marketplace (no token needed)

| Method | Path | Notes |
|---|---|---|
| GET | `/cars` | Search. Query: `q`, `make[]`, `model[]`, `body[]`, `condition[]`, `transmission`, `fuel[]`, `drive[]`, `colour[]`, `feature[]` (cars with all of them), `has[]` (`loans`, `trade_ins`, `offers`, `negotiable`, `inspected`, `verified_lot`, `duty_paid`, `registered`), `price_min`, `price_max`, `year_min`, `year_max`, `mileage_max`, `city`, `state`, `lat`, `lng`, `radius`, `sort` (`newest`, `nearest`, …), `page` (the same as the website's /search). `{data: [card], sponsored: [card], filters, meta}` |
| GET | `/cars/filters` | Filter choices from what is on sale, each with a `count`: `makes`, `models` (with `make_id`), `body_types`, `conditions`, `transmissions`, `fuels`, `drivetrains`, `colours`, `states`, `cities` (with `state`), `features` (with `group`), `extras` (the `has[]` values), `years` and `prices` ranges, `radii`. Cached for a few minutes. |
| GET | `/cars/{ulid}` | Car details, photos (`src`, `full`), specs, features, inspection summary, `lot`, `saved`, `from_monthly`. |
| GET | `/lots/{slug}` | Lot page: details, opening hours, rating, `following`, and its cars (`?page=`). |
| GET | `/lots/{slug}/slots` | Bookable times for 14 days (`starts_at` in UTC). |
| GET | `/legal` | The legal documents: `{data: [{key, title, version, url}], required_version}` (Terms, Privacy Policy, Lender Terms, Security). |

## Terms and privacy

The app's sign-in screen must say, next to the button, that continuing accepts the Terms of Use and Privacy Policy (with links from
`/legal`): a new account made by `POST /auth/token` is recorded as accepting them. `GET /me` returns `terms: {accepted, required_version,
accepted_version}`. While `accepted` is false (older accounts, accounts made by a seller or an admin, or after we change the documents), every
signed-in endpoint except `/me`, `/auth/token` and `/legal/accept` answers `403 {code: "terms_not_accepted", required_version}`: show the
documents and send `POST /legal/accept` with `agree: true` (returns the updated user).

## Buyer (token)

| Method | Path | Notes |
|---|---|---|
| GET | `/saved` | Saved cars with `price_drop`, `sold`, `unavailable`. |
| PUT / DELETE | `/saved/{car ulid}` | Save / remove. |
| GET | `/conversations` | Chats with lots: `ulid, lot, car, last, last_at, unread`. |
| POST | `/conversations` | `vehicle` (car ulid) or `lot` (slug), optional `body`. Starts or reopens the chat (and the seller's lead). `201 {data: thread}` |
| GET | `/conversations/{ulid}` | The thread; `?after={message id}` returns only newer messages (poll every few seconds). Marks it read. |
| POST | `/conversations/{ulid}/messages` | `body` and/or `photo` (multipart image). |
| GET | `/bookings` | Visits and test drives. |
| POST | `/bookings` | `lot` (slug), `type` (`viewing`, `test_drive`, `inspection`, `trade_in`), `starts_at` (a slot), optional `vehicle`, `notes`, `whatsapp_reminders`. |
| GET / PATCH | `/bookings/{ulid}` | Show / move (`starts_at`). |
| POST | `/bookings/{ulid}/cancel` | Optional `reason`. |
| GET | `/notifications` | The notification centre: `{data: [{id, kind, text, url, created_at, read}], unread}`. |
| POST | `/notifications/read` | Marks all read. |

## Lot staff (token; member of the seller)

The sellers come from `GET /me`. Every path checks membership (`403` otherwise) and only finds the seller's own records (`404`).

| Method | Path | Notes |
|---|---|---|
| GET | `/dealer/lots/{slug}/leads` | Open leads, newest activity first. `?stage=`, `?who=mine|unassigned`, `?page=`. |
| GET | `/dealer/lots/{slug}/leads/{ulid}` | A lead with its chat (`?after=` as above). The buyer's number is masked until they engage. |
| PATCH | `/dealer/lots/{slug}/leads/{ulid}` | `stage`, `assigned_to` (user ulid; sales staff can only take it themselves), `next_follow_up_at`, `lost_reason`. |
| POST | `/dealer/lots/{slug}/leads/{ulid}/messages` | Reply to the buyer: `body` and/or `photo`. |
| GET | `/dealer/lots/{slug}/vehicles` | Stock (seller fields, prices in whole naira; never costs). `?status=`. |
| GET | `/dealer/lots/{slug}/appointments` | Visits from `from` to `to` (dates in the seller's zone; default the next 8 days). |

Push notifications for the app are not part of v1 (the web uses Web Push); the app can poll `/notifications`.
Adding and editing cars, Sales Manager and billing stay on the website for now.

## Lenders' systems (car loans)

Loans start on CarYard and continue with the lender: CarYard gathers and passes on the application; the lender decides and
completes KYC, the agreement and payment through its own channels. A lender that chooses "Our own system (API)" in the lender portal (Settings) or is set up that way by an admin gets each
application posted to it instead of waiting in the portal. There is no token: CarYard calls the lender, and the lender calls back.

**CarYard → lender**: `POST {api_url}/applications` with `Authorization: Bearer {api_key}` and JSON:
`reference` (CarYard's id for the application), `amount`, `deposit` (whole naira), `currency`, `tenor_months`,
`vehicle {title, year, price}`, `lot {name, city, state}`, `applicant {name, phone, email, monthly_income, monthly_commitments,
employment, employer}`, `consented_at`, `callback_url`. Answer `2xx` with `{reference, status?, message?, approved_amount?}`
(`status`: `received` by default, or `pre_approved` / `declined` straight away). Any other answer marks the application
"Couldn't send" and nothing is kept on your side.

**Lender → CarYard**: `POST /webhooks/finance/{lender slug}` (the `callback_url`) with a JSON body signed with HMAC-SHA256 of the
raw body using the lender's webhook secret, in `X-CarYard-Signature`. Fields: `reference` (CarYard's or yours), `status`
(`received`, `documents_requested`, `pre_approved`, `approved`, `disbursed`, `declined`), optional `message` (shown to the buyer),
`next_steps` (with `pre_approved` / `approved`: how the buyer continues with you; defaults to the next steps in your settings), `approved_amount`, `rate` (% a year), `tenor_months`, `disbursed_amount`, `disbursed_reference`. Replies are JSON: `200 {ok, status}`,
`401` bad signature, `404` not your application, `422 {errors}` invalid or not allowed from the current status (for example
`disbursed` before `approved`). Repeating the current status only updates the message.
