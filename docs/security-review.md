# LotLink security review (Sprint 14)

A review of the app against the TDD's security and privacy section before full release. Each item
says what was checked, what was found and what changed. Tests that cover an item are named so the
checks keep running in CI.

## Summary

| Area | Status | Notes |
| --- | --- | --- |
| Tenancy (lot A can't reach lot B) | OK | `BelongsToLot` scope + `lot.member` + scoped bindings; a tenancy test for every lot-owned model (tests `*Tenancy*`, `VerificationTest`, `InspectionTest`, `ReviewTest`, `SocialPostTest`, `ImportTest`). |
| Authentication | Fixed | Phone OTP hashed, attempt/send limits, session regenerated on login. **New:** admin two-step sign-in (TOTP) — `SecurityTest`. |
| Authorisation | OK | Policies per model; Filament panel admin-only; `Impersonation` audit-logged. |
| Security headers | Fixed | **New** `SecurityHeaders` middleware: CSP with per-request nonces, HSTS, `X-Frame-Options: DENY` (mini-site embeddable), `nosniff`, `Referrer-Policy`, `Permissions-Policy`. |
| Cookies and HTTPS | Fixed | Session cookie `secure` by default in production; `TRUSTED_PROXIES` so HTTPS is detected behind Caddy/Cloudflare. HttpOnly and SameSite=Lax already set. |
| Uploads | OK | Size and MIME checked; photos re-encoded to WebP (drops EXIF GPS and embedded payloads); private files (CAC documents, trade-in photos, receipts, inspection PDFs, imports) on the private `local` disk behind signed, short-lived links. |
| Payments | OK | Paystack signature check, server-side re-verification, idempotent webhooks, amounts from the database. |
| Webhooks | OK | Paystack (HMAC-SHA512) and finance partner (HMAC-SHA256, `hash_equals`) refuse unsigned calls — `FinanceTest`. |
| Injection | OK | No user input reaches raw SQL: every `selectRaw`/`orderByRaw` uses constants; the distance maths casts to float and formats with `%F`. Form Requests / `validate()` everywhere; no `$request->all()` into models. |
| XSS | OK | Vue escapes output; the only `v-html` is Laravel's own pagination labels. JSON-LD is printed through `StructuredData::encode()` with `JSON_HEX_TAG` — `SeoTest`. PDFs escape through Blade. |
| Secrets | OK | Environment only. Social tokens, finance applicant details and 2FA secrets are encrypted at rest (`encrypted` casts) — `SocialPostTest`, `FinanceTest`. |
| Rate limiting | OK | OTP, booking, offers, reports, messages, search, shares, finance (5/hour), imports, 2FA (5 tries / 5 min). |
| Privacy (NDPA) | Fixed | **New** account deletion: closes now, anonymised after 30 days (`accounts:anonymise`); a lot's own sales records stay, as the lot is the data controller. Buyer location is never stored (saved searches drop distance). Finance data is only sent with explicit consent. |
| Audit log | OK | Money, statuses, staff, moderation, plans, impersonation, 2FA and social connections. |

## Details and decisions

### Content Security Policy
`default-src 'self'`; scripts from self, a per-request nonce (Vite tags and Ziggy's `@routes`) and
Google Maps; styles allow `'unsafe-inline'` because Vue binds inline `style` attributes (brand
colours) — script injection is still blocked. Images from self, data/blob, Google Maps and the media
CDN (`R2_MEDIA_URL`); `connect-src` adds the R2 upload endpoint and Reverb. Paystack checkout is a
full-page redirect, so it needs no script access. `frame-ancestors 'none'`, except `/l/{lot}` and
custom domains, which lots may embed.

The CSP is **on in production** (`LOTLINK_CSP`) and off elsewhere, because `npm run dev` serves
scripts from Vite's own port. The Filament admin (`/admin`, `/livewire`) is excluded from the CSP:
Filament and Livewire rely on inline scripts. The admin is protected by login, 2FA and the other headers.

### Admin two-step sign-in
RFC 6238 codes (verified against the RFC test vector), 30-second steps with one step of drift,
8 single-use recovery codes stored hashed, 5 attempts per 5 minutes. Setup and challenge pages run
before the Filament panel. On by default in production (`ADMIN_2FA`).

### Things to do outside the code before launch
- Set `APP_DEBUG=false`, `APP_ENV=production`, and `TRUSTED_PROXIES` to your proxy.
- Serve only over HTTPS (Caddy does this, including on-demand certificates for custom domains using
  `/internal/domains/allowed` as the `ask` endpoint).
- Put Cloudflare or the load balancer's rate limiting in front of `/auth/*` as a second layer.
- Have the privacy policy and lot data-processing terms reviewed by a lawyer (TDD).
- Rotate the Paystack, Meta, Termii and finance-partner secrets from the staging values.
- Back up the database and the private disk daily; test a restore.

### Known limits (accepted for launch)
- Sanctum API endpoints are not exposed yet, so there are no API tokens to scope.
- Filament admin has no CSP (see above).
- Duplicate-photo detection compares against other lots' covers of the same make in PHP; fine for
  launch volumes, worth moving to a dedicated index past ~100k listings.
