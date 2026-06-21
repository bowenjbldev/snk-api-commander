import { useState } from 'react'
import { Select, SelectItem, TextInput, TextArea, Toggle } from '@carbon/react'
import { useStore } from '../store/store'
import { useToast } from '../providers/ToastProvider'
import { CreatePanel } from './CreatePanel'
import type { PaymentTerms } from '../store/types'

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

// Create-client side panel (replaces the old ClientFormModal create flow). Single-column form in a
// right-side Drawer. Same field set + validation as before; submits via addClient.
export function ClientCreatePanel({ open, onClose }: { open: boolean; onClose: () => void }) {
  const addClient = useStore((s) => s.addClient)
  const toast = useToast()

  const [name, setName] = useState('')
  const [company, setCompany] = useState('')
  const [email, setEmail] = useState('')
  const [phone, setPhone] = useState('')
  const [notes, setNotes] = useState('')
  const [active, setActive] = useState(true)
  const [billingEmail, setBillingEmail] = useState('')
  const [taxId, setTaxId] = useState('')
  const [address1, setAddress1] = useState('')
  const [address2, setAddress2] = useState('')
  const [city, setCity] = useState('')
  const [postal, setPostal] = useState('')
  const [country, setCountry] = useState('')
  const [terms, setTerms] = useState<PaymentTerms | ''>('')
  const [submitted, setSubmitted] = useState(false)

  const nameError = !name.trim() ? 'Required' : ''
  const emailError = !email.trim() ? 'Required' : !EMAIL_RE.test(email) ? 'Enter a valid email' : ''
  const billingEmailError = billingEmail.trim() && !EMAIL_RE.test(billingEmail) ? 'Enter a valid email' : ''
  const countryError = country.trim() && country.trim().length !== 2 ? 'Use a 2-letter code' : ''
  const valid = !nameError && !emailError && !billingEmailError && !countryError

  const submit = () => {
    setSubmitted(true)
    if (!valid) return
    addClient({
      name: name.trim(),
      company: company.trim(),
      email: email.trim(),
      phone: phone.trim() || undefined,
      notes: notes.trim() || undefined,
      status: active ? 'active' : 'inactive',
      billing_email: billingEmail.trim() || null,
      tax_id: taxId.trim() || null,
      address_line1: address1.trim() || null,
      address_line2: address2.trim() || null,
      city: city.trim() || null,
      postal_code: postal.trim() || null,
      country: country.trim().toUpperCase() || null,
      payment_terms: (terms || null) as PaymentTerms | null,
    })
    toast.success('Client created', name.trim())
    onClose()
  }

  return (
    <CreatePanel open={open} title="Create client" submitLabel="Create client" onSubmit={submit} onClose={onClose}>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
        <TextInput id="cc-name" labelText="Name" value={name} onChange={(e) => setName(e.target.value)} invalid={submitted && !!nameError} invalidText={nameError} />
        <TextInput id="cc-company" labelText="Company" value={company} onChange={(e) => setCompany(e.target.value)} />
        <TextInput id="cc-email" labelText="Email" value={email} onChange={(e) => setEmail(e.target.value)} invalid={submitted && !!emailError} invalidText={emailError} />
        <TextInput id="cc-phone" labelText="Phone (optional)" value={phone} onChange={(e) => setPhone(e.target.value)} />
        <TextArea id="cc-notes" labelText="Notes (optional)" rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} />
        <Toggle id="cc-active" labelText="Status" labelA="Inactive" labelB="Active" toggled={active} onToggle={setActive} />

        <p className="scns-section-label" style={{ marginTop: 8 }}>Billing</p>
        <TextInput id="cc-billing-email" labelText="Billing email (optional)" value={billingEmail} onChange={(e) => setBillingEmail(e.target.value)} invalid={submitted && !!billingEmailError} invalidText={billingEmailError} />
        <TextInput id="cc-tax" labelText="Tax / VAT ID (optional)" value={taxId} onChange={(e) => setTaxId(e.target.value)} />
        <TextInput id="cc-addr1" labelText="Address line 1 (optional)" value={address1} onChange={(e) => setAddress1(e.target.value)} />
        <TextInput id="cc-addr2" labelText="Address line 2 (optional)" value={address2} onChange={(e) => setAddress2(e.target.value)} />
        <TextInput id="cc-city" labelText="City (optional)" value={city} onChange={(e) => setCity(e.target.value)} />
        <TextInput id="cc-postal" labelText="Postal code (optional)" value={postal} onChange={(e) => setPostal(e.target.value)} />
        <TextInput id="cc-country" labelText="Country (ISO-2)" value={country} onChange={(e) => setCountry(e.target.value)} invalid={submitted && !!countryError} invalidText={countryError} />
        <Select id="cc-terms" labelText="Payment terms (optional)" value={terms} onChange={(e) => setTerms(e.target.value as PaymentTerms | '')}>
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
