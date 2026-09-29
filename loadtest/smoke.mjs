// Quick latency check without k6: node loadtest/smoke.mjs [base] [requests-per-page] [concurrency]
// Prints p50/p95/max per page. Use it for a rough local number; use k6 against staging for the real test.
const base = process.argv[2] || 'http://127.0.0.1:8000';
const perPage = Number(process.argv[3] || 60);
const concurrency = Number(process.argv[4] || 4);

const xml = await (await fetch(`${base}/sitemap.xml`)).text();
const locs = [...xml.matchAll(/<loc>([^<]+)<\/loc>/g)].map((m) => m[1].replace(/^https?:\/\/[^/]+/, ''));
const pages = {
    home: ['/'],
    search: ['/cars', '/cars?q=toyota', '/cars?body[]=suv', '/cars?price_max=15000000', '/cars?sort=price_asc'],
    landing: locs.filter((p) => /^\/cars\/.+/.test(p)),
    car: locs.filter((p) => p.startsWith('/car/')),
    lot: locs.filter((p) => p.startsWith('/l/')),
};

const pct = (sorted, p) => sorted[Math.min(sorted.length - 1, Math.ceil((p / 100) * sorted.length) - 1)];
const results = {};

for (const [name, list] of Object.entries(pages)) {
    if (!list.length) continue;
    const times = [];
    let failed = 0;
    let next = 0;
    const worker = async () => {
        while (next < perPage) {
            const path = list[next++ % list.length];
            const start = performance.now();
            const res = await fetch(base + path);
            await res.arrayBuffer();
            times.push(performance.now() - start);
            if (res.status !== 200) failed++;
        }
    };
    await Promise.all(Array.from({ length: concurrency }, worker));
    times.sort((a, b) => a - b);
    results[name] = { requests: times.length, failed, p50_ms: Math.round(pct(times, 50)), p95_ms: Math.round(pct(times, 95)), max_ms: Math.round(times.at(-1)) };
}

console.table(results);
