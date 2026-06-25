import { useEffect, useMemo, useState } from 'react'
import {
  Dropdown,
  InlineNotification,
  Modal,
  RadioButton,
  RadioButtonGroup,
  TextInput,
} from '@carbon/react'
import { useStore } from '../../store/store'
import { useToast } from '../../providers/ToastProvider'
import type { Plan } from '../../store/types'

interface Props {
  open: boolean
  /**
   * Business package this provisioning is scoped to. When set, only this package's plans are
   * offered and the backend derives the package from the chosen plan.
   */
  packageName?: string
  /**
   * Plans to offer (already package-scoped by the caller). Falls back to the full store catalog
   * when omitted.
   */
  plans?: Plan[]
  planId?: number // pre-select a plan (from a row action)
  /** Pre-select + lock an existing client (e.g. "New package for this client" from the client page). */
  lockClientId?: number
  onClose: () => void
  /** Fired after a successful provision (e.g. to refresh / close the empty-state). */
  onProvisioned?: () => void
}

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

function slug(s: string): string {
  return s.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '')
}

export function ProvisionModal({ open, packageName, plans, planId, lockClientId, onClose, onProvisioned }: Props) {
  const storePlans = useStore((s) => s.plans)
  const clients = useStore((s) => s.clients)
  const provision = useStore((s) => s.provision)
  const toast = useToast()

  // Package-scoped when a list is supplied, else the whole catalog. Always re-filter by packageName
  // (when given) so a caller passing mixed plans can't leak another package's offerings.
  const activePlans = useMemo(() => {
    const source = plans ?? storePlans
    return source.filter((p) => p.status === 'active' && (!packageName || p.package === packageName))
  }, [plans, storePlans, packageName])

  const [selectedPlan, setSelectedPlan] = useState<Plan | null>(null)
  const [clientMode, setClientMode] = useState<'existing' | 'new'>('existing')
  const [clientId, setClientId] = useState<number | ''>('')
  const [newName, setNewName] = useState('')
  const [newEmail, setNewEmail] = useState('')
  const [newBillingEmail, setNewBillingEmail] = useState('')
  const [pkgSlug, setPkgSlug] = useState('')
  const [submitted, setSubmitted] = useState(false)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    if (open) {
      setSelectedPlan(activePlans.find((p) => p.id === planId) ?? activePlans[0] ?? null)
      setClientMode(lockClientId != null || clients.length > 0 ? 'existing' : 'new')
      setClientId(lockClientId ?? clients[0]?.id ?? '')
      setNewName('')
      setNewEmail('')
      setNewBillingEmail('')
      setPkgSlug('')
      setSubmitted(false)
      setBusy(false)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, planId, lockClientId])

  if (!open) return null

  const planError = !selectedPlan ? 'Select a plan' : ''
  const newNameError = clientMode === 'new' && !newName.trim() ? 'Required' : ''
  const newEmailError =
    clientMode === 'new' ? (!newEmail.trim() ? 'Required' : !EMAIL_RE.test(newEmail) ? 'Invalid email' : '') : ''
  const existingError = clientMode === 'existing' && !clientId ? 'Select a client' : ''
  const valid = !planError && !newNameError && !newEmailError && !existingError

  const submit = async () => {
    setSubmitted(true)
    if (!valid || !selectedPlan) return
    setBusy(true)
    try {
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
      await provision(input)
      toast.success('Instance provisioned', `${selectedPlan.package} · ${selectedPlan.name} is live`)
      onProvisioned?.()
      onClose()
    } catch (e) {
      toast.error('Provisioning failed', e instanceof Error ? e.message : 'Request failed')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal
      open
      modalHeading="Provision package"
      modalLabel={packageName ? `Provisioning · ${packageName}` : 'Provisioning'}
      primaryButtonText={busy ? 'Provisioning…' : 'Create & provision'}
      primaryButtonDisabled={busy}
      secondaryButtonText="Cancel"
      onRequestClose={onClose}
      onRequestSubmit={submit}
    >
        <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
          {activePlans.length === 0 ? (
            <InlineNotification
              kind="info"
              lowContrast
              hideCloseButton
              title="No active plans"
              subtitle={
                packageName
                  ? 'Create an active plan for this package first (Plans tab).'
                  : 'Create a plan in the Catalog first.'
              }
              style={{ maxWidth: '100%' }}
            />
          ) : (
            <Dropdown
              id="prov-plan"
              titleText="Plan"
              label="Select a plan"
              items={activePlans}
              selectedItem={selectedPlan}
              itemToString={(p) =>
                p ? `${packageName ? '' : `${p.package} · `}${p.name} — $${p.price_monthly}/mo` : ''
              }
              onChange={({ selectedItem }) => setSelectedPlan(selectedItem ?? null)}
              invalid={submitted && !!planError}
              invalidText={planError}
            />
          )}

          {lockClientId == null && (
            <RadioButtonGroup
              legendText="Client"
              name="prov-client-mode"
              valueSelected={clientMode}
              onChange={(v) => setClientMode(v as 'existing' | 'new')}
            >
              <RadioButton labelText="Existing client" value="existing" id="prov-existing" disabled={clients.length === 0} />
              <RadioButton labelText="New client" value="new" id="prov-new" />
            </RadioButtonGroup>
          )}

          {clientMode === 'existing' ? (
            <Dropdown
              id="prov-client"
              titleText="Client"
              label="Select a client"
              disabled={lockClientId != null}
              items={clients}
              selectedItem={clients.find((c) => c.id === clientId) ?? null}
              itemToString={(c) => (c ? `${c.name} (${c.slug})` : '')}
              onChange={({ selectedItem }) => setClientId(selectedItem?.id ?? '')}
              invalid={submitted && !!existingError}
              invalidText={existingError}
            />
          ) : (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
              <TextInput id="prov-name" labelText="Client name" value={newName} onChange={(e) => setNewName(e.target.value)} invalid={submitted && !!newNameError} invalidText={newNameError} />
              <TextInput id="prov-email" labelText="Email" value={newEmail} onChange={(e) => setNewEmail(e.target.value)} invalid={submitted && !!newEmailError} invalidText={newEmailError} />
              <TextInput id="prov-billing" labelText="Billing email (optional)" value={newBillingEmail} onChange={(e) => setNewBillingEmail(e.target.value)} />
              <p className="scns-helper" style={{ fontSize: 12 }}>
                Add full billing details (tax id, address, terms) on the client page after creating.
              </p>
            </div>
          )}

          <TextInput
            id="prov-slug"
            labelText="Package slug (optional)"
            placeholder="auto from package name"
            value={pkgSlug}
            onChange={(e) => setPkgSlug(e.target.value)}
            helperText="Path segment of the API (/api/v1/<slug>). The host comes from the client. Leave blank to let the backend derive it."
          />
        </div>
    </Modal>
  )
}
