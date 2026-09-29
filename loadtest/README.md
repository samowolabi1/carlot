# Load tests

- `marketplace.js` — k6. Ramps to 100 virtual buyers over 5 minutes: home → search (6 query shapes) →
  an SEO landing page → a car → a lot → its booking slots, with think time between pages. Cars, lots
  and landing pages come from `/sitemap.xml`, so it runs against any environment.
  `k6 run -e BASE_URL=https://staging.example loadtest/marketplace.js`
- `smoke.mjs` — no k6 needed: `node loadtest/smoke.mjs http://127.0.0.1:8000 60 4` prints p50/p95 per page.

Search and SEO pages are rate-limited per IP (120/min, TDD). Every k6 virtual user comes from the same
machine, so raise `BROWSE_RATE_LIMIT` on the environment under test for the duration of the run.

## First run (Sprint 14)

Setup: 60 lots, 3,000 cars (Meilisearch index, MySQL 8), 4,043 sitemap URLs. **One 4-core container
running everything at once**: PHP's built-in server with 8 workers and OPcache, MySQL, Meilisearch and
k6 itself. This is a floor, not a production figure.

**One buyer at a time** (`smoke.mjs`, 40 requests per page, concurrency 1):

| Page | p50 | p95 | TDD target |
| --- | --- | --- | --- |
| Search | 85 ms | 103 ms | < 200 ms p95 ✓ |
| SEO landing page | 51 ms | 68 ms | |
| Car page | 56 ms | 65 ms | |
| Lot page | 53 ms | 58 ms | |
| Home | 91 ms | 97 ms | |

Each page runs 9–17 SQL queries taking 7–25 ms in total; the rest is PHP rendering.

**100 buyers at once** (k6, 5 minutes, ~39 requests/s, 11,959 requests):

| Page | p50 | p95 |
| --- | --- | --- |
| Search | 104 ms | 320 ms |
| SEO landing page | 73 ms | 264 ms |
| Car page | 74 ms | 266 ms |
| Lot page | 82 ms | 301 ms |

0 failed requests. Under this load the box is CPU-bound (PHP workers, MySQL, Meilisearch and k6
share 4 cores), so the search p95 misses the 200 ms target here. What changed as a result: SEO landing
figures are now cached for 10 minutes (TDD: cache SEO pages 5–15 min), and the search rate limit is
configurable for tests.

## Before launch

Run `marketplace.js` against staging on the production setup (php-fpm or Octane, MySQL and
Meilisearch on their own machines, OPcache on, `php artisan optimize`). Expect the p95 figures to sit
close to the single-buyer numbers above; if search is over 200 ms p95 there, add Redis for the cache
and sessions (TDD) and another PHP server before anything else. The public JS is 108 KB gzipped for
the shared bundle plus a small page chunk, inside the 200 KB budget.
