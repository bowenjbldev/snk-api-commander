import { useEffect, useMemo, useState } from 'react'
import {
  CodeSnippet,
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
  planId?: string // pre-select a plan (from the Catalog row action)
  onClose: () => void
}

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

function slug(s: string): string {
  return s.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '')
}

export function ProvisionModal({ open, planId, onClose }: Props) {
  const plans = useStore((s) => s.plans)
  const clients = useStore((s) => s.clients)
  const provision = useStore((s) => s.provision)
  const toast = useToast()

  const activePlans = useMemo(() => plans.filter((p) => p.status === 'active'), [plans])

  const [selectedPlan, setSelectedPlan] = useState<Plan | null>(null)
  const [clientMode, setClientMode] = useState<'existing' | 'new'>('existing')
  const [clientId, setClientId] = useState('')
  const [newName, setNewName] = useState('')
  const [newEmail, setNewEmail] = useState('')
  const [newBillingEmail, setNewBillingEmail] = useState('')
  const [submitted, setSubmitted] = useState(false)
  const [busy, setBusy] = useState(false)
  const [hostCommand, setHostCommand] = useState<string | null>(null)

  useEffect(() => {
    if (open) {
      setSelectedPlan(activePlans.find((p) => p.id === planId) ?? activePlans[0] ?? null)
      setClientMode(clients.length > 0 ? 'existing' : 'new')
      setClientId(clients[0]?.id ?? '')
      setNewName('')
      setNewEmail('')
      setNewBillingEmail('')
      setSubmitted(false)
      setBusy(false)
      setHostCommand(null)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, planId])

  if (!open) return null

  const planError = !selectedPlan ? 'Select a plan' : ''
  const newNameError = clientMode === 'new' && !newName.trim() ? 'Required' : ''
  const newEmailError =
    clientMode === 'new' ? (!newEmail.trim() ? 'Required' : !EMAIL_RE.test(newEmail) ? 'Invalid email' : '') : ''
  const existingError = clientMode === 'existing' && !clientId ? 'Select a client' : ''
  const valid = !planError && !newNameError && !newEmailError && !existingError

  const submit = async () => {
    if (hostCommand) {
      onClose()
      return
    }
    setSubmitted(true)
    if (!valid || !selectedPlan) return
    setBusy(true)
    try {
      const input =
        clientMode === 'existing'
          ? { plan_id: selectedPlan.id, client_id: clientId }
          : {
              plan_id: selectedPlan.id,
              client: {
                id: slug(newName),
                name: newName.trim(),
                email: newEmail.trim(),
                billing_email: newBillingEmail.trim() || undefined,
              },
            }
      const cmd = await provision(input)
      setHostCommand(cmd)
      toast.success('Subscription created', `${selectedPlan.package} · ${selectedPlan.name}`)
    } catch (e) {
      toast.error('Provisioning failed', e instanceof Error ? e.message : 'Request failed')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal
      open
      modalHeading={hostCommand ? 'Subscription created — provision on host' : 'New subscription'}
      modalLabel="Provisioning"
      primaryButtonText={hostCommand ? 'Done' : busy ? 'Creating…' : 'Create subscription'}
      primaryButtonDisabled={busy}
      secondaryButtonText={hostCommand ? '' : 'Cancel'}
      onRequestClose={onClose}
      onRequestSubmit={submit}
    >
      {hostCommand ? (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
          <InlineNotification
            kind="success"
            lowContrast
            hideCloseButton
            title="Pending deployment created"
            subtitle="The Commander can't start containers. Run this on the host to provision and activate it:"
            style={{ maxWidth: '100%' }}
          />
          <CodeSnippet type="single" feedback="Copied">
            {hostCommand}
          </CodeSnippet>
          <p className="scns-helper" style={{ fontSize: 13 }}>
            When it finishes, the deployment flips from <strong>Provisioning</strong> to{' '}
            <strong>Operational</strong> and its plan quotas are applied.
          </p>
        </div>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
          {activePlans.length === 0 ? (
            <InlineNotification
              kind="info"
              lowContrast
              hideCloseButton
              title="No active plans"
              subtitle="Create a plan in the Catalog first."
              style={{ maxWidth: '100%' }}
            />
          ) : (
            <Dropdown
              id="prov-plan"
              titleText="Plan"
              label="Select a plan"
              items={activePlans}
              selectedItem={selectedPlan}
              itemToString={(p) => (p ? `${p.package} · ${p.name} — $${p.price_monthly}/mo` : '')}
              onChange={({ selectedItem }) => setSelectedPlan(selectedItem ?? null)}
              invalid={submitted && !!planError}
              invalidText={planError}
            />
          )}

          <RadioButtonGroup
            legendText="Client"
            name="prov-client-mode"
            valueSelected={clientMode}
            onChange={(v) => setClientMode(v as 'existing' | 'new')}
          >
            <RadioButton labelText="Existing client" value="existing" id="prov-existing" disabled={clients.length === 0} />
            <RadioButton labelText="New client" value="new" id="prov-new" />
          </RadioButtonGroup>

          {clientMode === 'existing' ? (
            <Dropdown
              id="prov-client"
              titleText="Select client"
              label="Select a client"
              items={clients}
              selectedItem={clients.find((c) => c.id === clientId) ?? null}
              itemToString={(c) => (c ? `${c.name} (${c.id})` : '')}
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
        </div>
      )}
    </Modal>
  )
}
