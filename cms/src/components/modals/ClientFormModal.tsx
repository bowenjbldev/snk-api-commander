import { useEffect, useState } from 'react'
import { Modal, TextInput, TextArea, Toggle } from '@carbon/react'
import { useStore } from '../../store/store'
import { useToast } from '../../providers/ToastProvider'
import type { Client } from '../../store/types'

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
  const [submitted, setSubmitted] = useState(false)

  useEffect(() => {
    if (open) {
      setName(client?.name ?? '')
      setCompany(client?.company ?? '')
      setEmail(client?.email ?? '')
      setPhone(client?.phone ?? '')
      setNotes(client?.notes ?? '')
      setActive(client ? client.status === 'active' : true)
      setSubmitted(false)
    }
  }, [open, client])

  if (!open) return null

  const nameError = !name.trim() ? 'Required' : ''
  const emailError = !email.trim() ? 'Required' : !EMAIL_RE.test(email) ? 'Enter a valid email' : ''
  const valid = !nameError && !emailError

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
      </div>
    </Modal>
  )
}
