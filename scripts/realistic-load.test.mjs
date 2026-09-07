import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { operation, summarize } from './realistic-load.mjs';

test('global throughput uses valid responses / wall time and every failure remains visible', () => {
    const summary = summarize([
        { ms: 10, ok: true, bytes: 100 }, { ms: 100, ok: false, bytes: 0 },
        { ms: 20, ok: true, bytes: 100 }, { ms: 30, ok: false, bytes: 20 },
    ], 2);
    assert.equal(summary.rps, 1);
    assert.equal(summary.errors, 2);
    assert.equal(summary.p50_ms, 20);
    assert.equal(summary.p95_ms, 100);
    const weights = {};
    for (let i = 0; i < 100; i++) weights[operation(i)] = (weights[operation(i)] ?? 0) + 1;
    assert.deepEqual(weights, { article: 40, listing: 25, section: 15, cms: 10, media: 5, save: 4, login: 1 });
});

test('cleanup visits every tenant even when the database client consumes stdin', t => {
    const directory = mkdtempSync(join(tmpdir(), 'realistic-cleanup-test-'));
    t.after(() => rmSync(directory, { recursive: true }));
    writeFileSync(join(directory, 'users.tsv'), 'acme_demo_demo_app\t1\ndiario_norte_kilkana\t2\ndemo_kilkana\t3\n');
    const result = spawnSync('bash', ['-c', `
        source scripts/realistic-ab.sh
        RUN_ID=test
        BENCH_USERS_FILE="$1/users.tsv"
        RUNTIME_CHANGED=0
        FPM_WAS_RUNNING=1
        dbq() { cat >/dev/null; echo "database:$*" >&2; [[ "$1" != -N ]] || echo 0; return 0; }
        cleanup_records() { :; }
        logout_tokens() { :; }
        cleanup_benchmark_identities() { :; }
        true
        restore_realistic
    `, 'cleanup-test', directory], { cwd: new URL('..', import.meta.url), encoding: 'utf8' });
    assert.equal(result.status, 0, result.stderr);
    for (const database of ['diario_norte_kilkana', 'demo_kilkana']) {
        assert.match(result.stderr, new RegExp('database:' + database + ' -e START TRANSACTION'));
    }
    assert.match(result.stderr, /database:-N demo_kilkana -e SELECT COUNT\(\*\) FROM users/);
});
