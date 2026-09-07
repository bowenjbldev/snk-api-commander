// Local HTTP/1.1 load test, Node standard library only. Credentials stay in the private fixture.
import https from 'node:https';
import { readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { performance } from 'node:perf_hooks';
import { pathToFileURL } from 'node:url';
import assert from 'node:assert/strict';

const base = '/api/v1/kilkana';
const agents = new Map();
function request(host, port, path, token, method = 'GET', body) {
    assert.match(host, /^[a-z0-9-]+\.[a-z0-9.-]+\.test$/);
    const key = `${host}:${port}`;
    if (!agents.has(key)) agents.set(key, new https.Agent({ keepAlive: true, maxSockets: 64 }));
    const payload = body === undefined ? undefined : JSON.stringify(body);
    const started = performance.now();
    return new Promise((resolve, reject) => {
        const req = https.request({
            hostname: '127.0.0.1', servername: host, port, path, method,
            rejectUnauthorized: false, agent: agents.get(key),
            headers: { Host: host, Accept: 'application/json', 'Accept-Encoding': 'identity',
                ...(token ? { Authorization: `Bearer ${token}` } : {}),
                ...(payload ? { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(payload) } : {}) },
        }, res => {
            const chunks = [];
            res.on('data', chunk => chunks.push(chunk));
            res.on('error', reject);
            res.on('end', () => {
                const bytes = Buffer.concat(chunks);
                let data;
                try { data = JSON.parse(bytes); } catch { /* Recorded as a validation failure. */ }
                resolve({ status: res.statusCode, data, bytes: bytes.length, ms: performance.now() - started });
            });
        });
        const timer = setTimeout(() => req.destroy(new Error('timeout')), 15000);
        req.on('close', () => clearTimeout(timer));
        req.on('error', reject);
        req.end(payload);
    });
}

export function summarize(samples, elapsed) {
    const ordered = samples.map(s => s.ms).sort((a, b) => a - b);
    const pct = p => ordered[Math.max(0, Math.ceil(ordered.length * p) - 1)] ?? 0;
    const valid = samples.filter(s => s.ok).length;
    return { requests: samples.length, valid, errors: samples.length - valid,
        elapsed_s: elapsed, rps: valid / elapsed, p50_ms: pct(.5), p95_ms: pct(.95), p99_ms: pct(.99),
        mean_bytes: samples.reduce((sum, s) => sum + s.bytes, 0) / (samples.length || 1) };
}

async function prepare(manifest, file, runId, ports) {
    assert.match(runId, /^[A-Za-z0-9-]+$/);
    const tenants = readFileSync(manifest, 'utf8').trim().split('\n').map(line => {
        const [key, host, app, , auth, login] = line.split('\t');
        return { key, host, app, token: readFileSync(auth, 'utf8').match(/Bearer ([^"]+)/)[1],
            login: JSON.parse(readFileSync(login, 'utf8')) };
    }).filter(t => t.app === 'kilkana');
    const selected = [];
    for (const t of tenants) {
        const res = await request(t.host, ports[0], `${base}/public/posts?count=20`);
        assert.equal(res.status, 200);
        if (!Array.isArray(res.data) || res.data.length < 20) {
            console.log(`Excluded ${t.host}: fewer than 20 published posts`);
            continue;
        }
        t.posts = res.data.map(p => ({ id: p.id, category: p.category.slug, author: p.author.id,
            path: `${base}/public/posts/${p.url_date.replaceAll('-', '/')}/${encodeURIComponent(p.slug)}` }));
        t.drafts = [];
        selected.push(t);
    }
    assert(selected.length, 'No populated tenant: refusing to benchmark empty lists');
    // Same records and URLs must exist on both runtimes; identity checks are not throughput samples.
    for (const port of ports) {
        for (const t of selected) {
            const me = await request(t.host, port, `${base}/auth/me`, t.token);
            assert.equal(me.status, 200);
            assert.equal(me.data.email ?? me.data.user?.email, t.login.email);
            const other = tenants.find(o => o.host !== t.host);
            assert(other, 'A second tenant is required for the cross-token check');
            assert.equal((await request(other.host, port, `${base}/auth/me`, t.token)).status, 401);
            const listing = await request(t.host, port, `${base}/public/posts?count=20`);
            assert.deepEqual(listing.data.map(p => p.id), t.posts.map(p => p.id));
            for (const post of t.posts) {
                const detail = await request(t.host, port, post.path);
                assert.equal(detail.status, 200);
                assert.equal(detail.data.id, post.id);
                assert(Array.isArray(detail.data.body_blocks) && detail.data.body_blocks.length > 0,
                    'Article detail must contain real body blocks');
            }
        }
    }
    for (const t of selected) {
        // One independent draft per client lane, never an existing editorial record.
        for (let lane = 0; lane < 24; lane++) {
            const post = t.posts[lane % t.posts.length];
            const slug = `bench-${runId}-${lane}`;
            const created = await request(t.host, ports[0], `${base}/posts`, t.token, 'POST', {
                title: slug, slug, status: 'draft', category: post.category, author_id: post.author,
                body_blocks: [{ id: 'paragraph-1', type: 'paragraph', config: { html: '<p>Temporary editorial benchmark draft.</p>' } }],
            });
            assert.equal(created.status, 201, `Draft creation failed: HTTP ${created.status}`);
            t.drafts.push(created.data.id);
        }
    }
    writeFileSync(file, JSON.stringify(selected), { mode: 0o600 });
    console.log(`Fixture ready: ${selected.length} populated tenant(s), 20 articles and 24 temporary drafts each`);
}

export function operation(sequence) {
    // A permutation of 0..99: exact weights without 40 identical requests in a row.
    const slot = (sequence * 37) % 100;
    return slot < 40 ? 'article' : slot < 65 ? 'listing' : slot < 80 ? 'section'
        : slot < 90 ? 'cms' : slot < 95 ? 'media' : slot < 99 ? 'save' : 'login';
}

async function run(file, port, concurrency, seconds, output) {
    assert([8, 24].includes(concurrency));
    assert(Number.isFinite(seconds) && seconds > 0 && seconds <= 3600);
    const tenants = JSON.parse(readFileSync(file, 'utf8'));
    const samples = [];
    let sequence = 0;
    const started = performance.now();
    const cpu = process.cpuUsage();
    const end = started + seconds * 1000;
    await Promise.all(Array.from({ length: concurrency }, async (_, lane) => {
        while (performance.now() < end) {
            const seq = sequence++;
            const t = tenants[Math.floor(seq / 100) % tenants.length];
            const post = t.posts[seq % t.posts.length];
            const kind = operation(seq);
            let path, token, method = 'GET', body;
            if (kind === 'article') path = post.path;
            if (kind === 'listing') path = `${base}/public/posts?count=10&page=${1 + seq % 2}`;
            if (kind === 'section') path = `${base}/public/posts?count=10&cat=${encodeURIComponent(post.category)}`;
            if (kind === 'cms') { path = `${base}/posts?per_page=25&page=${1 + seq % 3}`; token = t.token; }
            if (kind === 'media') { path = `${base}/media?per_page=25`; token = t.token; }
            if (kind === 'save') {
                path = `${base}/posts/${t.drafts[lane]}`; token = t.token; method = 'PUT';
                body = { excerpt: `Benchmark revision ${seq}`, body_blocks: [{ id: 'paragraph-1', type: 'paragraph',
                    config: { html: `<p>${'Texto editorial de prueba con contenido. '.repeat(100)} ${seq}</p>` } }] };
            }
            if (kind === 'login') { path = `${base}/auth/login`; method = 'POST'; body = t.login; }
            const begin = performance.now();
            try {
                const res = await request(t.host, port, path, token, method, body);
                const data = res.data;
                let valid = res.status === 200;
                if (kind === 'article') valid &&= data?.id === post.id && data.body_blocks?.length > 0;
                if (kind === 'listing' || kind === 'section') valid &&= Array.isArray(data) && data.length > 0;
                if (kind === 'cms' || kind === 'media') valid &&= (data?.data ?? data)?.length > 0;
                if (kind === 'save') valid &&= data?.id === t.drafts[lane] && data.status === 'draft' && data.excerpt === body.excerpt;
                if (kind === 'login') valid &&= typeof data?.token === 'string';
                samples.push({ kind, status: res.status, ok: Boolean(valid), ms: res.ms, bytes: res.bytes });
            } catch (error) {
                samples.push({ kind, status: error.message === 'timeout' ? 'timeout' : 'network',
                    ok: false, ms: performance.now() - begin, bytes: 0 });
            }
        }
    }));
    // A single monotonic interval, including the drain of requests already in flight.
    const elapsed = (performance.now() - started) / 1000;
    const used = process.cpuUsage(cpu);
    const result = { port, concurrency, duration_s: seconds, tenants: tenants.map(t => t.host),
        ...summarize(samples, elapsed), generator_cpu_percent: (used.user + used.system) / (elapsed * 10000),
        endpoints: Object.fromEntries([...new Set(samples.map(s => s.kind))].map(kind =>
            [kind, summarize(samples.filter(s => s.kind === kind), elapsed)])),
        failures: samples.filter(s => !s.ok).slice(0, 20), samples };
    writeFileSync(output, JSON.stringify(result));
    console.log(JSON.stringify({ port, concurrency, rps: result.rps, p95_ms: result.p95_ms,
        errors: result.errors, generator_cpu_percent: result.generator_cpu_percent }));
    if (result.errors) process.exitCode = 1;
}

function report(directory) {
    const groups = new Map();
    for (const file of readdirSync(directory)) {
        const match = file.match(/^(\d+)-(app|fpm)-c(8|24)\.json$/);
        if (!match) continue;
        const key = `${match[2]}-c${match[3]}`;
        if (!groups.has(key)) groups.set(key, []);
        groups.get(key).push(JSON.parse(readFileSync(join(directory, file), 'utf8')));
    }
    assert(groups.size, 'No completed measurements');
    const median = values => {
        const sorted = values.toSorted((a, b) => a - b);
        return (sorted[Math.floor((sorted.length - 1) / 2)] + sorted[Math.floor(sorted.length / 2)]) / 2;
    };
    const result = Object.fromEntries([...groups].map(([key, runs]) => [key, {
        rounds: runs.length, requests: runs.reduce((n, r) => n + r.requests, 0),
        errors: runs.reduce((n, r) => n + r.errors, 0),
        rps_median: median(runs.map(r => r.rps)), rps_min: Math.min(...runs.map(r => r.rps)),
        rps_max: Math.max(...runs.map(r => r.rps)), p95_ms_median: median(runs.map(r => r.p95_ms)),
        p99_ms_median: median(runs.map(r => r.p99_ms)),
        generator_cpu_peak: Math.max(...runs.map(r => r.generator_cpu_percent)),
        endpoints: Object.fromEntries(Object.keys(runs[0].endpoints).map(kind => [kind, {
            p95_ms_median: median(runs.map(r => r.endpoints[kind].p95_ms)),
            mean_bytes: median(runs.map(r => r.endpoints[kind].mean_bytes)),
            requests: runs.reduce((n, r) => n + r.endpoints[kind].requests, 0),
            errors: runs.reduce((n, r) => n + r.endpoints[kind].errors, 0),
        }])),
    }]));
    writeFileSync(join(directory, 'report.json'), JSON.stringify(result, null, 2));
    console.log(JSON.stringify(result, null, 2));
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    const [mode, ...args] = process.argv.slice(2);
    try {
        if (mode === 'prepare') await prepare(args[0], args[1], args[2], args.slice(3).map(Number));
        else if (mode === 'run') await run(args[0], +args[1], +args[2], +args[3], args[4]);
        else if (mode === 'report') report(args[0]);
        else throw new Error('Expected prepare, run or report');
    } finally {
        for (const agent of agents.values()) agent.destroy();
    }
}
