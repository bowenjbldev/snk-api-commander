import type { ReactNode } from 'react'
import { Button } from '@carbon/react'
import { WarningAlt } from '@carbon/icons-react'
import { Link, useNavigate } from 'react-router-dom'
import {
  Card,
  CopyableCode,
  KV,
  LiveCountdown,
  MaskedSecret,
  Mono,
  QuotaBar,
  RelativeTime,
  quotaFraction,
  quotaTone,
} from '../../components/ui'
import type { QuotaTone } from '../../components/ui'
import type { Deployment, Quota, QuotaMetric } from '../../types'
import { dateTimeUTC, fmtInt, monthYear, pct } from '../../lib/format'
import { getClient, getSubscription } from '../../data/dataset'
import { useModals } from '../../components/modals/ModalHost'
import { useToast } from '../../notify/ToastProvider'

const METRIC_LABEL: Record<QuotaMetric, string> = {
  requests_day: 'requests / day',
  disk_db_mb: 'database disk',
  disk_storage_mb: 'object storage',
  users_total: 'users',
}

const TONE_COLOR: Record<QuotaTone, string> = {
  normal: 'var(--cds-text-helper)',
  warn: 'var(--sc-support-warning)',
  over: 'var(--sc-support-error)',
  unlimited: 'var(--cds-text-helper)',
}

function StateCard({ dep }: { dep: Deployment }) {
  const m = useModals()
  const { status, reason, message, graceUntil, changedBy, changedAt } = dep.state

  if (status === 'grace' && graceUntil) {
    return (
      <Card accent="var(--sc-support-warning)">
        <div style={{ display: 'flex', gap: 24, flexWrap: 'wrap' }}>
          <div style={{ flex: '1 1 320px', minWidth: 0 }}>
            <div
              style={{
                display: 'flex',
                alignItems: 'center',
                gap: 8,
                fontSize: 16,
                fontWeight: 600,
              }}
            >
              <WarningAlt style={{ color: 'var(--sc-support-warning)' }} />
              Grace period — payment overdue
            </div>

            <div
              style={{
                display: 'grid',
                gridTemplateColumns: 'repeat(3, 1fr)',
                gap: 16,
                marginTop: 16,
              }}
            >
              <Field label="Reason">
                <Mono>{reason}</Mono>
              </Field>
              <Field label="Deadline">
                <Mono>{dateTimeUTC(graceUntil)}</Mono>
              </Field>
              <Field label="Changed by">
                <span>
                  {changedBy} · <RelativeTime iso={changedAt} />
                </span>
              </Field>
            </div>

            <div style={{ marginTop: 16 }}>
              <div
                style={{ fontSize: 12, color: 'var(--cds-text-helper)' }}
              >
                Client-facing message (returned with HTTP 402 after deadline)
              </div>
              <p
                style={{
                  margin: '6px 0 0',
                  fontSize: 13,
                  fontStyle: 'italic',
                  color: 'var(--cds-text-secondary)',
                }}
              >
                “{message}”
              </p>
            </div>

            <div
              style={{
                display: 'flex',
                gap: 8,
                marginTop: 20,
                flexWrap: 'wrap',
              }}
            >
              <Button
                kind="primary"
                size="sm"
                onClick={() => m.openResume(dep)}
              >
                Resume now
              </Button>
              <Button
                kind="tertiary"
                size="sm"
                onClick={() => m.openGrace(dep, 'extend')}
              >
                Extend grace…
              </Button>
              <Button
                kind="danger--tertiary"
                size="sm"
                onClick={() => m.openSuspend(dep)}
              >
                Suspend now
              </Button>
            </div>
          </div>

          <div
            style={{
              flex: '0 0 auto',
              borderLeft: '1px solid var(--cds-border-subtle)',
              paddingLeft: 24,
              display: 'flex',
              flexDirection: 'column',
              justifyContent: 'center',
            }}
          >
            <div style={{ fontSize: 12, color: 'var(--cds-text-helper)' }}>
              Suspends in
            </div>
            <div style={{ marginTop: 6 }}>
              <LiveCountdown deadlineIso={graceUntil} />
            </div>
            <div
              style={{
                fontSize: 12,
                color: 'var(--cds-text-helper)',
                marginTop: 6,
              }}
            >
              users will receive HTTP 402
            </div>
          </div>
        </div>
      </Card>
    )
  }

  if (status === 'suspended') {
    return (
      <Card accent="var(--sc-support-error)">
        <div
          style={{
            display: 'flex',
            alignItems: 'center',
            gap: 8,
            fontSize: 16,
            fontWeight: 600,
          }}
        >
          <WarningAlt style={{ color: 'var(--sc-support-error)' }} />
          Suspended
        </div>
        <div
          style={{
            display: 'grid',
            gridTemplateColumns: 'repeat(3, 1fr)',
            gap: 16,
            marginTop: 16,
          }}
        >
          <Field label="Reason">
            <Mono>{reason}</Mono>
          </Field>
          <Field label="Changed by">
            <span>
              {changedBy} · <RelativeTime iso={changedAt} />
            </span>
          </Field>
          <div />
        </div>
        <div style={{ marginTop: 16 }}>
          <div style={{ fontSize: 12, color: 'var(--cds-text-helper)' }}>
            Client-facing message (returned with HTTP 402)
          </div>
          <p
            style={{
              margin: '6px 0 0',
              fontSize: 13,
              fontStyle: 'italic',
              color: 'var(--cds-text-secondary)',
            }}
          >
            “{message}”
          </p>
        </div>
        <div style={{ marginTop: 20 }}>
          <Button kind="primary" size="sm" onClick={() => m.openResume(dep)}>
            Resume now
          </Button>
        </div>
      </Card>
    )
  }

  // active
  return (
    <Card accent="var(--sc-support-success)">
      <div style={{ fontSize: 16, fontWeight: 600 }}>Operational</div>
      <div
        style={{
          fontSize: 13,
          color: 'var(--cds-text-helper)',
          marginTop: 6,
        }}
      >
        Serving normally.
      </div>
    </Card>
  )
}

function Field({
  label,
  children,
}: {
  label: string
  children: ReactNode
}) {
  return (
    <div style={{ minWidth: 0 }}>
      <div style={{ fontSize: 12, color: 'var(--cds-text-helper)' }}>
        {label}
      </div>
      <div style={{ fontSize: 13, marginTop: 4 }}>{children}</div>
    </div>
  )
}

function QuotaGauge({ q }: { q: Quota }) {
  const tone = quotaTone(q.current, q.limit)
  const frac = quotaFraction(q.current, q.limit)
  return (
    <div style={{ minWidth: 0 }}>
      <div
        style={{
          display: 'flex',
          justifyContent: 'space-between',
          alignItems: 'baseline',
          gap: 8,
        }}
      >
        <Mono size={12}>{METRIC_LABEL[q.metric]}</Mono>
        {tone === 'unlimited' ? (
          <span style={{ fontSize: 11, color: 'var(--cds-text-helper)' }}>
            no limit
          </span>
        ) : (
          <Mono size={12} color={TONE_COLOR[tone]}>
            {pct(frac)}
          </Mono>
        )}
      </div>
      <div style={{ margin: '8px 0' }}>
        <QuotaBar current={q.current} limit={q.limit} width="100%" height={6} />
      </div>
      <Mono size={11} color="var(--cds-text-helper)">
        {fmtInt(q.current)} / {q.limit == null ? '∞' : fmtInt(q.limit)}
      </Mono>
    </div>
  )
}

export function OverviewTab({
  dep,
  quotas,
}: {
  dep: Deployment
  quotas: Quota[]
}) {
  const navigate = useNavigate()
  const toast = useToast()
  const m = useModals()
  const client = getClient(dep.clientId)
  const sub = getSubscription(dep.subscriptionId)
  const depQuotas = quotas.filter((q) => q.deploymentId === dep.id)

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 24 }}>
      <StateCard dep={dep} />

      <div
        style={{
          display: 'grid',
          gridTemplateColumns: 'repeat(2, 1fr)',
          gap: 24,
        }}
      >
        <Card title="Details">
          <KV label="Client">
            {client ? (
              <Link
                to={`/clients?focus=${dep.clientId}`}
                style={{ color: 'var(--cds-link-primary)' }}
              >
                {client.name}
              </Link>
            ) : (
              dep.clientName
            )}
          </KV>
          <KV label="Package">
            <Mono>
              {dep.packageName} {dep.packageVersion}
            </Mono>
          </KV>
          <KV label="Subscription">
            {sub ? (
              <Link
                to="/subscriptions"
                style={{ color: 'var(--cds-link-primary)' }}
              >
                {sub.plan} · ${sub.pricePerMonth}/mo
              </Link>
            ) : (
              '—'
            )}
          </KV>
          <KV label="Created">{monthYear(dep.createdAt)}</KV>
          <KV label="Last seen">
            <RelativeTime iso={dep.lastSeen} />
          </KV>
          <div style={{ marginTop: 16 }}>
            <div
              className="sc-mono"
              style={{
                fontSize: 11,
                color: 'var(--cds-text-helper)',
                marginBottom: 6,
              }}
            >
              base_url
            </div>
            <CopyableCode value={dep.baseUrl} />
          </div>
        </Card>

        <Card title="Control secret">
          <p
            style={{
              margin: 0,
              fontSize: 13,
              color: 'var(--cds-text-secondary)',
            }}
          >
            Authenticates control-plane calls (suspend / grace / resume) against
            this deployment.
          </p>
          <div style={{ marginTop: 14 }}>
            <MaskedSecret
              last4={dep.controlSecretLast4}
              onCopy={() => toast.success('Secret copied')}
            />
          </div>
          <div
            className="sc-mono"
            style={{
              fontSize: 11,
              color: 'var(--cds-text-helper)',
              marginTop: 10,
            }}
          >
            last rotated <RelativeTime iso={dep.secretRotatedAt} mono />
          </div>
          <div style={{ marginTop: 14 }}>
            <Button
              kind="tertiary"
              size="sm"
              onClick={() => m.openRotateSecret(dep)}
            >
              Rotate secret…
            </Button>
          </div>
          <div
            style={{
              fontSize: 12,
              color: 'var(--cds-text-helper)',
              marginTop: 10,
            }}
          >
            Rotating immediately invalidates the previous secret. Requires typed
            confirmation.
          </div>
        </Card>
      </div>

      <Card
        title="Quotas"
        right={
          <a
            href={`/quotas?deployment=${dep.id}`}
            onClick={(e) => {
              e.preventDefault()
              navigate(`/quotas?deployment=${dep.id}`)
            }}
            style={{
              fontSize: 13,
              color: 'var(--cds-link-primary)',
              cursor: 'pointer',
            }}
          >
            Edit limits →
          </a>
        }
      >
        {depQuotas.length === 0 ? (
          <div style={{ fontSize: 13, color: 'var(--cds-text-helper)' }}>
            No quotas configured.
          </div>
        ) : (
          <div
            style={{
              display: 'grid',
              gridTemplateColumns: 'repeat(4, 1fr)',
              gap: 24,
            }}
          >
            {depQuotas.map((q) => (
              <QuotaGauge key={q.id} q={q} />
            ))}
          </div>
        )}
      </Card>
    </div>
  )
}
