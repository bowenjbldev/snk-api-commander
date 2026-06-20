import { useState, type FormEvent } from 'react'
import { Button, Form, InlineNotification, PasswordInput, TextInput } from '@carbon/react'
import { ArrowRight, Cloud } from '@carbon/icons-react'
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
    <div className="login-page">
      <section className="login-brand" aria-label="Product introduction">
        <div className="login-brand__image" />
        <div className="login-brand__content">
          <div>
            <div className="login-brand__mark">
              <Cloud size={24} aria-hidden="true" />
              <span>SnakeCloud Commander</span>
            </div>
            <h1>Enterprise infrastructure, instantiated with precision.</h1>
          </div>
          <p>
            Secure, high-density environments engineered for DevOps and IT
            administrators. Execute complex infrastructure deployments with
            unyielding mathematical logic.
          </p>
        </div>
      </section>

      <section className="login-panel" aria-labelledby="login-title">
        <div className="login-panel__inner">
          <div className="login-panel__mobile-brand">
            <Cloud size={24} aria-hidden="true" />
            <span>SnakeCloud Commander</span>
          </div>

          <div className="login-panel__heading">
            <h2 id="login-title">Log in</h2>
            <p>Access your provisioning dashboard.</p>
          </div>

          <Form className="login-form" onSubmit={onSubmit}>
            {error && (
              <InlineNotification
                kind="error"
                title="Sign-in failed"
                subtitle={error}
                lowContrast
                hideCloseButton
                style={{ marginBottom: '1rem', width: '100%', maxWidth: 'none' }}
              />
            )}

            <TextInput
              id="login-email"
              labelText="Email"
              type="email"
              placeholder="admin@snake.com"
              autoComplete="username"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              required
            />

            <PasswordInput
              id="login-password"
              labelText="Password"
              placeholder="Password"
              autoComplete="current-password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              required
            />

            <Button
              className="login-form__submit"
              type="submit"
              size="lg"
              renderIcon={ArrowRight}
              disabled={submitting}
            >
              {submitting ? 'Signing in...' : 'Log in'}
            </Button>

            <p style={{ fontSize: 13, color: 'var(--cds-text-secondary)', marginTop: '1rem' }}>
              Demo access: <code>admin@snake.com</code> / <code>secret1234</code>
            </p>
          </Form>
        </div>
      </section>
    </div>
  )
}
