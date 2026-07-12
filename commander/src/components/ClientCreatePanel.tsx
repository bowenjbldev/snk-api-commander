import { useEffect, useState } from 'react'
import { Select, SelectItem, TextInput, TextArea, Toggle } from '@carbon/react'
import { useStore } from '../store/store'
import { useToast } from '../providers/ToastProvider'
import { CreatePanel } from './CreatePanel'
import { clientDraft, clientErrors, clientPayload, type ClientDraft } from '../lib/clientForm'

// Create-client flow in the canonical Carbon Side panel (CreateSidePanel via CreatePanel): a
// single-column form that slides in from the right while the client list stays visible behind it.
// Same field set + validation as before; submits via addClient.
export function ClientCreatePanel({ open, onClose }: { open: boolean; onClose: () => void }) {
  const addClient = useStore((s) => s.addClient)
  const toast = useToast()

  const [draft, setDraft] = useState(clientDraft)
  const [submitted, setSubmitted] = useState(false)
  const set = <K extends keyof ClientDraft>(key: K, value: ClientDraft[K]) =>
    setDraft((current) => ({ ...current, [key]: value }))
  const { name, company, email, phone, notes, active, billingEmail, taxId, address1, address2, city, postal, country, terms } = draft

  // The panel stays mounted (so it can slide out), so reset the form once it has closed — a fresh
  // form each time it reopens, matching the old remount-on-open behaviour.
  useEffect(() => {
    if (open) return
    setDraft(clientDraft()); setSubmitted(false)
  }, [open])

  const { name: nameError, email: emailError, billingEmail: billingEmailError, country: countryError } = clientErrors(draft)
  const valid = !nameError && !emailError && !billingEmailError && !countryError

  const submit = () => {
    setSubmitted(true)
    if (!valid) return
    addClient(clientPayload(draft))
    toast.success('Client created', name.trim())
    onClose()
  }

  return (
    <CreatePanel open={open} title="Create client" subtitle="A customer account on the platform" formTitle="Client details" submitLabel="Create client" onSubmit={submit} onClose={onClose}>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
        <TextInput id="cc-name" labelText="Name" value={name} onChange={(e) => set('name', e.target.value)} invalid={submitted && !!nameError} invalidText={nameError} />
        <TextInput id="cc-company" labelText="Company" value={company} onChange={(e) => set('company', e.target.value)} />
        <TextInput id="cc-email" labelText="Email" value={email} onChange={(e) => set('email', e.target.value)} invalid={submitted && !!emailError} invalidText={emailError} />
        <TextInput id="cc-phone" labelText="Phone (optional)" value={phone} onChange={(e) => set('phone', e.target.value)} />
        <TextArea id="cc-notes" labelText="Notes (optional)" rows={2} value={notes} onChange={(e) => set('notes', e.target.value)} />
        <Toggle id="cc-active" labelText="Status" labelA="Inactive" labelB="Active" toggled={active} onToggle={(value) => set('active', value)} />

        <p className="scns-section-label" style={{ marginTop: 8 }}>Billing</p>
        <TextInput id="cc-billing-email" labelText="Billing email (optional)" value={billingEmail} onChange={(e) => set('billingEmail', e.target.value)} invalid={submitted && !!billingEmailError} invalidText={billingEmailError} />
        <TextInput id="cc-tax" labelText="Tax / VAT ID (optional)" value={taxId} onChange={(e) => set('taxId', e.target.value)} />
        <TextInput id="cc-addr1" labelText="Address line 1 (optional)" value={address1} onChange={(e) => set('address1', e.target.value)} />
        <TextInput id="cc-addr2" labelText="Address line 2 (optional)" value={address2} onChange={(e) => set('address2', e.target.value)} />
        <TextInput id="cc-city" labelText="City (optional)" value={city} onChange={(e) => set('city', e.target.value)} />
        <TextInput id="cc-postal" labelText="Postal code (optional)" value={postal} onChange={(e) => set('postal', e.target.value)} />
        <TextInput id="cc-country" labelText="Country (ISO-2)" value={country} onChange={(e) => set('country', e.target.value)} invalid={submitted && !!countryError} invalidText={countryError} />
        <Select id="cc-terms" labelText="Payment terms (optional)" value={terms} onChange={(e) => set('terms', e.target.value as ClientDraft['terms'])}>
          <SelectItem value="" text="—" />
          <SelectItem value="prepaid" text="Prepaid" />
          <SelectItem value="net_15" text="Net 15" />
          <SelectItem value="net_30" text="Net 30" />
          <SelectItem value="net_60" text="Net 60" />
        </Select>
      </div>
    </CreatePanel>
  )
}
