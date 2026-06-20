import { useEffect, useState } from 'react'
import { Modal, NumberInput, TextArea, TextInput, Toggle } from '@carbon/react'
import { useStore } from '../../store/store'
import { useToast } from '../../providers/ToastProvider'
import type { Plan } from '../../store/types'

interface Props {
  open: boolean
  mode: 'create' | 'edit'
  plan?: Plan
  /** The business package this plan belongs to — comes from the route context, not a picker. */
  packageName: string
  onClose: () => void
}

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

export function PlanFormModal({ open, mode, plan, packageName, onClose }: Props) {
  const addPlan = useStore((s) => s.addPlan)
  const updatePlan = useStore((s) => s.updatePlan)
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

  useEffect(() => {
    if (open) {
      setName(plan?.name ?? '')
      setDescription(plan?.description ?? '')
      setPrice(String(plan?.price_monthly ?? 0))
      setQReq(plan?.q_requests_day != null ? String(plan.q_requests_day) : '')
      setQDb(plan?.q_disk_db_mb != null ? String(plan.q_disk_db_mb) : '')
      setQStorage(plan?.q_disk_storage_mb != null ? String(plan.q_disk_storage_mb) : '')
      setQUsers(plan?.q_users_total != null ? String(plan.q_users_total) : '')
      setActive(plan ? plan.status === 'active' : true)
      setSubmitted(false)
    }
  }, [open, plan])

  if (!open) return null

  const nameError = !name.trim() ? 'Required' : ''
  const valid = !nameError

  const submit = () => {
    setSubmitted(true)
    if (!valid) return
    const quotas = {
      q_requests_day: numOrNull(qReq),
      q_disk_db_mb: numOrNull(qDb),
      q_disk_storage_mb: numOrNull(qStorage),
      q_users_total: numOrNull(qUsers),
    }
    if (mode === 'edit' && plan) {
      updatePlan(plan.id, {
        package: packageName,
        name: name.trim(),
        description: description.trim() || null,
        price_monthly: Number(price) || 0,
        status: active ? 'active' : 'inactive',
        ...quotas,
      })
      toast.success('Plan updated', `${packageName} · ${name.trim()}`)
    } else {
      addPlan({
        slug: `${slug(packageName)}-${slug(name)}`,
        package: packageName,
        name: name.trim(),
        description: description.trim() || null,
        price_monthly: Number(price) || 0,
        status: active ? 'active' : 'inactive',
        ...quotas,
      })
      toast.success('Plan created', `${packageName} · ${name.trim()}`)
    }
    onClose()
  }

  return (
    <Modal
      open
      modalHeading={mode === 'edit' ? 'Edit plan' : 'Create plan'}
      modalLabel="Catalog"
      primaryButtonText={mode === 'edit' ? 'Save changes' : 'Create plan'}
      secondaryButtonText="Cancel"
      onRequestClose={onClose}
      onRequestSubmit={submit}
    >
      <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
        <TextInput
          id="pl-package"
          labelText="Package"
          helperText="The business package this plan provisions. Fixed by context."
          value={packageName}
          readOnly
          disabled
        />
        <TextInput id="pl-name" labelText="Plan name" placeholder="Pro" value={name} onChange={(e) => setName(e.target.value)} invalid={submitted && !!nameError} invalidText={nameError} />
        <TextArea id="pl-desc" labelText="Description (optional)" rows={2} value={description} onChange={(e) => setDescription(e.target.value)} />
        <TextInput id="pl-price" labelText="Price / month" type="number" value={price} onChange={(e) => setPrice(e.target.value)} />

        <div className="scns-helper" style={{ fontSize: 12, textTransform: 'uppercase', letterSpacing: '.04em', marginTop: 8 }}>
          Default quota limits · blank = unlimited
        </div>
        <NumberInput id="pl-q-req" label="Requests / day" hideSteppers value={qReq} onChange={(_e, { value }) => setQReq(String(value ?? ''))} />
        <NumberInput id="pl-q-db" label="DB size (MB)" hideSteppers value={qDb} onChange={(_e, { value }) => setQDb(String(value ?? ''))} />
        <NumberInput id="pl-q-storage" label="Storage (MB)" hideSteppers value={qStorage} onChange={(_e, { value }) => setQStorage(String(value ?? ''))} />
        <NumberInput id="pl-q-users" label="Users total" hideSteppers value={qUsers} onChange={(_e, { value }) => setQUsers(String(value ?? ''))} />

        <Toggle id="pl-active" labelText="Status" labelA="Inactive" labelB="Active" toggled={active} onToggle={setActive} />
      </div>
    </Modal>
  )
}
