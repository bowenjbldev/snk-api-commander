import { useState } from 'react'
import {
  Button,
  ContentSwitcher,
  FormGroup,
  NumberInput,
  Switch,
  TextInput,
  Toggle,
} from '@carbon/react'
import { Card, CopyableCode, PageHeader } from '../components/ui'
import { useTheme } from '../theme/ThemeContext'
import { useToast } from '../notify/ToastProvider'

export function Settings() {
  const { theme, setTheme } = useTheme()
  const toast = useToast()
  const [name, setName] = useState('Operator')
  const [email, setEmail] = useState('bowenjbl.dev@gmail.com')
  const [graceHours, setGraceHours] = useState(72)
  const [notifyOverdue, setNotifyOverdue] = useState(true)
  const [notifyQuota, setNotifyQuota] = useState(true)
  const [notifyGrace, setNotifyGrace] = useState(false)

  return (
    <div className="sc-page" style={{ maxWidth: 880 }}>
      <PageHeader
        title="Settings"
        subtitle="Operator profile, defaults and CMS API access."
        right={
          <Button kind="primary" onClick={() => toast.success('Settings saved')}>
            Save changes
          </Button>
        }
      />

      <div style={{ display: 'grid', gap: 16 }}>
        <Card title="Operator profile">
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
            <TextInput
              id="op-name"
              labelText="Name"
              value={name}
              onChange={(e) => setName(e.target.value)}
            />
            <TextInput
              id="op-email"
              labelText="Email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
            />
          </div>
        </Card>

        <Card title="Appearance">
          <FormGroup legendText="Theme">
            <div style={{ maxWidth: 320 }}>
              <ContentSwitcher
                selectedIndex={theme === 'g100' ? 0 : 1}
                onChange={({ index }) => setTheme(index === 0 ? 'g100' : 'g10')}
              >
                <Switch name="g100" text="Dark (g100)" />
                <Switch name="g10" text="Light (g10)" />
              </ContentSwitcher>
            </div>
          </FormGroup>
        </Card>

        <Card title="Enforcement defaults">
          <div style={{ maxWidth: 240 }}>
            <NumberInput
              id="grace-hours"
              label="Default grace window (hours)"
              helperText="Applied when moving a deployment to grace."
              min={1}
              max={336}
              value={graceHours}
              onChange={(_e, { value }) =>
                setGraceHours(typeof value === 'number' ? value : Number(value) || 72)
              }
            />
          </div>
        </Card>

        <Card title="Notifications">
          <div style={{ display: 'grid', gap: 12, maxWidth: 420 }}>
            <Toggle
              id="n-overdue"
              size="sm"
              labelText="Invoice overdue"
              toggled={notifyOverdue}
              onToggle={setNotifyOverdue}
            />
            <Toggle
              id="n-quota"
              size="sm"
              labelText="Quota exceeded"
              toggled={notifyQuota}
              onToggle={setNotifyQuota}
            />
            <Toggle
              id="n-grace"
              size="sm"
              labelText="Grace ending soon"
              toggled={notifyGrace}
              onToggle={setNotifyGrace}
            />
          </div>
        </Card>

        <Card title="CMS API token">
          <p style={{ fontSize: 13, color: 'var(--cds-text-secondary)', marginBottom: 12 }}>
            Used by the control plane to call <span className="sc-mono">/api/v1/control/*</span> on each deployment.
          </p>
          <CopyableCode value="cms_live_8Fa2Kd9XpLm7Qz0Rt4Vc1Bn" />
          <div style={{ marginTop: 12 }}>
            <Button
              kind="tertiary"
              onClick={() => toast.success('Token rotated', 'The previous token is now invalid.')}
            >
              Rotate token…
            </Button>
          </div>
        </Card>
      </div>
    </div>
  )
}
