import { useEffect, useState } from 'react'
import { FormGroup, NumberInput, Stack, TextArea, TextInput, Toggle } from '@carbon/react'
import { useStore } from '../store/store'
import { useToast } from '../providers/ToastProvider'
import { CreatePanel } from './CreatePanel'

function slug(s: string): string {
  return s.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '')
}

// '' (blank) → unlimited (null); a number → that limit.
function numOrNull(v: string): number | null {
  const t = v.trim()
  if (t === '') return null
  const n = Number(t)
  return Number.isFinite(n) && n >= 0 ? Math.round(n) : null
}

// Create-plan flow in the canonical Carbon Side panel (CreateSidePanel via CreatePanel): a
// single-column form that slides in from the right. Same field set + validation as the old modal.
// Editing still uses PlanFormModal.
export function PlanCreatePanel({
  open,
  packageName,
  onClose,
}: {
  open: boolean
  packageName: string
  onClose: () => void
}) {
  const addPlan = useStore((s) => s.addPlan)
  const toast = useToast()

  const [name, setName] = useState('')
  const [description, setDescription] = useState('')
  const [price, setPrice] = useState('0')
  const [qReq, setQReq] = useState('')
  const [qDb, setQDb] = useState('')
  const [qStorage, setQStorage] = useState('')
  const [qUsers, setQUsers] = useState('')
  const [active, setActive] = useState(true)
  const [submitted, setSubmitted] = useState(false)

  // Stays mounted to slide out, so reset the form once closed (fresh form on reopen).
  useEffect(() => {
    if (open) return
    setName(''); setDescription(''); setPrice('0'); setQReq(''); setQDb('')
    setQStorage(''); setQUsers(''); setActive(true); setSubmitted(false)
  }, [open])

  const nameError = !name.trim() ? 'Required' : ''
  const valid = !nameError

  const submit = () => {
    setSubmitted(true)
    if (!valid) return
    addPlan({
      slug: `${slug(packageName)}-${slug(name)}`,
      package: packageName,
      name: name.trim(),
      description: description.trim() || null,
      price_monthly: Number(price) || 0,
      status: active ? 'active' : 'inactive',
      q_requests_day: numOrNull(qReq),
      q_disk_db_mb: numOrNull(qDb),
      q_disk_storage_mb: numOrNull(qStorage),
      q_users_total: numOrNull(qUsers),
    })
    toast.success('Plan created', `${packageName} · ${name.trim()}`)
    onClose()
  }

  return (
    <CreatePanel open={open} title="New plan" subtitle={`Pricing & quota defaults for ${packageName}`} formTitle="Plan details" submitLabel="Create plan" onSubmit={submit} onClose={onClose}>
      <Stack gap={5}>
        <TextInput
          id="pc-package"
          labelText="Package"
          helperText="The business package this plan provisions. Fixed by context."
          value={packageName}
          readOnly
          disabled
        />
        <TextInput
          id="pc-name"
          labelText="Plan name"
          placeholder="Pro"
          value={name}
          onChange={(e) => setName(e.target.value)}
          invalid={submitted && !!nameError}
          invalidText={nameError}
        />
        <TextArea id="pc-desc" labelText="Description (optional)" rows={2} value={description} onChange={(e) => setDescription(e.target.value)} />
        <NumberInput id="pc-price" label="Price / month" hideSteppers min={0} value={price} onChange={(_e, { value }) => setPrice(String(value ?? ''))} />

        <FormGroup legendText="Default quota limits · blank = unlimited">
          <Stack gap={5}>
            <NumberInput id="pc-q-req" label="Requests / day" hideSteppers value={qReq} onChange={(_e, { value }) => setQReq(String(value ?? ''))} />
            <NumberInput id="pc-q-db" label="DB size (MB)" hideSteppers value={qDb} onChange={(_e, { value }) => setQDb(String(value ?? ''))} />
            <NumberInput id="pc-q-storage" label="Storage (MB)" hideSteppers value={qStorage} onChange={(_e, { value }) => setQStorage(String(value ?? ''))} />
            <NumberInput id="pc-q-users" label="Users total" hideSteppers value={qUsers} onChange={(_e, { value }) => setQUsers(String(value ?? ''))} />
          </Stack>
        </FormGroup>

        <Toggle id="pc-active" labelText="Status" labelA="Inactive" labelB="Active" toggled={active} onToggle={setActive} />
      </Stack>
    </CreatePanel>
  )
}
