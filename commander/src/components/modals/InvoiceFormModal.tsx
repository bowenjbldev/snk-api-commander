import { useEffect, useMemo, useState } from 'react'
import { Modal, Dropdown, NumberInput, DatePicker, DatePickerInput } from '@carbon/react'
import { useStore } from '../../store/store'
import type { Invoice } from '../../store/types'

type CreateInput = { client_id: number; deployment_id: number; amount: number; issued_at: string; due_at: string }
type EditPatch = { amount: number; issued_at: string; due_at: string }

interface Props {
  open: boolean
  mode: 'create' | 'edit'
  invoice?: Invoice
  /** create → full input; edit → amount/issued/due patch. */
  onSubmit: (value: CreateInput | EditPatch) => void
  onClose: () => void
}

// A picked Date → midnight-UTC ISO, so issued/due survive the round-trip without TZ drift.
function toIso(d: Date | undefined): string {
  if (!d) return ''
  return new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate())).toISOString()
}

export function InvoiceFormModal({ open, mode, invoice, onSubmit, onClose }: Props) {
  const clients = useStore((s) => s.clients)
  const deployments = useStore((s) => s.deployments)

  const [clientId, setClientId] = useState<number | ''>('')
  const [deploymentId, setDeploymentId] = useState<number | ''>('')
  const [amount, setAmount] = useState('0')
  const [issuedAt, setIssuedAt] = useState<Date | undefined>(undefined)
  const [dueAt, setDueAt] = useState<Date | undefined>(undefined)
  const [submitted, setSubmitted] = useState(false)

  useEffect(() => {
    if (open) {
      setClientId(invoice?.client_id ?? '')
      setDeploymentId(invoice?.deployment_id ?? '')
      setAmount(String(invoice?.amount ?? 0))
      setIssuedAt(invoice ? new Date(invoice.issued_at) : undefined)
      setDueAt(invoice ? new Date(invoice.due_at) : undefined)
      setSubmitted(false)
    }
  }, [open, invoice])

  // Deployments scoped to the selected client; in edit the pair is fixed.
  const clientDeployments = useMemo(
    () => deployments.filter((d) => d.client_id === clientId),
    [deployments, clientId],
  )

  if (!open) return null

  const selectedClient = clients.find((c) => c.id === clientId) ?? null
  const selectedDeployment = clientDeployments.find((d) => d.id === deploymentId) ?? null
  const amountNum = Number(amount)

  const clientError = !clientId ? 'Required' : ''
  const deploymentError = !deploymentId ? 'Required' : ''
  const amountError = !Number.isFinite(amountNum) || amountNum < 0 ? 'Enter an amount ≥ 0' : ''
  const issuedError = !issuedAt ? 'Required' : ''
  const dueError = !dueAt ? 'Required' : ''
  const valid = !clientError && !deploymentError && !amountError && !issuedError && !dueError

  const submit = () => {
    setSubmitted(true)
    if (!valid) return
    if (mode === 'edit') {
      onSubmit({ amount: amountNum, issued_at: toIso(issuedAt), due_at: toIso(dueAt) })
    } else {
      onSubmit({
        client_id: Number(clientId),
        deployment_id: Number(deploymentId),
        amount: amountNum,
        issued_at: toIso(issuedAt),
        due_at: toIso(dueAt),
      })
    }
    onClose()
  }

  return (
    <Modal
      open
      modalHeading={mode === 'edit' ? 'Edit invoice' : 'Create invoice'}
      modalLabel={mode === 'edit' ? invoice?.id : 'Invoices'}
      primaryButtonText={mode === 'edit' ? 'Save changes' : 'Create invoice'}
      secondaryButtonText="Cancel"
      primaryButtonDisabled={submitted && !valid}
      onRequestClose={onClose}
      onRequestSubmit={submit}
    >
      <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
        <Dropdown
          id="inv-client"
          titleText="Client"
          label="Select a client"
          disabled={mode === 'edit'}
          items={clients}
          itemToString={(c) => c?.name ?? ''}
          selectedItem={selectedClient}
          invalid={submitted && !!clientError}
          invalidText={clientError}
          onChange={({ selectedItem }) => {
            setClientId(selectedItem?.id ?? '')
            setDeploymentId('') // reset: deployments are scoped to the client
          }}
        />
        <Dropdown
          id="inv-deployment"
          titleText="Deployment"
          label={clientId ? 'Select a deployment' : 'Select a client first'}
          disabled={mode === 'edit' || !clientId}
          items={clientDeployments}
          itemToString={(d) => d?.name ?? ''}
          selectedItem={selectedDeployment}
          invalid={submitted && !!deploymentError}
          invalidText={deploymentError}
          onChange={({ selectedItem }) => setDeploymentId(selectedItem?.id ?? '')}
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
    </Modal>
  )
}
