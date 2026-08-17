import type { UsageBucket } from '@services/api/types';

export interface SeriesPoint {
  group: string;
  date: string;
  value: number;
}

/**
 * Agrega buckets horarios en las tres series del gráfico de tráfico.
 * Varias instancias comparten bucket, así que se suman antes de dibujar.
 */
export function usageSeries(buckets: UsageBucket[]): SeriesPoint[] {
  const byBucket = new Map<string, { requests: number; e4xx: number; e5xx: number }>();
  for (const b of buckets) {
    const acc = byBucket.get(b.bucket) ?? { requests: 0, e4xx: 0, e5xx: 0 };
    acc.requests += b.requests;
    acc.e4xx += b.e4xx;
    acc.e5xx += b.e5xx;
    byBucket.set(b.bucket, acc);
  }
  const dates = [...byBucket.keys()].sort();
  return dates.flatMap((date) => {
    const v = byBucket.get(date)!;
    return [
      { group: 'Requests', date, value: v.requests },
      { group: '4xx', date, value: v.e4xx },
      { group: '5xx', date, value: v.e5xx },
    ];
  });
}
