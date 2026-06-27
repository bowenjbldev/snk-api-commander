import { useEffect, useState, type ReactNode } from 'react'
import { CreateTearsheet, CreateTearsheetStep } from '@carbon/ibm-products'
import {
  Dropdown,
  FormGroup,
  NumberInput,
  RadioButton,
  RadioButtonGroup,
  Stack,
  StructuredListBody,
  StructuredListCell,
  StructuredListRow,
  StructuredListWrapper,
  Tag,
  TextArea,
  TextInput,
} from '@carbon/react'
import { useStore } from '../store/store'
import { useToast } from '../providers/ToastProvider'
import { api } from '../lib/api'
import { formatCurrencyShort, formatInt, formatMb } from '../lib/format'
import { baseDomain } from '../lib/package'
import type { Plan } from '../store/types'

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

function slug(s: string): string {
  return s.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '')
}
// '' → unlimited (null); a number → that limit.
function numOrNull(v: string): number | null {
  const t = v.trim()
  if (t === '') return null
  const n = Number(t)
  return Number.isFinite(n) && n >= 0 ? Math.round(n) : null
}

// Wide multi-step create flow (Carbon CreateTearsheet, left progress stepper) for standing up a new
// package: Client → Plan → Review. The Plan step is always a brand-new plan for a new package (the
// package name is typed; slug auto-derives from it). Submit creates the plan (POST /plans → real id),
// then provisions (POST /provisioning); a new client is created inline by the provisioning endpoint.
//
// NOTE: the package code must already exist on disk (`packages/<Client>/<Package>`, scaffolded via
// `php artisan make:package` by the dev) — provisioning verifies it. That scaffold step is manual.
export function PackageCreateTearsheet({ open, onClose }: { open: boolean; onClose: () => void }) {
  const clients = useStore((s) => s.clients)
  const provision = useStore((s) => s.provision)
  const toast = useToast()

  // Client
  const [clientMode, setClientMode] = useState<'existing' | 'new'>('existing')
  const [clientId, setClientId] = useState<number | ''>('')
  const [newName, setNewName] = useState('')
  const [newEmail, setNewEmail] = useState('')
  const [newBillingEmail, setNewBillingEmail] = useState('')
  // Plan (always new) — for a new package whose name is typed; slug auto-derives from it.
  const [pkgName, setPkgName] = useState('')
  const [planName, setPlanName] = useState('')
  const [planDesc, setPlanDesc] = useState('')
  const [planPrice, setPlanPrice] = useState('0')
  const [qReq, setQReq] = useState('')
  const [qDb, setQDb] = useState('')
  const [qStorage, setQStorage] = useState('')
  const [qUsers, setQUsers] = useState('')

  // Reset to a clean flow once the tearsheet has closed (stays mounted to animate out).
  useEffect(() => {
    if (open) return
    setClientMode(clients.length ? 'existing' : 'new'); setClientId(clients[0]?.id ?? '')
    setNewName(''); setNewEmail(''); setNewBillingEmail('')
    setPkgName(''); setPlanName(''); setPlanDesc(''); setPlanPrice('0')
    setQReq(''); setQDb(''); setQStorage(''); setQUsers('')
  }, [open, clients])

  const clientStepInvalid =
    clientMode === 'existing' ? !clientId : !newName.trim() || !EMAIL_RE.test(newEmail)
  const planStepInvalid = !pkgName.trim() || !planName.trim()

  const submit = async () => {
    if (clientStepInvalid || planStepInvalid) return
    // 1. Create the plan (provisioning only takes plan_id).
    const created = await api<Plan>('/plans', {
      method: 'POST',
      body: {
        slug: `${slug(pkgName)}-${slug(planName)}`,
        package: pkgName.trim(),
        name: planName.trim(),
        description: planDesc.trim() || null,
        price_monthly: Number(planPrice) || 0,
        status: 'active',
        q_requests_day: numOrNull(qReq),
        q_disk_db_mb: numOrNull(qDb),
        q_disk_storage_mb: numOrNull(qStorage),
        q_users_total: numOrNull(qUsers),
      },
    })

    // 2. Provision — slug auto-derives from the package name on the backend; a new client is
    // created inline by the endpoint.
    const input =
      clientMode === 'existing'
        ? { plan_id: created.id, client_id: clientId || undefined }
        : {
            plan_id: created.id,
            client: {
              slug: slug(newName),
              name: newName.trim(),
              email: newEmail.trim(),
              billing_email: newBillingEmail.trim() || undefined,
            },
          }
    try {
      await provision(input)
      toast.success('Instance provisioned', `${pkgName.trim()} · ${planName.trim()} is live`)
      onClose()
    } catch (e) {
      toast.error('Provisioning failed', e instanceof Error ? e.message : 'Request failed')
    }
  }

  const selectedClient = clients.find((c) => c.id === clientId) ?? null

  // Review derivations (instance isn't provisioned yet, so build the host/url/db from inputs).
  const clientSlug = clientMode === 'existing' ? selectedClient?.slug ?? '' : slug(newName)
  const pkgSlug = slug(pkgName)
  const baseUrl = clientSlug && pkgSlug ? `https://${clientSlug}.${baseDomain()}/api/v1/${pkgSlug}` : '—'
  const dbName = clientSlug && pkgSlug ? `${clientSlug}_${pkgSlug}` : '—'
  const quotaText = (v: string, fmt: (n: number) => string) => {
    const n = numOrNull(v)
    return n === null ? 'Unlimited' : fmt(n)
  }

  return (
    <CreateTearsheet
      className="scns-create-flow"
      open={open}
      title="Create package"
      description="Stand up a new package: a client, a plan, and the provisioned instance."
      label="Provisioning"
      influencerWidth="narrow"
      submitButtonText="Create package"
      cancelButtonText="Cancel"
      backButtonText="Back"
      nextButtonText="Next"
      onClose={onClose}
      onRequestSubmit={submit}
    >
      <CreateTearsheetStep
        title="Client"
        subtitle="Provision for an existing client or create a new one."
        fieldsetLegendText="Client selection"
        fieldsetLegendId="pct-legend-client"
        disableSubmit={clientStepInvalid}
      >
        <Stack gap={5} style={{ maxWidth: 480 }}>
          <RadioButtonGroup
            legendText="Client"
            name="pct-client-mode"
            valueSelected={clientMode}
            onChange={(v) => setClientMode(v as 'existing' | 'new')}
          >
            <RadioButton labelText="Existing client" value="existing" id="pct-c-existing" disabled={clients.length === 0} />
            <RadioButton labelText="New client" value="new" id="pct-c-new" />
          </RadioButtonGroup>

          {clientMode === 'existing' ? (
            <Dropdown
              id="pct-client"
              titleText="Client"
              label="Select a client"
              items={clients}
              selectedItem={selectedClient}
              itemToString={(c) => (c ? `${c.name} (${c.slug})` : '')}
              onChange={({ selectedItem }) => setClientId(selectedItem?.id ?? '')}
            />
          ) : (
            <>
              <TextInput id="pct-name" labelText="Client name" value={newName} onChange={(e) => setNewName(e.target.value)} />
              <TextInput id="pct-email" labelText="Email" value={newEmail} onChange={(e) => setNewEmail(e.target.value)} />
              <TextInput id="pct-billing" labelText="Billing email (optional)" value={newBillingEmail} onChange={(e) => setNewBillingEmail(e.target.value)} />
              <p className="scns-helper" style={{ fontSize: 12 }}>
                Add full billing details (tax id, address, terms) on the client page after creating.
              </p>
            </>
          )}
        </Stack>
      </CreateTearsheetStep>

      <CreateTearsheetStep
        title="Plan"
        subtitle="Name the new package and define its plan."
        fieldsetLegendText="Plan details"
        fieldsetLegendId="pct-legend-plan"
        disableSubmit={planStepInvalid}
      >
        <Stack gap={5} style={{ maxWidth: 480 }}>
          <TextInput
            id="pct-pkg"
            labelText="Package name"
            placeholder="Shop"
            value={pkgName}
            onChange={(e) => setPkgName(e.target.value)}
            helperText={
              pkgName.trim() ? (
                <>
                  API path <code>/api/v1/{slug(pkgName)}</code> · code must already exist on disk (make:package).
                </>
              ) : (
                "The package's code must already exist on disk (scaffolded via make:package)."
              )
            }
          />
          <TextInput id="pct-plan-name" labelText="Plan name" placeholder="Pro" value={planName} onChange={(e) => setPlanName(e.target.value)} />
          <TextArea id="pct-plan-desc" labelText="Description (optional)" rows={2} value={planDesc} onChange={(e) => setPlanDesc(e.target.value)} />
          <NumberInput id="pct-plan-price" label="Price / month" hideSteppers min={0} value={planPrice} onChange={(_e, { value }) => setPlanPrice(String(value ?? ''))} />
          <FormGroup legendText="Default quota limits · blank = unlimited">
            <Stack gap={5}>
              <NumberInput id="pct-q-req" label="Requests / day" hideSteppers value={qReq} onChange={(_e, { value }) => setQReq(String(value ?? ''))} />
              <NumberInput id="pct-q-db" label="DB size (MB)" hideSteppers value={qDb} onChange={(_e, { value }) => setQDb(String(value ?? ''))} />
              <NumberInput id="pct-q-storage" label="Storage (MB)" hideSteppers value={qStorage} onChange={(_e, { value }) => setQStorage(String(value ?? ''))} />
              <NumberInput id="pct-q-users" label="Users total" hideSteppers value={qUsers} onChange={(_e, { value }) => setQUsers(String(value ?? ''))} />
            </Stack>
          </FormGroup>
        </Stack>
      </CreateTearsheetStep>

      <CreateTearsheetStep
        title="Review"
        subtitle={`Confirm the details, then create. Provisions database ${dbName}, runs migrate + seed, and brings the instance live.`}
        hasFieldset={false}
      >
        <Stack gap={5} style={{ maxWidth: 480 }}>
          <ReviewSection title="Client">
            <ReviewRow
              label="Client"
              value={clientMode === 'existing' ? selectedClient?.name ?? '—' : newName.trim() || '—'}
              tag={clientMode === 'new' ? 'New' : undefined}
            />
            {clientMode === 'existing'
              ? selectedClient && <ReviewRow label="Slug" value={selectedClient.slug} mono />
              : (
                <>
                  <ReviewRow label="Email" value={newEmail.trim() || '—'} mono />
                  {newBillingEmail.trim() && <ReviewRow label="Billing email" value={newBillingEmail.trim()} mono />}
                </>
              )}
          </ReviewSection>

          <ReviewSection title="Package & plan">
            <ReviewRow label="Package" value={pkgName.trim() || '—'} />
            <ReviewRow label="Plan" value={planName.trim() || '—'} tag={planName.trim() ? 'New' : undefined} />
            <ReviewRow label="Price" value={`${formatCurrencyShort(Number(planPrice) || 0)}/mo`} mono />
            {planDesc.trim() && <ReviewRow label="Description" value={planDesc.trim()} />}
            <ReviewRow label="Base URL" value={baseUrl} mono />
          </ReviewSection>

          <ReviewSection title="Quota limits">
            <ReviewRow label="Requests / day" value={quotaText(qReq, formatInt)} mono />
            <ReviewRow label="Database" value={quotaText(qDb, formatMb)} mono />
            <ReviewRow label="Storage" value={quotaText(qStorage, formatMb)} mono />
            <ReviewRow label="Users" value={quotaText(qUsers, formatInt)} mono />
          </ReviewSection>
        </Stack>
      </CreateTearsheetStep>
    </CreateTearsheet>
  )
}

function ReviewSection({ title, children }: { title: string; children: ReactNode }) {
  return (
    <div>
      <p className="scns-section-label">{title}</p>
      <StructuredListWrapper isCondensed style={{ marginBottom: 0 }}>
        <StructuredListBody>{children}</StructuredListBody>
      </StructuredListWrapper>
    </div>
  )
}

function ReviewRow({ label, value, mono, tag }: { label: string; value: string; mono?: boolean; tag?: string }) {
  return (
    <StructuredListRow>
      <StructuredListCell noWrap style={{ width: 160, color: 'var(--cds-text-secondary)' }}>{label}</StructuredListCell>
      <StructuredListCell style={{ fontWeight: 600 }}>
        <span className={mono ? 'scns-mono' : undefined}>{value}</span>
        {tag && <Tag type="cool-gray" size="sm" style={{ marginInlineStart: 8 }}>{tag}</Tag>}
      </StructuredListCell>
    </StructuredListRow>
  )
}
