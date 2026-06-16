import { useState } from 'react'
import {
  TextInput,
  NumberInput,
  Button,
  Toggle,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableHeader,
  TableRow,
} from '@carbon/react'
import { Add } from '@carbon/icons-react'
import { useStore } from '../store/store'
import { useAppTheme } from '../providers/ThemeProvider'
import { useToast } from '../providers/ToastProvider'
import { TypedConfirmModal } from '../components/modals/TypedConfirmModal'
import { CopyableCode } from '../components/CopyableCode'
import { RelativeTime } from '../components/RelativeTime'
import { EmptyState } from '../components/EmptyState'
import { PageHeader } from '../components/PageHeader'

interface Token {
  id: string
  name: string
  created_at: string
  secret: string
}

export function Settings() {
  const settings = useStore((s) => s.settings)
  const updateSettings = useStore((s) => s.updateSettings)
  const { theme, toggle } = useAppTheme()
  const toast = useToast()

  const [name, setName] = useState(settings.operator_name)
  const [email, setEmail] = useState(settings.operator_email)
  const [graceHours, setGraceHours] = useState(settings.default_grace_hours)
  const [tokens, setTokens] = useState<Token[]>([
    { id: 't1', name: 'ci-deploy', created_at: new Date(Date.now() - 40 * 86400000).toISOString(), secret: 'commander_tok_live_a91f23cd77b0' },
  ])
  const [revoking, setRevoking] = useState<Token | null>(null)

  const hasChanges = name !== settings.operator_name || email !== settings.operator_email || graceHours !== settings.default_grace_hours

  const savePreferences = () => {
    updateSettings({ operator_name: name, operator_email: email, default_grace_hours: graceHours })
    toast.success('Preferences saved')
  }

  const createToken = () => {
    const secret = `commander_tok_live_${Math.random().toString(16).slice(2, 14)}`
    setTokens((t) => [...t, { id: `t_${t.length + 1}`, name: `token-${t.length + 1}`, created_at: new Date().toISOString(), secret }])
    toast.success('Token created', 'Copy it now — it will not be shown again.')
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

        {/* Tokens Card */}
        <div className="scns-card">
          <div style={{ padding: 24 }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
              <div>
                <div style={{ fontSize: 18, fontWeight: 600 }}>Commander API tokens</div>
                <div className="scns-helper" style={{ fontSize: 13, marginTop: 4 }}>Tokens authenticate the Commander against deployment /control endpoints.</div>
              </div>
              <Button kind="primary" size="sm" renderIcon={Add} onClick={createToken}>
                Create token
              </Button>
            </div>
            <TableContainer>
              <Table size="sm">
                <TableHead>
                  <TableRow>
                    <TableHeader>Name</TableHeader>
                    <TableHeader>Secret</TableHeader>
                    <TableHeader>Created</TableHeader>
                    <TableHeader aria-label="Actions" />
                  </TableRow>
                </TableHead>
                <TableBody>
                  {tokens.length === 0 ? (
                    <TableRow>
                      <TableCell colSpan={4}>
                        <EmptyState title="No API tokens" description="Create a token to authenticate with Commander API." />
                      </TableCell>
                    </TableRow>
                  ) : (
                    tokens.map((t) => (
                      <TableRow key={t.id}>
                        <TableCell className="scns-mono">{t.name}</TableCell>
                        <TableCell>
                          <CopyableCode value={t.secret} masked={`commander_tok_live_${'•'.repeat(8)}`} secret />
                        </TableCell>
                        <TableCell>
                          <RelativeTime iso={t.created_at} />
                        </TableCell>
                        <TableCell>
                          <Button kind="danger--ghost" size="sm" onClick={() => setRevoking(t)}>
                            Revoke…
                          </Button>
                        </TableCell>
                      </TableRow>
                    ))
                  )}
                </TableBody>
              </Table>
            </TableContainer>
          </div>
        </div>
      </div>

      {revoking && (
        <TypedConfirmModal
          open
          title={`Revoke token ${revoking.name}?`}
          confirmWord={revoking.name}
          confirmLabel="Revoke token"
          body={<p>Any Commander instance using this token will lose access to deployment control endpoints immediately.</p>}
          onClose={() => setRevoking(null)}
          onConfirm={() => {
            setTokens((t) => t.filter((x) => x.id !== revoking.id))
            toast.success('Token revoked', revoking.name)
            setRevoking(null)
          }}
        />
      )}
    </div>
  )
}