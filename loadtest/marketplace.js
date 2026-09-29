// k6 load test for the public marketplace (TDD performance targets).
//   k6 run -e BASE_URL=https://staging.lotlink.app loadtest/marketplace.js
// Ramps to 100 virtual buyers browsing: home → search → a landing page → a car → a lot → slots.
import http from 'k6/http';
import { check, group, sleep } from 'k6';

const BASE = __ENV.BASE_URL || 'http://127.0.0.1:8000';

export const options = {
    scenarios: {
        browse: {
            executor: 'ramping-vus',
            stages: [
                { duration: '1m', target: 25 },
                { duration: '3m', target: 100 },
                { duration: '1m', target: 0 },
            ],
        },
    },
    thresholds: {
        // TDD: search results < 200 ms p95 on the server.
        'http_req_duration{page:search}': ['p(95)<200'],
        'http_req_duration{page:car}': ['p(95)<300'],
        'http_req_duration{page:lot}': ['p(95)<300'],
        'http_req_duration{page:landing}': ['p(95)<300'],
        http_req_failed: ['rate<0.01'],
    },
};

const queries = ['', '?q=toyota', '?body[]=suv', '?price_max=15000000', '?sort=price_asc', '?make[]=1&year_min=2016'];

export function setup() {
    // Pick real cars and lots from the sitemap so the test works on any environment.
    const xml = http.get(`${BASE}/sitemap.xml`).body || '';
    const locs = [...xml.matchAll(/<loc>([^<]+)<\/loc>/g)].map((m) => m[1].replace(/^https?:\/\/[^/]+/, ''));
    return {
        cars: locs.filter((p) => p.startsWith('/car/')).slice(0, 200),
        lots: locs.filter((p) => p.startsWith('/l/')).slice(0, 50),
        landings: locs.filter((p) => /^\/cars\/.+/.test(p)).slice(0, 100),
    };
}

const pick = (list) => list[Math.floor(Math.random() * list.length)];

export default function (data) {
    group('home', () => {
        check(http.get(`${BASE}/`, { tags: { page: 'home' } }), { 'home 200': (r) => r.status === 200 });
    });
    sleep(1);

    group('search', () => {
        check(http.get(`${BASE}/cars${pick(queries)}`, { tags: { page: 'search' } }), { 'search 200': (r) => r.status === 200 });
    });
    sleep(1);

    if (data.landings.length) {
        check(http.get(`${BASE}${pick(data.landings)}`, { tags: { page: 'landing' } }), { 'landing 200': (r) => r.status === 200 });
        sleep(1);
    }

    if (data.cars.length) {
        check(http.get(`${BASE}${pick(data.cars)}`, { tags: { page: 'car' } }), { 'car 200': (r) => r.status === 200 });
        sleep(2);
    }

    if (data.lots.length) {
        const lot = pick(data.lots);
        check(http.get(`${BASE}${lot}`, { tags: { page: 'lot' } }), { 'lot 200': (r) => r.status === 200 });
        const slug = lot.split('/')[2];
        check(http.get(`${BASE}/lots/${slug}/slots`, { headers: { Accept: 'application/json' }, tags: { page: 'slots' } }), { 'slots 200': (r) => r.status === 200 });
    }
    sleep(2);
}
