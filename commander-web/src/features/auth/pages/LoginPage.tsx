import { useState } from 'react';
import { Button, InlineNotification, PasswordInput, TextInput } from '@carbon/react';
import { useLocation, useNavigate } from 'react-router-dom';
import type { ApiError } from '@services/api/mock/commander-mock';
import { useApp } from '@app/provider';

export function LoginPage() {
  const { api, signIn } = useApp();
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
          <strong>Commander</strong> <span className="cmd-dim">Control plane</span>
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
        {/* El título va dentro del formulario porque comparte con él la columna estrecha. */}
        <form className="cmd-stack" onSubmit={submit} noValidate>
          <h2 className="cmd-page__title">Entrar</h2>
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
            autoComplete="username"
            labelText="Correo del operador"
            value={email}
            invalid={Boolean(fields.email)}
            invalidText={fields.email}
            onChange={(e) => setEmail(e.target.value)}
          />
          <PasswordInput
            id="login-password"
            autoComplete="current-password"
            labelText="Contraseña"
            showPasswordLabel="Mostrar contraseña"
            hidePasswordLabel="Ocultar contraseña"
            tooltipPosition="left"
            value={password}
            invalid={Boolean(fields.password)}
            invalidText={fields.password}
            onChange={(e) => setPassword(e.target.value)}
          />
          {/* Aquí el prototipo pone «Entrar con permisos de». Se omite porque el proveedor fija
              el rol en una constante: el desplegable no cambiaría ni `can` ni `permissions`, y un
              control que no hace nada miente. Vuelve en cuanto el rol sea estado de la aplicación. */}
          <Button type="submit" disabled={busy}>
            {busy ? 'Autenticando…' : 'Entrar'}
          </Button>
          <p className="cmd-meter__caption">
            El token vive en memoria durante la sesión. Cualquier contraseña de seis caracteres o
            más entra en el prototipo.
          </p>
        </form>
      </section>
    </div>
  );
}
