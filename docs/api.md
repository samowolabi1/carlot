# LotLink API v1

JSON API for the LotLink mobile app, at `/api/v1`. Authentication is a Sanctum bearer token
(`Authorization: Bearer {token}`). Every endpoint answers in JSON, errors included:
`401` (no or expired token), `403` (not allowed), `404`, `422` (`{"message", "errors": {field: [..]}}`) and `429` (rate limit).

- Public IDs only: cars, chats, leads and bookings by ULID, lots by slug. No internal ids, costs or profit.
- Times are ISO 8601 in UTC. `timezone` fields give the lot's zone for display.
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
| GET | `/cars` | Search. Query: `q`, `make`, `model`, `body`, `condition`, `transmission`, `fuel`, `price_min`, `price_max`, `year_min`, `year_max`, `mileage_max`, `city`, `state`, `lat`, `lng`, `radius`, `sort` (`newest`, `nearest`, …), `page` (the same as the website's /search). `{data: [card], sponsored: [card], filters, meta}` |
| GET | `/cars/filters` | Filter choices (makes with stock, body types, …). |
| GET | `/cars/{ulid}` | Car details, photos (`src`, `full`), specs, features, inspection summary, `lot`, `saved`, `from_monthly`. |
| GET | `/lots/{slug}` | Lot page: details, opening hours, rating, `following`, and its cars (`?page=`). |
| GET | `/lots/{slug}/slots` | Bookable times for 14 days (`starts_at` in UTC). |

## Buyer (token)

| Method | Path | Notes |
|---|---|---|
| GET | `/saved` | Saved cars with `price_drop`, `sold`, `unavailable`. |
| PUT / DELETE | `/saved/{car ulid}` | Save / remove. |
| GET | `/conversations` | Chats with lots: `ulid, lot, car, last, last_at, unread`. |
| POST | `/conversations` | `vehicle` (car ulid) or `lot` (slug), optional `body`. Starts or reopens the chat (and the lot's lead). `201 {data: thread}` |
| GET | `/conversations/{ulid}` | The thread; `?after={message id}` returns only newer messages (poll every few seconds). Marks it read. |
| POST | `/conversations/{ulid}/messages` | `body` and/or `photo` (multipart image). |
| GET | `/bookings` | Visits and test drives. |
| POST | `/bookings` | `lot` (slug), `type` (`viewing`, `test_drive`, `inspection`, `trade_in`), `starts_at` (a slot), optional `vehicle`, `notes`, `whatsapp_reminders`. |
| GET / PATCH | `/bookings/{ulid}` | Show / move (`starts_at`). |
| POST | `/bookings/{ulid}/cancel` | Optional `reason`. |
| GET | `/notifications` | The notification centre: `{data: [{id, kind, text, url, created_at, read}], unread}`. |
| POST | `/notifications/read` | Marks all read. |

## Lot staff (token; member of the lot)

The lots come from `GET /me`. Every path checks membership (`403` otherwise) and only finds the lot's own records (`404`).

| Method | Path | Notes |
|---|---|---|
| GET | `/dealer/lots/{slug}/leads` | Open leads, newest activity first. `?stage=`, `?who=mine|unassigned`, `?page=`. |
| GET | `/dealer/lots/{slug}/leads/{ulid}` | A lead with its chat (`?after=` as above). The buyer's number is masked until they engage. |
| PATCH | `/dealer/lots/{slug}/leads/{ulid}` | `stage`, `assigned_to` (user ulid; sales staff can only take it themselves), `next_follow_up_at`, `lost_reason`. |
| POST | `/dealer/lots/{slug}/leads/{ulid}/messages` | Reply to the buyer: `body` and/or `photo`. |
| GET | `/dealer/lots/{slug}/vehicles` | Stock (dealer fields, prices in whole naira; never costs). `?status=`. |
| GET | `/dealer/lots/{slug}/appointments` | Visits from `from` to `to` (dates in the lot's zone; default the next 8 days). |

Push notifications for the app are not part of v1 (the web uses Web Push); the app can poll `/notifications`.
Adding and editing cars, Lot Manager and billing stay on the website for now.
