import { useEffect, useState } from 'react'
import { Modal, Select, SelectItem, TextInput, TextArea, Toggle } from '@carbon/react'
import { useStore } from '../../store/store'
import { useToast } from '../../providers/ToastProvider'
import type { Client, PaymentTerms } from '../../store/types'

interface Props {
  open: boolean
  mode: 'create' | 'edit'
  client?: Client
  onClose: () => void
}

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

export function ClientFormModal({ open, mode, client, onClose }: Props) {
  const addClient = useStore((s) => s.addClient)
  const updateClient = useStore((s) => s.updateClient)
  const toast = useToast()

  const [name, setName] = useState('')
  const [company, setCompany] = useState('')
  const [email, setEmail] = useState('')
  const [phone, setPhone] = useState('')
  const [notes, setNotes] = useState('')
  const [active, setActive] = useState(true)
  // Billing
  const [billingEmail, setBillingEmail] = useState('')
  const [taxId, setTaxId] = useState('')
  const [address1, setAddress1] = useState('')
  const [address2, setAddress2] = useState('')
  const [city, setCity] = useState('')
  const [postal, setPostal] = useState('')
  const [country, setCountry] = useState('')
  const [terms, setTerms] = useState<PaymentTerms | ''>('')
  const [submitted, setSubmitted] = useState(false)

  useEffect(() => {
    if (open) {
      setName(client?.name ?? '')
      setCompany(client?.company ?? '')
      setEmail(client?.email ?? '')
      setPhone(client?.phone ?? '')
      setNotes(client?.notes ?? '')
      setActive(client ? client.status === 'active' : true)
      setBillingEmail(client?.billing_email ?? '')
      setTaxId(client?.tax_id ?? '')
      setAddress1(client?.address_line1 ?? '')
      setAddress2(client?.address_line2 ?? '')
      setCity(client?.city ?? '')
      setPostal(client?.postal_code ?? '')
      setCountry(client?.country ?? '')
      setTerms(client?.payment_terms ?? '')
      setSubmitted(false)
    }
  }, [open, client])

  if (!open) return null

  const nameError = !name.trim() ? 'Required' : ''
  const emailError = !email.trim() ? 'Required' : !EMAIL_RE.test(email) ? 'Enter a valid email' : ''
  const billingEmailError = billingEmail.trim() && !EMAIL_RE.test(billingEmail) ? 'Enter a valid email' : ''
  const countryError = country.trim() && country.trim().length !== 2 ? 'Use a 2-letter code' : ''
  const valid = !nameError && !emailError && !billingEmailError && !countryError

  const submit = () => {
    setSubmitted(true)
    if (!valid) return
    const payload = {
      name: name.trim(),
      company: company.trim(),
      email: email.trim(),
      phone: phone.trim() || undefined,
      notes: notes.trim() || undefined,
      status: active ? ('active' as const) : ('inactive' as const),
      billing_email: billingEmail.trim() || null,
      tax_id: taxId.trim() || null,
      address_line1: address1.trim() || null,
      address_line2: address2.trim() || null,
      city: city.trim() || null,
      postal_code: postal.trim() || null,
      country: country.trim().toUpperCase() || null,
      payment_terms: (terms || null) as PaymentTerms | null,
    }
    if (mode === 'edit' && client) {
      updateClient(client.id, payload)
      toast.success('Client updated', payload.name)
    } else {
      addClient(payload)
      toast.success('Client created', payload.name)
    }
    onClose()
  }

  return (
    <Modal
      open
      modalHeading={mode === 'edit' ? 'Edit client' : 'Create client'}
      modalLabel="Clients"
      primaryButtonText={mode === 'edit' ? 'Save changes' : 'Create client'}
      secondaryButtonText="Cancel"
      onRequestClose={onClose}
      onRequestSubmit={submit}
    >
      <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
        <TextInput id="cl-name" labelText="Name" value={name} onChange={(e) => setName(e.target.value)} invalid={submitted && !!nameError} invalidText={nameError} />
        <TextInput id="cl-company" labelText="Company" value={company} onChange={(e) => setCompany(e.target.value)} />
        <TextInput id="cl-email" labelText="Email" value={email} onChange={(e) => setEmail(e.target.value)} invalid={submitted && !!emailError} invalidText={emailError} />
        <TextInput id="cl-phone" labelText="Phone (optional)" value={phone} onChange={(e) => setPhone(e.target.value)} />
        <TextArea id="cl-notes" labelText="Notes (optional)" rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} />
        <Toggle id="cl-active" labelText="Status" labelA="Inactive" labelB="Active" toggled={active} onToggle={setActive} />

        <div className="scns-helper" style={{ fontSize: 12, textTransform: 'uppercase', letterSpacing: '.04em', marginTop: 8 }}>
          Billing
        </div>
        <TextInput id="cl-billing-email" labelText="Billing email (optional)" value={billingEmail} onChange={(e) => setBillingEmail(e.target.value)} invalid={submitted && !!billingEmailError} invalidText={billingEmailError} />
        <TextInput id="cl-tax" labelText="Tax / VAT ID (optional)" value={taxId} onChange={(e) => setTaxId(e.target.value)} />
        <TextInput id="cl-addr1" labelText="Address line 1 (optional)" value={address1} onChange={(e) => setAddress1(e.target.value)} />
        <TextInput id="cl-addr2" labelText="Address line 2 (optional)" value={address2} onChange={(e) => setAddress2(e.target.value)} />
        <div style={{ display: 'flex', gap: 12 }}>
          <TextInput id="cl-city" labelText="City (optional)" value={city} onChange={(e) => setCity(e.target.value)} />
          <TextInput id="cl-postal" labelText="Postal code (optional)" value={postal} onChange={(e) => setPostal(e.target.value)} />
          <TextInput id="cl-country" labelText="Country (ISO-2)" value={country} onChange={(e) => setCountry(e.target.value)} invalid={submitted && !!countryError} invalidText={countryError} />
        </div>
        <Select id="cl-terms" labelText="Payment terms (optional)" value={terms} onChange={(e) => setTerms(e.target.value as PaymentTerms | '')}>
          <SelectItem value="" text="—" />
          <SelectItem value="prepaid" text="Prepaid" />
          <SelectItem value="net_15" text="Net 15" />
          <SelectItem value="net_30" text="Net 30" />
          <SelectItem value="net_60" text="Net 60" />
        </Select>
      </div>
    </Modal>
  )
}
