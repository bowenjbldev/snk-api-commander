import { useMemo, type ReactNode } from 'react'
import { useStore, useProvisionedPackages } from '../store/store'
import { GraceCountdown } from '../components/GraceCountdown'
import { formatCurrency, formatPercent } from './format'

// Shared status tone for the dense triage rows. The tint tokens carry both themes, so a pill stays
// legible in g100 and g10 (and in the always-dark header).
export type Tone = 'red' | 'amber' | 'green' | 'blue'

export function toneTint(tone: Tone): { bg: string; fg: string } {
  switch (tone) {
    case 'red':
      return { bg: 'var(--scns-tag-red-bg)', fg: 'var(--scns-tag-red-text)' }
    case 'amber':
      return { bg: 'var(--scns-amber-bg)', fg: 'var(--scns-amber-text)' }
    case 'green':
      return { bg: 'var(--scns-method-green-bg)', fg: 'var(--scns-method-green-text)' }
    case 'blue':
      return { bg: 'var(--scns-method-blue-bg)', fg: 'var(--scns-method-blue-text)' }
  }
}

export interface AttentionItem {
  id: string
  tone: Tone
  /** Source group the item belongs to (Carbon notification panel groups by source). */
  group: string
  label: string
  entity: string
  desc: ReactNode
  metric: ReactNode
  to: string
}

// Operator triage feed: quota overages, grace windows closing and overdue invoices, derived live
// from the store. Surfaced by the header notifications bell (and reusable anywhere a count or list
// of "things needing attention" is wanted).
export function useAttentionItems(): AttentionItem[] {
  const packages = useProvisionedPackages()
  const invoices = useStore((s) => s.invoices)
  const quotas = useStore((s) => s.quotas)

  return useMemo(() => {
    const packageName = (id: number | undefined) =>
      id == null ? 'Unknown package' : packages.find((p) => p.id === id)?.name ?? `package ${id}`

    const items: AttentionItem[] = []

    for (const q of quotas) {
      if (q.limit_value != null && q.current_value / q.limit_value > 1) {
        const pkg = packages.find((p) => p.id === q.package_id)
        items.push({
          id: `quota-${q.id}`,
          tone: 'red',
          group: 'Quotas',
          label: 'Quota over',
          entity: packageName(q.package_id),
          desc: `${q.metric_key} over limit`,
          metric: formatPercent(q.current_value / q.limit_value),
          to: pkg ? `/packages/${pkg.id}/quotas` : '/packages',
        })
      }
    }

    for (const p of packages) {
      if (p.status === 'grace' && p.grace_until) {
        items.push({
          id: `grace-${p.id}`,
          tone: 'amber',
          group: 'Grace periods',
          label: 'Grace ends',
          entity: p.name,
          desc: p.reason ?? 'payment overdue',
          metric: <GraceCountdown until={p.grace_until} mode="short" />,
          to: `/packages/${p.id}`,
        })
      }
    }

    for (const i of invoices) {
      if (i.status === 'overdue') {
        items.push({
          id: `invoice-${i.id}`,
          tone: 'red',
          group: 'Billing',
          label: 'Overdue',
          entity: i.number,
          desc: `linked ${packageName(i.package_id)} ${i.enforcement === 'suspended' ? 'suspended' : 'in grace'}`,
          metric: formatCurrency(i.amount),
          to: '/invoices',
        })
      }
    }

    // Carbon notification-pattern guidance: group/order by urgency. Sort by tone severity so the
    // most critical (red) surface first; Array.sort is stable, so same-tone items keep their
    // insertion (by-source) order.
    const severity: Record<Tone, number> = { red: 0, amber: 1, blue: 2, green: 3 }
    return items.sort((a, b) => severity[a.tone] - severity[b.tone])
  }, [quotas, packages, invoices])
}
