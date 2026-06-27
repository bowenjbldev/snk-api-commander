import { useEffect, useMemo, useState } from 'react'
import { CreateTearsheet, CreateTearsheetStep } from '@carbon/ibm-products'
import { Dropdown, RadioButton, RadioButtonGroup, TextInput } from '@carbon/react'
import { useStore } from '../store/store'
import { useToast } from '../providers/ToastProvider'
import { formatCurrencyShort } from '../lib/format'
import type { Plan } from '../store/types'

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

function slug(s: string): string {
  return s.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '')
}

// Wide multi-step create flow (Carbon CreateTearsheet) for provisioning a package instance:
// Plan → Client → Config. Submits the same POST /provisioning payload as the old ProvisionModal,
// but split across steps with the left progress influencer. Only package types that have an active
// plan are offered (a plan implies the type exists on disk; scaffolding new types is a CLI concern).
export function PackageCreateTearsheet({ open, onClose }: { open: boolean; onClose: () => void }) {
  const plans = useStore((s) => s.plans)
  const clients = useStore((s) => s.clients)
  const provision = useStore((s) => s.provision)
  const toast = useToast()

  const activePlans = useMemo(() => plans.filter((p) => p.status === 'active'), [plans])
  const packageTypes = useMemo(() => [...new Set(activePlans.map((p) => p.package))].sort(), [activePlans])

  const [pkgType, setPkgType] = useState<string | null>(null)
  const [selectedPlan, setSelectedPlan] = useState<Plan | null>(null)
  const [clientMode, setClientMode] = useState<'existing' | 'new'>('existing')
  const [clientId, setClientId] = useState<number | ''>('')
  const [newName, setNewName] = useState('')
  const [newEmail, setNewEmail] = useState('')
  const [newBillingEmail, setNewBillingEmail] = useState('')
  const [pkgSlug, setPkgSlug] = useState('')

  // Reset to a clean flow once the tearsheet has closed (it stays mounted to animate out).
  useEffect(() => {
    if (open) return
    setPkgType(null); setSelectedPlan(null); setClientMode(clients.length ? 'existing' : 'new')
    setClientId(clients[0]?.id ?? ''); setNewName(''); setNewEmail(''); setNewBillingEmail(''); setPkgSlug('')
  }, [open, clients])

  const typePlans = useMemo(
    () => (pkgType ? activePlans.filter((p) => p.package === pkgType) : []),
    [activePlans, pkgType],
  )

  const newNameError = !newName.trim()
  const newEmailError = !newEmail.trim() || !EMAIL_RE.test(newEmail)

  const planStepInvalid = !selectedPlan
  const clientStepInvalid =
    clientMode === 'existing' ? !clientId : newNameError || newEmailError

  const submit = async () => {
    if (!selectedPlan) return
    const pathSlug = slug(pkgSlug) || undefined
    const input =
      clientMode === 'existing'
        ? { plan_id: selectedPlan.id, client_id: clientId || undefined, slug: pathSlug }
        : {
            plan_id: selectedPlan.id,
            slug: pathSlug,
            client: {
              slug: slug(newName),
              name: newName.trim(),
              email: newEmail.trim(),
              billing_email: newBillingEmail.trim() || undefined,
            },
          }
    try {
      await provision(input)
      toast.success('Instance provisioned', `${selectedPlan.package} · ${selectedPlan.name} is live`)
      onClose()
    } catch (e) {
      toast.error('Provisioning failed', e instanceof Error ? e.message : 'Request failed')
    }
  }

  const selectedClient = clients.find((c) => c.id === clientId) ?? null

  return (
    <CreateTearsheet
      className="scns-create-flow"
      open={open}
      title="Create package"
      description="Provision a package instance for a client from an active plan."
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
        title="Plan"
        subtitle="Pick the package type and the plan to provision."
        fieldsetLegendText="Plan selection"
        fieldsetLegendId="pct-legend-plan"
        disableSubmit={planStepInvalid}
      >
        <div style={{ display: 'flex', flexDirection: 'column', gap: 16, maxWidth: 480 }}>
          <Dropdown
            id="pct-type"
            titleText="Package type"
            label="Select a package type"
            items={packageTypes}
            selectedItem={pkgType}
            itemToString={(t) => t ?? ''}
            onChange={({ selectedItem }) => {
              setPkgType(selectedItem ?? null)
              setSelectedPlan(null) // plans are scoped to the type
            }}
          />
          <Dropdown
            id="pct-plan"
            titleText="Plan"
            label={pkgType ? 'Select a plan' : 'Select a package type first'}
            disabled={!pkgType}
            items={typePlans}
            selectedItem={selectedPlan}
            itemToString={(p) => (p ? `${p.name} — ${formatCurrencyShort(p.price_monthly)}/mo` : '')}
            onChange={({ selectedItem }) => setSelectedPlan(selectedItem ?? null)}
          />
        </div>
      </CreateTearsheetStep>

      <CreateTearsheetStep
        title="Client"
        subtitle="Provision for an existing client or create a new one."
        fieldsetLegendText="Client selection"
        fieldsetLegendId="pct-legend-client"
        disableSubmit={clientStepInvalid}
      >
        <div style={{ display: 'flex', flexDirection: 'column', gap: 16, maxWidth: 480 }}>
          <RadioButtonGroup
            legendText="Client"
            name="pct-client-mode"
            valueSelected={clientMode}
            onChange={(v) => setClientMode(v as 'existing' | 'new')}
          >
            <RadioButton labelText="Existing client" value="existing" id="pct-existing" disabled={clients.length === 0} />
            <RadioButton labelText="New client" value="new" id="pct-new" />
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
        </div>
      </CreateTearsheetStep>

      <CreateTearsheetStep
        title="Config"
        subtitle="Optional slug, then review and create."
        fieldsetLegendText="Configuration"
        fieldsetLegendId="pct-legend-config"
      >
        <div style={{ display: 'flex', flexDirection: 'column', gap: 16, maxWidth: 480 }}>
          <TextInput
            id="pct-slug"
            labelText="Package slug (optional)"
            placeholder="auto from package name"
            value={pkgSlug}
            onChange={(e) => setPkgSlug(e.target.value)}
            helperText="Path segment of the API (/api/v1/<slug>). The host comes from the client. Leave blank to let the backend derive it."
          />
          <div className="scns-cardtable" style={{ border: '1px solid var(--scns-border)' }}>
            <ReviewRow label="Package" value={pkgType ?? '—'} />
            <ReviewRow label="Plan" value={selectedPlan ? `${selectedPlan.name} · ${formatCurrencyShort(selectedPlan.price_monthly)}/mo` : '—'} />
            <ReviewRow
              label="Client"
              value={clientMode === 'existing' ? selectedClient?.name ?? '—' : `${newName.trim() || '—'} (new)`}
            />
          </div>
        </div>
      </CreateTearsheetStep>
    </CreateTearsheet>
  )
}

function ReviewRow({ label, value }: { label: string; value: string }) {
  return (
    <div style={{ display: 'flex', justifyContent: 'space-between', gap: 16, padding: '10px 16px', borderBottom: '1px solid var(--scns-border)' }}>
      <span className="scns-helper" style={{ fontSize: 13 }}>{label}</span>
      <span style={{ fontSize: 13, fontWeight: 600 }}>{value}</span>
    </div>
  )
}
