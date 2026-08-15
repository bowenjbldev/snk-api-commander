import { useState } from 'react';
import { Button, InlineNotification, PasswordInput, Select, SelectItem, TextInput } from '@carbon/react';
import { useLocation, useNavigate } from 'react-router-dom';
import { ROLES } from '@services/api/mock/commander-mock';
import type { ApiError } from '@services/api/mock/commander-mock';
import { useApp } from '@app/provider';

export function LoginPage() {
  const { api, sim, setSim, signIn } = useApp();
  const navigate = useNavigate();
  const location = useLocation();
  const from = (location.state as { from?: string } | null)?.from ?? '/';

  const [email, setEmail] = useState('marta.ferreiro@commander.es');
  const [password, setPassword] = useState('commander2026');
  const [fields, setFields] = useState<Record<string, string>>({});
  const [global, setGlobal] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    setBusy(true);
    setFields({});
    setGlobal(null);
    try {
      const session = await api.login(email, password);
      signIn(session.token, session.operator);
      navigate(from, { replace: true });
    } catch (e) {
      const err = e as ApiError;
      if (err.fields) setFields(err.fields);
      else setGlobal(err.message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="cmd-login">
      <aside className="cmd-login__aside">
        <p>
          <strong>Commander</strong> <span className="cmd-meter__caption">Control plane</span>
        </p>
        <h1 className="cmd-login__claim">
          Cuentas, clientes, aplicaciones e instancias en un solo plano de control.
        </h1>
        <p className="cmd-page__lede">
          Una cuenta paga, un cliente es el tenant, una aplicación es el producto y una instancia es
          el par cliente-aplicación. Este prototipo no accede a datos de negocio de ningún tenant.
        </p>
        <p className="cmd-login__foot">
          Prototipo con datos simulados · Carbon Design System v11 · tema Gray 100
        </p>
      </aside>

      <section className="cmd-login__form">
        <h2 className="cmd-page__title">Entrar</h2>
        <form className="cmd-stack" onSubmit={submit} noValidate>
          {global && (
            <InlineNotification
              kind="error"
              lowContrast
              hideCloseButton
              title="No se pudo entrar"
              subtitle={global}
            />
          )}
          <TextInput
            id="login-email"
            type="email"
            labelText="Correo del operador"
            value={email}
            invalid={Boolean(fields.email)}
            invalidText={fields.email}
            onChange={(e) => setEmail(e.target.value)}
          />
          <PasswordInput
            id="login-password"
            labelText="Contraseña"
            showPasswordLabel="Mostrar contraseña"
            hidePasswordLabel="Ocultar contraseña"
            tooltipPosition="left"
            value={password}
            invalid={Boolean(fields.password)}
            invalidText={fields.password}
            onChange={(e) => setPassword(e.target.value)}
          />
          <Select
            id="login-role"
            labelText="Entrar con permisos de"
            helperText={ROLES[sim.role].hint}
            value={sim.role}
            onChange={(e) => setSim({ role: e.target.value })}
          >
            {Object.entries(ROLES).map(([key, role]) => (
              <SelectItem key={key} value={key} text={role.label} />
            ))}
          </Select>
          <Button type="submit" disabled={busy}>
            {busy ? 'Entrando…' : 'Entrar'}
          </Button>
          <p className="cmd-meter__caption">
            El token vive en <code className="cmd-mono">sessionStorage</code> durante la sesión.
            Cualquier contraseña de seis caracteres o más entra en el prototipo.
          </p>
        </form>
      </section>
    </div>
  );
}
