import { useEffect, useState } from 'react'
import { Modal, NumberInput, TextArea, TextInput, Toggle } from '@carbon/react'
import { useStore } from '../../store/store'
import { useToast } from '../../providers/ToastProvider'
import type { Plan } from '../../store/types'
import { planDraft, planPayload, type PlanDraft } from '../../lib/planForm'

interface Props {
  open: boolean
  mode: 'create' | 'edit'
  plan?: Plan
  /** The business package this plan belongs to — comes from the route context, not a picker. */
  packageName: string
  onClose: () => void
}

export function PlanFormModal({ open, mode, plan, packageName, onClose }: Props) {
  const addPlan = useStore((s) => s.addPlan)
  const updatePlan = useStore((s) => s.updatePlan)
  const toast = useToast()

  const [draft, setDraft] = useState(planDraft)
  const [submitted, setSubmitted] = useState(false)
  const set = <K extends keyof PlanDraft>(key: K, value: PlanDraft[K]) =>
    setDraft((current) => ({ ...current, [key]: value }))
  const { name, description, price, qReq, qDb, qStorage, qUsers, active } = draft

  useEffect(() => {
    if (open) {
      setDraft(planDraft(plan))
      setSubmitted(false)
    }
  }, [open, plan])

  if (!open) return null

  const nameError = !name.trim() ? 'Required' : ''
  const valid = !nameError

  const submit = () => {
    setSubmitted(true)
    if (!valid) return
    const payload = planPayload(packageName, draft)
    if (mode === 'edit' && plan) {
      updatePlan(plan.id, payload)
      toast.success('Plan updated', `${packageName} · ${name.trim()}`)
    } else {
      addPlan(payload)
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
        <TextInput id="pl-name" labelText="Plan name" placeholder="Pro" value={name} onChange={(e) => set('name', e.target.value)} invalid={submitted && !!nameError} invalidText={nameError} />
        <TextArea id="pl-desc" labelText="Description (optional)" rows={2} value={description} onChange={(e) => set('description', e.target.value)} />
        <TextInput id="pl-price" labelText="Price / month" type="number" value={price} onChange={(e) => set('price', e.target.value)} />

        <div className="scns-helper" style={{ fontSize: 12, textTransform: 'uppercase', letterSpacing: '.04em', marginTop: 8 }}>
          Default quota limits · blank = unlimited
        </div>
        <NumberInput id="pl-q-req" label="Requests / day" hideSteppers value={qReq} onChange={(_e, { value }) => set('qReq', String(value ?? ''))} />
        <NumberInput id="pl-q-db" label="DB size (MB)" hideSteppers value={qDb} onChange={(_e, { value }) => set('qDb', String(value ?? ''))} />
        <NumberInput id="pl-q-storage" label="Storage (MB)" hideSteppers value={qStorage} onChange={(_e, { value }) => set('qStorage', String(value ?? ''))} />
        <NumberInput id="pl-q-users" label="Users total" hideSteppers value={qUsers} onChange={(_e, { value }) => set('qUsers', String(value ?? ''))} />

        <Toggle id="pl-active" labelText="Status" labelA="Inactive" labelB="Active" toggled={active} onToggle={(value) => set('active', value)} />
      </div>
    </Modal>
  )
}
