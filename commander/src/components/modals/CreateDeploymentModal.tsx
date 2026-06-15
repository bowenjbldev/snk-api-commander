import { useEffect, useState } from 'react'
import { Modal, TextInput, Dropdown } from '@carbon/react'
import { useStore } from '../../store/store'
import { useToast } from '../../providers/ToastProvider'

interface Props {
  open: boolean
  presetClientId?: string
  onClose: () => void
  onCreated?: (id: string) => void
}

export function CreateDeploymentModal({ open, presetClientId, onClose, onCreated }: Props) {
  const clients = useStore((s) => s.clients)
  const deployments = useStore((s) => s.deployments)
  const createDeployment = useStore((s) => s.createDeployment)
  const toast = useToast()

  const [name, setName] = useState('')
  const [clientId, setClientId] = useState(presetClientId ?? '')
  const [pkg, setPkg] = useState('')
  const [version, setVersion] = useState('v1.0.0')
  const [submitted, setSubmitted] = useState(false)

  useEffect(() => {
    if (open) {
      setName('')
      setClientId(presetClientId ?? '')
      setPkg('')
      setVersion('v1.0.0')
      setSubmitted(false)
    }
  }, [open, presetClientId])

  if (!open) return null

  const slug = name.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '')
  const nameTaken = deployments.some((d) => d.id === slug)
  const nameError = !slug ? 'Required' : nameTaken ? 'A deployment with this name already exists' : ''
  const clientError = !clientId ? 'Required' : ''
  const pkgError = !pkg.trim() ? 'Required' : ''
  const valid = !nameError && !clientError && !pkgError

  const submit = () => {
    setSubmitted(true)
    if (!valid) return
    const id = createDeployment({ name: slug, client_id: clientId, package: pkg.trim(), version: version.trim() || 'v1.0.0' })
    toast.success('Deployment created', id)
    onClose()
    onCreated?.(id)
  }

  const clientItems = clients.map((c) => ({ id: c.id, label: c.name }))

  return (
    <Modal
      open
      modalHeading="Create deployment"
      modalLabel="Deployments"
      primaryButtonText="Create deployment"
      secondaryButtonText="Cancel"
      onRequestClose={onClose}
      onRequestSubmit={submit}
    >
      <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
        <TextInput
          id="dep-name"
          labelText="Name"
          placeholder="acme-shop"
          helperText="Lowercase, used as the subdomain slug."
          value={name}
          onChange={(e) => setName(e.target.value)}
          invalid={submitted && !!nameError}
          invalidText={nameError}
        />
        <Dropdown
          id="dep-client"
          titleText="Client"
          label="Select a client"
          items={clientItems}
          itemToString={(i) => i?.label ?? ''}
          selectedItem={clientItems.find((i) => i.id === clientId) ?? null}
          onChange={({ selectedItem }) => setClientId(selectedItem?.id ?? '')}
          invalid={submitted && !!clientError}
          invalidText={clientError}
        />
        <TextInput
          id="dep-package"
          labelText="Package"
          placeholder="commerce-api"
          value={pkg}
          onChange={(e) => setPkg(e.target.value)}
          invalid={submitted && !!pkgError}
          invalidText={pkgError}
        />
        <TextInput id="dep-version" labelText="Version" value={version} onChange={(e) => setVersion(e.target.value)} />
      </div>
    </Modal>
  )
}
