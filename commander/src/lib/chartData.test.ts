import { describe, it, expect } from 'vitest'
import { rollupsToUsageSeries, snapshotsToPlatformSeries } from './chartData'
import type { UsageRollup, UsageSnapshot } from '../store/types'

const MB = 1024 * 1024
const A = '2026-06-15T10:00:00Z'
const B = '2026-06-15T11:00:00Z'

function row(p: Partial<UsageRollup>): UsageRollup {
  return {
    period: 'hour',
    period_start: A,
    route: 'GET /x',
    method: 'GET',
    status_class: '2xx',
    request_count: 0,
    error_count: 0,
    avg_duration_ms: 0,
    p95_duration_ms: 0,
    total_request_bytes: 0,
    total_response_bytes: 0,
    unique_users: 0,
    ...p,
  }
}

describe('rollupsToUsageSeries', () => {
  it('buckets by period_start, computes error %, weighted latency, MB transfer', () => {
    const rollups: UsageRollup[] = [
      row({ period_start: A, status_class: '2xx', request_count: 80, avg_duration_ms: 100, p95_duration_ms: 200, total_request_bytes: MB, total_response_bytes: 2 * MB }),
      row({ period_start: A, status_class: '4xx', request_count: 20, error_count: 20, avg_duration_ms: 300, p95_duration_ms: 500, total_request_bytes: MB }),
      row({ period_start: B, status_class: '5xx', request_count: 10, error_count: 10, avg_duration_ms: 50, p95_duration_ms: 90 }),
    ]
    const s = rollupsToUsageSeries(rollups)

    // Requests bucketed chronologically: A=100, B=10.
    expect(s.requests.map((p) => p.value)).toEqual([100, 10])
    // 4xx at A = 20/100 = 20%.
    expect(s.errorRate.find((p) => p.group === '4xx')?.value).toBe(20)
    // Weighted avg latency at A = (80*100 + 20*300)/100 = 140; p95 = max(200,500) = 500.
    expect(s.latency.find((p) => p.group === 'avg')?.value).toBe(140)
    expect(s.latency.find((p) => p.group === 'p95')?.value).toBe(500)
    // Transfer in at A = 2 MB total_request_bytes ÷ 1 MB = 2.
    expect(s.transfer.find((p) => p.group === 'in')?.value).toBe(2)
    // KPIs across all rows: 110 requests, 30 errors.
    expect(s.kpis.totalRequests).toBe(110)
    expect(s.kpis.errorRate).toBeCloseTo(30 / 110, 5)
  })

  it('returns empty series and zero KPIs for no rollups', () => {
    const s = rollupsToUsageSeries([])
    expect(s.requests).toEqual([])
    expect(s.kpis.totalRequests).toBe(0)
    expect(s.kpis.errorRate).toBe(0)
    expect(s.topEndpoints).toEqual([])
  })

  it('aggregates top endpoints by route+method, sorted by requests', () => {
    const rollups: UsageRollup[] = [
      row({ route: 'GET /a', method: 'GET', request_count: 5, error_count: 1, p95_duration_ms: 100 }),
      row({ route: 'GET /a', method: 'GET', request_count: 15, p95_duration_ms: 300, period_start: B }),
      row({ route: 'POST /b', method: 'POST', request_count: 8, error_count: 4, p95_duration_ms: 50 }),
    ]
    const s = rollupsToUsageSeries(rollups)
    expect(s.topEndpoints[0]).toMatchObject({ route: 'GET /a', method: 'GET', requests: 20, p95_ms: 300 })
    expect(s.topEndpoints[0].error_rate).toBeCloseTo(1 / 20, 5)
  })
})

describe('snapshotsToPlatformSeries', () => {
  const snap = (package_id: number, period_start: string, request_count: number, c4 = 0, c5 = 0): UsageSnapshot => ({
    package_id,
    period_start,
    request_count,
    error_count: c4 + c5,
    count_4xx: c4,
    count_5xx: c5,
  })

  it('stacks the top-4 packages + other and computes per-hour error %', () => {
    const snapshots: UsageSnapshot[] = [
      snap(1, A, 100, 10),
      snap(2, A, 80),
      snap(3, A, 60),
      snap(4, A, 40),
      snap(5, A, 20), // 5th → folds into 'other'
      snap(1, B, 50, 5),
    ]
    const s = snapshotsToPlatformSeries(snapshots, new Map([
      [1, 'Shop'],
      [2, 'Blog'],
      [3, 'Crm'],
      [4, 'Billing'],
      [5, 'Archive'],
    ]))

    expect(s.requests.groups).toEqual(['Shop', 'Blog', 'Crm', 'Billing', 'other'])
    const atA = s.requests.data.filter((p) => +p.date === +new Date(A))
    expect(atA.find((p) => p.group === 'Shop')?.value).toBe(100)
    expect(atA.find((p) => p.group === 'other')?.value).toBe(20) // Archive
    // error rate at A: 10 4xx / 300 total × 100, and no 5xx.
    const errA = s.errorRate.filter((p) => +p.date === +new Date(A))
    expect(errA.find((p) => p.group === '4xx')?.value).toBeCloseTo((10 / 300) * 100, 5)
    expect(errA.find((p) => p.group === '5xx')?.value).toBeCloseTo(0, 5)
  })

  it('returns empty data for no snapshots', () => {
    const s = snapshotsToPlatformSeries([])
    expect(s.requests.data).toEqual([])
    expect(s.requests.groups).toEqual(['other'])
    expect(s.errorRate).toEqual([])
  })
})
