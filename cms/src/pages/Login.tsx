import { useState, type FormEvent } from 'react'
import { Button, Form, InlineNotification, PasswordInput, Stack, TextInput } from '@carbon/react'
import { useAuth } from '../store/auth'

export function Login() {
  const login = useAuth((s) => s.login)
  const error = useAuth((s) => s.error)
  const [email, setEmail] = useState('admin@snake.com')
  const [password, setPassword] = useState('')
  const [submitting, setSubmitting] = useState(false)

  const onSubmit = async (e: FormEvent) => {
    e.preventDefault()
    setSubmitting(true)
    try {
      await login(email, password)
    } catch {
      // error is rendered from the auth store below
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div
      style={{
        minHeight: '100vh',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        padding: 24,
      }}
    >
      <div style={{ width: '100%', maxWidth: 360 }}>
        <div style={{ marginBottom: 24, display: 'flex', alignItems: 'baseline', gap: 6 }}>
          <span style={{ fontSize: 20, fontWeight: 600 }}>SnakeCloud</span>
          <span style={{ fontSize: 20, fontWeight: 400, color: 'var(--cds-text-secondary)' }}>CMS</span>
        </div>

        <Form onSubmit={onSubmit}>
          <Stack gap={6}>
            {error && (
              <InlineNotification
                kind="error"
                title="Sign-in failed"
                subtitle={error}
                lowContrast
                hideCloseButton
              />
            )}
            <TextInput
              id="login-email"
              labelText="Email"
              type="email"
              autoComplete="username"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              required
            />
            <PasswordInput
              id="login-password"
              labelText="Password"
              autoComplete="current-password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              required
            />
            <Button type="submit" disabled={submitting} style={{ width: '100%', maxWidth: 'none' }}>
              {submitting ? 'Signing in…' : 'Sign in'}
            </Button>
            <p style={{ fontSize: 12, color: 'var(--cds-text-secondary)' }}>
              Demo: <code>admin@snake.com</code> / <code>secret1234</code>
            </p>
          </Stack>
        </Form>
      </div>
    </div>
  )
}
