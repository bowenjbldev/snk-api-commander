import assert from 'node:assert/strict';
import { test } from 'node:test';
import { usageSeries } from './usageSeries.ts';
import type { UsageBucket } from '../../../services/api/types.ts';

test('usageSeries suma por bucket y devuelve las tres series ordenadas', () => {
  const buckets: UsageBucket[] = [
    { instance_id: 1, bucket: '2026-08-14T02:00:00.000Z', requests: 10, e4xx: 1, e5xx: 0 },
    { instance_id: 2, bucket: '2026-08-14T02:00:00.000Z', requests: 5, e4xx: 0, e5xx: 2 },
    { instance_id: 1, bucket: '2026-08-14T01:00:00.000Z', requests: 7, e4xx: 3, e5xx: 1 },
  ];

  const series = usageSeries(buckets);

  assert.equal(series.length, 6, 'dos buckets por tres series');
  assert.deepEqual(series[0], { group: 'Requests', date: '2026-08-14T01:00:00.000Z', value: 7 });
  const later = series.filter((p) => p.date === '2026-08-14T02:00:00.000Z');
  assert.deepEqual(
    later.map((p) => [p.group, p.value]),
    [
      ['Requests', 15],
      ['4xx', 1],
      ['5xx', 2],
    ],
    'las instancias del mismo bucket se agregan',
  );
});

test('sin buckets no hay serie, no una serie de ceros', () => {
  assert.deepEqual(usageSeries([]), []);
});
