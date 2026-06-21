import { useEffect, useMemo, useState } from 'react'
import { Modal, Dropdown, InlineNotification } from '@carbon/react'
import { useStore } from '../../store/store'
import { useToast } from '../../providers/ToastProvider'
import type { Plan } from '../../store/types'

interface Props {
  open: boolean
  /** Business package whose plan is being changed. */
  packageName: string
  /** Plans to offer (package-scoped by the caller); only active, non-current ones are shown. */
  plans: Plan[]
  /** The currently active plan id (excluded from the list). */
  currentPlanId?: number | null
  onClose: () => void
  /** Fired after a successful change. */
  onChanged?: () => void
}

// Cambiar el plan del package: cierra el término vigente y abre uno nuevo (historial). Solo ofrece
// planes activos de este package distintos al vigente; el backend re-valida package/active/distinto.
export function ChangePlanModal({ open, packageName, plans, currentPlanId, onClose, onChanged }: Props) {
  const changePlan = useStore((s) => s.changePlan)
  const toast = useToast()

  const options = useMemo(
    () => plans.filter((p) => p.status === 'active' && p.package === packageName && p.id !== currentPlanId),
    [plans, packageName, currentPlanId],
  )

  const [selected, setSelected] = useState<Plan | null>(null)
  const [submitted, setSubmitted] = useState(false)

  useEffect(() => {
    if (open) {
      setSelected(options[0] ?? null)
      setSubmitted(false)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open])

  if (!open) return null

  const submit = () => {
    setSubmitted(true)
    if (!selected) return
    changePlan(packageName, selected.id)
    toast.success('Plan changed', `${packageName} → ${selected.name}`)
    onChanged?.()
    onClose()
  }

  return (
    <Modal
      open
      modalHeading="Change plan"
      modalLabel={packageName}
      primaryButtonText="Change plan"
      primaryButtonDisabled={options.length === 0}
      secondaryButtonText="Cancel"
      onRequestClose={onClose}
      onRequestSubmit={submit}
    >
      <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
        {options.length === 0 ? (
          <InlineNotification
            kind="info"
            lowContrast
            hideCloseButton
            title="No other active plans"
            subtitle="Create another active plan for this package (Plans tab) to switch to it."
            style={{ maxWidth: '100%' }}
          />
        ) : (
          <>
            <p style={{ fontSize: 13, color: 'var(--cds-text-secondary)' }}>
              Closes the current subscription term and opens a new one on the chosen plan. The new term
              starts as <strong>pending</strong>; past terms are kept as billing history.
            </p>
            <Dropdown
              id="changeplan-plan"
              titleText="New plan"
              label="Select a plan"
              items={options}
              selectedItem={selected}
              itemToString={(p) => (p ? `${p.name} — $${p.price_monthly}/mo` : '')}
              onChange={({ selectedItem }) => setSelected(selectedItem ?? null)}
              invalid={submitted && !selected}
              invalidText="Select a plan"
            />
          </>
        )}
      </div>
    </Modal>
  )
}
