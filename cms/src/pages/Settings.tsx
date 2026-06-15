import { useState, type ReactNode } from 'react'
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

interface Token {
  id: string
  name: string
  created_at: string
  secret: string
}

function Section({ title, description, children }: { title: string; description?: string; children: ReactNode }) {
  return (
    <div className="scns-card" style={{ padding: 16 }}>
      <div style={{ fontSize: 16, fontWeight: 600 }}>{title}</div>
      {description && <p className="scns-helper" style={{ fontSize: 13, margin: '4px 0 16px' }}>{description}</p>}
      {!description && <div style={{ height: 16 }} />}
      {children}
    </div>
  )
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
    { id: 't1', name: 'ci-deploy', created_at: new Date(Date.now() - 40 * 86400000).toISOString(), secret: 'cms_tok_live_a91f23cd77b0' },
  ])
  const [revoking, setRevoking] = useState<Token | null>(null)

  const createToken = () => {
    const secret = `cms_tok_live_${Math.random().toString(16).slice(2, 14)}`
    setTokens((t) => [...t, { id: `t_${t.length + 1}`, name: `token-${t.length + 1}`, created_at: new Date().toISOString(), secret }])
    toast.success('Token created', 'Copy it now — it will not be shown again.')
  }

  return (
    <div className="scns-page" style={{ maxWidth: 880 }}>
      <h1 style={{ fontSize: 24, fontWeight: 400, margin: '0 0 24px' }}>Settings</h1>

      <div style={{ display: 'flex', flexDirection: 'column', gap: 24 }}>
        <Section title="Operator profile">
          <div style={{ display: 'flex', flexDirection: 'column', gap: 16, maxWidth: 420 }}>
            <TextInput id="op-name" labelText="Name" value={name} onChange={(e) => setName(e.target.value)} />
            <TextInput id="op-email" labelText="Email" value={email} onChange={(e) => setEmail(e.target.value)} />
            <div>
              <Button
                onClick={() => {
                  updateSettings({ operator_name: name, operator_email: email })
                  toast.success('Profile saved')
                }}
              >
                Save profile
              </Button>
            </div>
          </div>
        </Section>

        <Section title="Defaults" description="Default grace period applied when moving a deployment to grace or on overdue enforcement.">
          <div style={{ maxWidth: 240 }}>
            <NumberInput
              id="default-grace"
              label="Default grace hours"
              min={1}
              max={720}
              value={graceHours}
              onChange={(_e, { value }) => setGraceHours(Number(value) || 72)}
            />
            <Button
              style={{ marginTop: 12 }}
              onClick={() => {
                updateSettings({ default_grace_hours: graceHours })
                toast.success('Default grace updated', `${graceHours}h`)
              }}
            >
              Save
            </Button>
          </div>
        </Section>

        <Section title="Notifications" description="Operator alerts for state changes and billing events.">
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
          </div>
        </Section>

        <Section title="Appearance" description="Theme is persisted to this browser.">
          <Toggle
            id="theme-toggle"
            labelText="Dark theme (Gray 100)"
            labelA="Light"
            labelB="Dark"
            toggled={theme === 'g100'}
            onToggle={() => toggle()}
          />
        </Section>

        <Section title="CMS API tokens" description="Tokens authenticate the CMS against deployment /control endpoints.">
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
                {tokens.map((t) => (
                  <TableRow key={t.id}>
                    <TableCell className="scns-mono">{t.name}</TableCell>
                    <TableCell>
                      <CopyableCode value={t.secret} masked={`cms_tok_live_${'•'.repeat(8)}`} secret />
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
                ))}
              </TableBody>
            </Table>
          </TableContainer>
          <Button kind="tertiary" size="sm" renderIcon={Add} style={{ marginTop: 16 }} onClick={createToken}>
            Create token
          </Button>
        </Section>
      </div>

      {revoking && (
        <TypedConfirmModal
          open
          title={`Revoke token ${revoking.name}?`}
          confirmWord={revoking.name}
          confirmLabel="Revoke token"
          body={<p>Any CMS instance using this token will lose access to deployment control endpoints immediately.</p>}
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
