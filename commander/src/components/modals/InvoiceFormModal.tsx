import { useEffect, useMemo, useState } from 'react'
import { Modal, Dropdown, NumberInput, DatePicker, DatePickerInput } from '@carbon/react'
import { useStore, useProvisionedPackages } from '../../store/store'
import { CreatePanel } from '../CreatePanel'
import type { Invoice } from '../../store/types'

type CreateInput = { client_id: number; package_id: number; amount: number; issued_at: string; due_at: string }
type EditPatch = { amount: number; issued_at: string; due_at: string }

interface Props {
  open: boolean
  /** Render in the slide-in side panel instead of a modal (used from the client page). */
  asPanel?: boolean
  mode: 'create' | 'edit'
  invoice?: Invoice
  /** Pre-select + lock the client (e.g. "New invoice for this client" from the client page). */
  clientId?: number
  /** create → full input; edit → amount/issued/due patch. */
  onSubmit: (value: CreateInput | EditPatch) => void
  onClose: () => void
}

// A picked Date → midnight-UTC ISO, so issued/due survive the round-trip without TZ drift.
function toIso(d: Date | undefined): string {
  if (!d) return ''
  return new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate())).toISOString()
}

export function InvoiceFormModal({ open, asPanel, mode, invoice, clientId: lockClientId, onSubmit, onClose }: Props) {
  const clients = useStore((s) => s.clients)
  const packages = useProvisionedPackages()

  const [clientId, setClientId] = useState<number | ''>('')
  const [packageId, setPackageId] = useState<number | ''>('')
  const [amount, setAmount] = useState('0')
  const [issuedAt, setIssuedAt] = useState<Date | undefined>(undefined)
  const [dueAt, setDueAt] = useState<Date | undefined>(undefined)
  const [submitted, setSubmitted] = useState(false)

  useEffect(() => {
    if (open) {
      setClientId(invoice?.client_id ?? lockClientId ?? '')
      setPackageId(invoice?.package_id ?? '')
      setAmount(String(invoice?.amount ?? 0))
      setIssuedAt(invoice ? new Date(invoice.issued_at) : undefined)
      setDueAt(invoice ? new Date(invoice.due_at) : undefined)
      setSubmitted(false)
    }
  }, [open, invoice, lockClientId])

  // Packages scoped to the selected client; in edit the pair is fixed.
  const clientPackages = useMemo(
    () => packages.filter((p) => p.client_id === clientId),
    [packages, clientId],
  )

  // In panel mode stay mounted so the side panel can slide in/out; the modal only renders when open.
  if (!open && !asPanel) return null

  const selectedClient = clients.find((c) => c.id === clientId) ?? null
  const selectedPackage = clientPackages.find((p) => p.id === packageId) ?? null
  const amountNum = Number(amount)

  const clientError = !clientId ? 'Required' : ''
  const packageError = !packageId ? 'Required' : ''
  const amountError = !Number.isFinite(amountNum) || amountNum < 0 ? 'Enter an amount ≥ 0' : ''
  const issuedError = !issuedAt ? 'Required' : ''
  const dueError = !dueAt ? 'Required' : ''
  const valid = !clientError && !packageError && !amountError && !issuedError && !dueError

  const submit = () => {
    setSubmitted(true)
    if (!valid) return
    if (mode === 'edit') {
      onSubmit({ amount: amountNum, issued_at: toIso(issuedAt), due_at: toIso(dueAt) })
    } else {
      onSubmit({
        client_id: Number(clientId),
        package_id: Number(packageId),
        amount: amountNum,
        issued_at: toIso(issuedAt),
        due_at: toIso(dueAt),
      })
    }
    onClose()
  }

  const body = (
      <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
        <Dropdown
          id="inv-client"
          titleText="Client"
          label="Select a client"
          disabled={mode === 'edit' || lockClientId != null}
          items={clients}
          itemToString={(c) => c?.name ?? ''}
          selectedItem={selectedClient}
          invalid={submitted && !!clientError}
          invalidText={clientError}
          onChange={({ selectedItem }) => {
            setClientId(selectedItem?.id ?? '')
            setPackageId('') // reset: packages are scoped to the client
          }}
        />
        <Dropdown
          id="inv-package"
          titleText="Package"
          label={clientId ? 'Select a package' : 'Select a client first'}
          disabled={mode === 'edit' || !clientId}
          items={clientPackages}
          itemToString={(p) => p?.name ?? ''}
          selectedItem={selectedPackage}
          invalid={submitted && !!packageError}
          invalidText={packageError}
          onChange={({ selectedItem }) => setPackageId(selectedItem?.id ?? '')}
        />
        <NumberInput
          id="inv-amount"
          label="Amount"
          min={0}
          hideSteppers
          value={amount}
          invalid={submitted && !!amountError}
          invalidText={amountError}
          onChange={(_e, { value }) => setAmount(String(value ?? ''))}
        />
        <DatePicker
          datePickerType="single"
          dateFormat="Y-m-d"
          value={issuedAt}
          onChange={(dates) => setIssuedAt(dates[0])}
        >
          <DatePickerInput
            id="inv-issued"
            labelText="Issued at"
            placeholder="yyyy-mm-dd"
            invalid={submitted && !!issuedError}
            invalidText={issuedError}
          />
        </DatePicker>
        <DatePicker
          datePickerType="single"
          dateFormat="Y-m-d"
          value={dueAt}
          onChange={(dates) => setDueAt(dates[0])}
        >
          <DatePickerInput
            id="inv-due"
            labelText="Due at"
            placeholder="yyyy-mm-dd"
            invalid={submitted && !!dueError}
            invalidText={dueError}
          />
        </DatePicker>
      </div>
  )

  if (asPanel) {
    return (
      <CreatePanel
        open={open}
        title={mode === 'edit' ? 'Edit invoice' : 'Create invoice'}
        subtitle={mode === 'edit' ? invoice?.number : 'A billing record for this client'}
        formTitle="Invoice details"
        submitLabel={mode === 'edit' ? 'Save changes' : 'Create invoice'}
        submitDisabled={submitted && !valid}
        onSubmit={submit}
        onClose={onClose}
      >
        {body}
      </CreatePanel>
    )
  }

  return (
    <Modal
      open
      modalHeading={mode === 'edit' ? 'Edit invoice' : 'Create invoice'}
      modalLabel={mode === 'edit' ? invoice?.number : 'Invoices'}
      primaryButtonText={mode === 'edit' ? 'Save changes' : 'Create invoice'}
      secondaryButtonText="Cancel"
      primaryButtonDisabled={submitted && !valid}
      onRequestClose={onClose}
      onRequestSubmit={submit}
    >
      {body}
    </Modal>
  )
}
