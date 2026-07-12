import { useEffect, useState } from 'react'
import { FormGroup, NumberInput, Stack, TextArea, TextInput, Toggle } from '@carbon/react'
import { useStore } from '../store/store'
import { useToast } from '../providers/ToastProvider'
import { CreatePanel } from './CreatePanel'
import { planDraft, planPayload, type PlanDraft } from '../lib/planForm'

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

  const [draft, setDraft] = useState(planDraft)
  const [submitted, setSubmitted] = useState(false)
  const set = <K extends keyof PlanDraft>(key: K, value: PlanDraft[K]) =>
    setDraft((current) => ({ ...current, [key]: value }))
  const { name, description, price, qReq, qDb, qStorage, qUsers, active } = draft

  // Stays mounted to slide out, so reset the form once closed (fresh form on reopen).
  useEffect(() => {
    if (open) return
    setDraft(planDraft()); setSubmitted(false)
  }, [open])

  const nameError = !name.trim() ? 'Required' : ''
  const valid = !nameError

  const submit = () => {
    setSubmitted(true)
    if (!valid) return
    addPlan(planPayload(packageName, draft))
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
          onChange={(e) => set('name', e.target.value)}
          invalid={submitted && !!nameError}
          invalidText={nameError}
        />
        <TextArea id="pc-desc" labelText="Description (optional)" rows={2} value={description} onChange={(e) => set('description', e.target.value)} />
        <NumberInput id="pc-price" label="Price / month" hideSteppers min={0} value={price} onChange={(_e, { value }) => set('price', String(value ?? ''))} />

        <FormGroup legendText="Default quota limits · blank = unlimited">
          <Stack gap={5}>
            <NumberInput id="pc-q-req" label="Requests / day" hideSteppers value={qReq} onChange={(_e, { value }) => set('qReq', String(value ?? ''))} />
            <NumberInput id="pc-q-db" label="DB size (MB)" hideSteppers value={qDb} onChange={(_e, { value }) => set('qDb', String(value ?? ''))} />
            <NumberInput id="pc-q-storage" label="Storage (MB)" hideSteppers value={qStorage} onChange={(_e, { value }) => set('qStorage', String(value ?? ''))} />
            <NumberInput id="pc-q-users" label="Users total" hideSteppers value={qUsers} onChange={(_e, { value }) => set('qUsers', String(value ?? ''))} />
          </Stack>
        </FormGroup>

        <Toggle id="pc-active" labelText="Status" labelA="Inactive" labelB="Active" toggled={active} onToggle={(value) => set('active', value)} />
      </Stack>
    </CreatePanel>
  )
}
