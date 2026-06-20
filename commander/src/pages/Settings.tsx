import { useState } from 'react'
import { TextInput, NumberInput, Button, Toggle } from '@carbon/react'
import { useStore } from '../store/store'
import { useAppTheme } from '../providers/ThemeProvider'
import { useToast } from '../providers/ToastProvider'
import { PageHeader } from '../components/PageHeader'

export function Settings() {
  const settings = useStore((s) => s.settings)
  const updateSettings = useStore((s) => s.updateSettings)
  const { theme, toggle } = useAppTheme()
  const toast = useToast()

  const [name, setName] = useState(settings.operator_name)
  const [email, setEmail] = useState(settings.operator_email)
  const [graceHours, setGraceHours] = useState(settings.default_grace_hours)

  const hasChanges = name !== settings.operator_name || email !== settings.operator_email || graceHours !== settings.default_grace_hours

  const savePreferences = () => {
    updateSettings({ operator_name: name, operator_email: email, default_grace_hours: graceHours })
    toast.success('Preferences saved')
  }

  return (
    <div className="scns-page">
      <PageHeader title="Settings" subtitle="Manage your operator profile and platform preferences" />

      <div style={{ display: 'flex', flexDirection: 'column', gap: 32, paddingBottom: 64 }}>

        {/* General Preferences Card */}
        <div className="scns-card">
          <div style={{ padding: '24px 24px 8px', fontSize: 18, fontWeight: 600 }}>General Preferences</div>

          <div style={{ padding: 24, display: 'flex', flexDirection: 'column', gap: 32 }}>

            {/* Profile */}
            <div style={{ display: 'grid', gridTemplateColumns: 'minmax(200px, 1fr) 2fr', gap: 24 }}>
              <div>
                <div style={{ fontSize: 14, fontWeight: 600 }}>Operator Profile</div>
                <div className="scns-helper" style={{ fontSize: 13, marginTop: 4 }}>
                  Contact details for platform alerts.
                </div>
              </div>
              <div style={{ display: 'flex', flexDirection: 'column', gap: 16, maxWidth: 400 }}>
                <TextInput id="op-name" labelText="Name" value={name} onChange={(e) => setName(e.target.value)} />
                <TextInput id="op-email" labelText="Email" value={email} onChange={(e) => setEmail(e.target.value)} />
              </div>
            </div>

            <hr style={{ border: 'none', borderTop: '1px solid var(--scns-card-border)', margin: 0 }} />

            {/* Defaults */}
            <div style={{ display: 'grid', gridTemplateColumns: 'minmax(200px, 1fr) 2fr', gap: 24 }}>
              <div>
                <div style={{ fontSize: 14, fontWeight: 600 }}>Platform Defaults</div>
                <div className="scns-helper" style={{ fontSize: 13, marginTop: 4 }}>
                  Default grace period applied when moving a deployment to grace or on overdue enforcement.
                </div>
              </div>
              <div style={{ maxWidth: 200 }}>
                <NumberInput
                  id="default-grace"
                  label="Default grace hours"
                  min={1}
                  max={720}
                  value={graceHours}
                  onChange={(_e, { value }) => setGraceHours(Number(value) || 72)}
                />
              </div>
            </div>

            <hr style={{ border: 'none', borderTop: '1px solid var(--scns-card-border)', margin: 0 }} />

            {/* Toggles */}
            <div style={{ display: 'grid', gridTemplateColumns: 'minmax(200px, 1fr) 2fr', gap: 24 }}>
              <div>
                <div style={{ fontSize: 14, fontWeight: 600 }}>Notifications & Theme</div>
                <div className="scns-helper" style={{ fontSize: 13, marginTop: 4 }}>
                  Alerts and visual appearance.
                </div>
              </div>
              <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
                <Toggle
                  id="notify-suspend"
                  labelText="Notify on suspend"
                  labelA="Off"
                  labelB="On"
                  toggled={settings.notify_on_suspend}
                  onToggle={(v) => {
                    updateSettings({ notify_on_suspend: v })
                    toast.success('Notifications updated')
                  }}
                />
                <Toggle
                  id="notify-overdue"
                  labelText="Notify on overdue invoice"
                  labelA="Off"
                  labelB="On"
                  toggled={settings.notify_on_overdue}
                  onToggle={(v) => {
                    updateSettings({ notify_on_overdue: v })
                    toast.success('Notifications updated')
                  }}
                />
                <Toggle
                  id="theme-toggle"
                  labelText="Dark theme (Gray 100)"
                  labelA="Light"
                  labelB="Dark"
                  toggled={theme === 'g100'}
                  onToggle={() => toggle()}
                />
              </div>
            </div>

          </div>

          {/* Form Footer */}
          <div style={{ padding: '16px 24px', background: 'var(--cds-layer-accent)', borderTop: '1px solid var(--scns-card-border)', display: 'flex', justifyContent: 'flex-end' }}>
            <Button disabled={!hasChanges} onClick={savePreferences}>
              Save preferences
            </Button>
          </div>
        </div>
      </div>
    </div>
  )
}
