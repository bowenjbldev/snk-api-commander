import { useMemo, useState } from 'react';
import {
  Button,
  Callout,
  InlineNotification,
  ProgressIndicator,
  ProgressStep,
  RadioTile,
  SkeletonText,
  TileGroup,
} from '@carbon/react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { METRICS, fmtMoney, fmtNum } from '@services/api/mock/commander-mock';
import type { ApiError } from '@services/api/mock/commander-mock';
import { useApp } from '@app/provider';
import { useResource } from '@shared/hooks/useResource';
import { lut, pending } from '@shared/lib/data';
import { PageHeader } from '@shared/components/PageHeader';
import { Defs, Panel } from '@shared/components/Detail';
import { Field } from '@shared/components/FormPage';
import { ErrorState, Forbidden } from '@shared/components/States';

type Mode = 'new_client' | 'extra_app' | 'group_item';

const STEPS = [
  { label: 'Modalidad', description: 'Paso 1: qué se está dando de alta' },
  { label: 'Aplicación y plan', description: 'Paso 2: producto y plantilla de límites' },
  { label: 'Cuenta, contrato y cliente', description: 'Paso 3: a quién pertenece la instancia' },
  { label: 'Administrador tenant', description: 'Paso 4: credenciales del primer usuario' },
  { label: 'Revisión', description: 'Paso 5: comprobar antes de enviar' },
];

/** Un 422 del servidor vuelve al paso donde se escribió el campo, no al principio. */
function stepFor(key: string): number {
  if (key.startsWith('admin.')) return 3;
  if (key === 'plan_id' || key === 'application_id') return 1;
  return 2;
}

const slugify = (value: string) =>
  value
    .toLowerCase()
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/(^-|-$)/g, '');

export function ProvisioningPage() {
  const { api, can } = useApp();
  const navigate = useNavigate();
  const [params] = useSearchParams();

  const res = useResource(
    () =>
      Promise.all([
        api.list('applications'),
        api.list('plans'),
        api.list('accounts'),
        api.list('clients'),
        api.list('subscriptions'),
        api.list('items'),
      ]),
    [],
  );
  const [applications, plans, accounts, clients, subscriptions, items] = pending(res.data, [[], [], [], [], [], []]);

  const preselectedClient = params.get('client');
  const preselectedSubscription = params.get('subscription');

  const [step, setStep] = useState(preselectedClient || preselectedSubscription ? 1 : 0);
  const [mode, setMode] = useState<Mode>(
    preselectedSubscription ? 'group_item' : preselectedClient ? 'extra_app' : 'new_client',
  );
  const [applicationId, setApplicationId] = useState('');
  const [planId, setPlanId] = useState('');
  const [clientId, setClientId] = useState(preselectedClient ?? '');
  const [subscriptionId, setSubscriptionId] = useState(preselectedSubscription ?? '');
  const [accountId, setAccountId] = useState(params.get('account') ?? '');
  const [account, setAccount] = useState({ name: '', email: '', billing_email: '', tax_id: '', payment_terms: '30 días', address: '' });
  const [client, setClient] = useState({ name: '', slug: '', email: '' });
  const [slugTouched, setSlugTouched] = useState(false);
  const [admin, setAdmin] = useState({ name: '', email: '', password: '' });
  const [simulateFailure, setSimulateFailure] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [global, setGlobal] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const appById = useMemo(() => lut(applications), [applications]);
  const planById = useMemo(() => lut(plans), [plans]);
  const cliById = useMemo(() => lut(clients), [clients]);

  if (!can('provision instances')) return <Forbidden perm="provision instances" />;
  if (res.error) return <ErrorState error={res.error} onRetry={res.reload} />;
  if (res.loading) return <SkeletonText paragraph lineCount={8} />;

  const appId = applicationId || String(applications[0]?.id ?? '');
  const application = appById.get(Number(appId));
  const appPlans = plans.filter((p) => p.application_id === Number(appId) && p.status === 'active');
  const plan = planById.get(Number(planId)) ?? appPlans[0];
  const negotiated = subscriptions.filter((s) => s.mode === 'negotiated' && s.status !== 'canceled');
  const subscription = subscriptions.find((s) => String(s.id) === subscriptionId);
  const activeItems = subscription
    ? items.filter((it) => it.subscription_id === subscription.id && it.status === 'active').length
    : 0;

  const derivedSlug = slugTouched ? client.slug : slugify(client.name);
  const derivedDatabase =
    application && derivedSlug ? `kk_${derivedSlug.replace(/-/g, '_')}_${application.slug.slice(0, 6)}` : '—';

  function localErrors(target: number): Record<string, string> {
    const found: Record<string, string> = {};
    if (target >= 1 && !plan) found.plan_id = 'Elige el plan.';
    if (target >= 2) {
      if (mode === 'extra_app' && !clientId) found.client_id = 'Elige el cliente existente.';
      if (mode === 'group_item' && !subscriptionId) found.subscription_id = 'Elige el contrato negociado.';
      if (mode !== 'extra_app') {
        if (!client.name) found['client.name'] = 'Nombre del cliente obligatorio.';
        if (!/^[a-z0-9-]{3,40}$/.test(derivedSlug)) found['client.slug'] = 'Slug con minúsculas, números y guiones.';
      }
      if (mode === 'new_client' && !accountId && !account.name) found['account.name'] = 'Nombre de la cuenta obligatorio.';
    }
    if (target >= 3) {
      if (!admin.name) found['admin.name'] = 'El nombre del administrador es obligatorio.';
      if (!/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i.test(admin.email)) found['admin.email'] = 'Formato de correo no válido.';
      if (admin.password.length < 12) found['admin.password'] = 'Al menos doce caracteres.';
      else if (!/[a-zA-Z]/.test(admin.password) || !/[0-9]/.test(admin.password)) {
        found['admin.password'] = 'Debe combinar letras y números.';
      }
    }
    return found;
  }

  function next() {
    const found = localErrors(step);
    if (Object.keys(found).length) {
      setErrors(found);
      return;
    }
    setErrors({});
    setStep(step + 1);
  }

  async function submit() {
    if (busy) return;
    setBusy(true);
    setErrors({});
    setGlobal(null);
    try {
      const result = await api.provision({
        mode,
        application_id: Number(appId),
        plan_id: plan?.id,
        client_id: mode === 'extra_app' ? Number(clientId) : undefined,
        subscription_id: mode === 'group_item' ? Number(subscriptionId) : undefined,
        account_id: mode === 'new_client' && accountId ? Number(accountId) : null,
        account,
        client: { ...client, slug: derivedSlug },
        admin,
        simulate_failure: simulateFailure,
      });
      navigate(`/instances/${result.instance.id}/operation`);
    } catch (e) {
      const err = e as ApiError;
      if (err.fields) {
        setErrors(err.fields);
        setStep(Math.min(...Object.keys(err.fields).map(stepFor)));
      } else {
        setGlobal(err.message);
      }
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      <PageHeader
        title="Nueva alta"
        lede="Un único POST /provisioning crea lo que falte y encola el trabajo. El alta responde 202: el estado real llega consultando la ficha de la instancia."
        crumbs={[{ label: 'Instancias', to: '/instances' }, { label: 'Nueva alta' }]}
      />

      <ProgressIndicator
        currentIndex={step}
        spaceEqually
        onChange={(index: number) => {
          // Solo hacia atrás: saltar adelante se saltaría la validación del paso actual.
          if (index < step) setStep(index);
        }}
      >
        {STEPS.map((s) => (
          <ProgressStep key={s.label} label={s.label} description={s.description} />
        ))}
      </ProgressIndicator>

      <div className="cmd-stack" style={{ marginBlockStart: '2rem' }}>
        {global && (
          <InlineNotification kind="error" lowContrast hideCloseButton title="No se pudo dar de alta" subtitle={global} />
        )}

        {step === 0 && (
          <TileGroup
            name="mode"
            legend="¿Qué se está dando de alta?"
            valueSelected={mode}
            onChange={(value) => setMode(value as Mode)}
          >
            <RadioTile value="new_client" id="mode-new">
              <strong>Cliente individual nuevo</strong>
              <p className="cmd-meter__caption">
                Commander crea cuenta, cliente, contrato de catálogo e instancia en una sola petición.
              </p>
            </RadioTile>
            <RadioTile value="extra_app" id="mode-extra">
              <strong>Aplicación adicional</strong>
              <p className="cmd-meter__caption">
                Un cliente que ya existe estrena otra aplicación. Se envía client_id y nace su contrato de catálogo.
              </p>
            </RadioTile>
            <RadioTile value="group_item" id="mode-group">
              <strong>Cabecera de grupo</strong>
              <p className="cmd-meter__caption">
                Nuevo cliente dentro de un contrato negociado existente. Consume capacidad del contrato.
              </p>
            </RadioTile>
          </TileGroup>
        )}

        {step === 1 && (
          <>
            <div className="cmd-form__grid">
              <Field
                field={{
                  key: 'application_id',
                  label: 'Aplicación',
                  type: 'select',
                  options: applications
                    .filter((a) => a.status === 'active')
                    .map((a) => ({ value: String(a.id), label: a.name })),
                }}
                value={appId}
                onChange={(v) => {
                  setApplicationId(String(v));
                  setPlanId('');
                }}
              />
              <Field
                field={{
                  key: 'plan_id',
                  label: 'Plan',
                  type: 'select',
                  options: appPlans.map((p) => ({
                    value: String(p.id),
                    label: `${p.name} · ${fmtMoney(p.monthly_price, p.currency)}`,
                  })),
                }}
                value={String(plan?.id ?? '')}
                error={errors.plan_id}
                onChange={(v) => setPlanId(String(v))}
              />
            </div>

            {plan && (
              <Panel title="Límites que se materializarán">
                <Defs
                  items={plan.limits.map((limit) => ({
                    label: METRICS[limit.metric_key]?.name ?? limit.metric_key,
                    value:
                      limit.limit_value === null
                        ? 'Sin límite (solo medición)'
                        : `${fmtNum(limit.limit_value)} ${METRICS[limit.metric_key]?.unit ?? ''} · aviso al ${limit.warning_percent} %`,
                  }))}
                />
                <p className="cmd-meter__caption">
                  Estos valores se copian a la instancia en el momento del alta. Editar la plantilla después no los cambia.
                </p>
              </Panel>
            )}
          </>
        )}

        {step === 2 && (
          <div className="cmd-stack">
            {mode === 'extra_app' && (
              <Field
                field={{
                  key: 'client_id',
                  label: 'Cliente existente',
                  type: 'select',
                  options: [
                    { value: '', label: 'Elige el cliente' },
                    ...clients.map((c) => ({ value: String(c.id), label: `${c.name} · ${c.slug}` })),
                  ],
                }}
                value={clientId}
                error={errors.client_id}
                onChange={(v) => setClientId(String(v))}
              />
            )}

            {mode === 'group_item' && (
              <>
                <Field
                  field={{
                    key: 'subscription_id',
                    label: 'Contrato negociado',
                    type: 'select',
                    options: [
                      { value: '', label: 'Elige el contrato' },
                      ...negotiated.map((s) => ({ value: String(s.id), label: s.name })),
                    ],
                  }}
                  value={subscriptionId}
                  error={errors.subscription_id}
                  onChange={(v) => setSubscriptionId(String(v))}
                />
                {subscription && (
                  <Callout
                    kind={activeItems >= subscription.max_instances ? 'warning' : 'info'}
                    lowContrast
                    title="Capacidad del contrato"
                    subtitle={`${activeItems} de ${subscription.max_instances} cabeceras activas. ${
                      activeItems >= subscription.max_instances
                        ? 'Está agotada: amplía la capacidad antes de añadir otra.'
                        : 'Queda hueco para esta cabecera.'
                    }`}
                  />
                )}
              </>
            )}

            {mode === 'new_client' && (
              <>
                <Field
                  field={{
                    key: 'account_id',
                    label: 'Cuenta pagadora',
                    type: 'select',
                    options: [
                      { value: '', label: 'Crear una cuenta nueva' },
                      ...accounts.map((a) => ({ value: String(a.id), label: a.name })),
                    ],
                  }}
                  value={accountId}
                  onChange={(v) => setAccountId(String(v))}
                />
                {!accountId && (
                  <div className="cmd-form__grid">
                    <Field
                      field={{ key: 'account.name', label: 'Nombre de la cuenta' }}
                      value={account.name}
                      error={errors['account.name']}
                      onChange={(v) => setAccount({ ...account, name: String(v) })}
                    />
                    <Field
                      field={{ key: 'account.email', label: 'Correo de la cuenta', type: 'email' }}
                      value={account.email}
                      onChange={(v) => setAccount({ ...account, email: String(v) })}
                    />
                    <Field
                      field={{ key: 'account.billing_email', label: 'Correo de facturación', type: 'email' }}
                      value={account.billing_email}
                      onChange={(v) => setAccount({ ...account, billing_email: String(v) })}
                    />
                    <Field
                      field={{ key: 'account.tax_id', label: 'NIF' }}
                      value={account.tax_id}
                      onChange={(v) => setAccount({ ...account, tax_id: String(v) })}
                    />
                  </div>
                )}
              </>
            )}

            {mode !== 'extra_app' && (
              <div className="cmd-form__grid">
                <Field
                  field={{ key: 'client.name', label: 'Nombre del cliente' }}
                  value={client.name}
                  error={errors['client.name']}
                  onChange={(v) => setClient({ ...client, name: String(v) })}
                />
                <Field
                  field={{
                    key: 'client.slug',
                    label: 'Slug (subdominio)',
                    helper: slugTouched ? 'Minúsculas, números y guiones.' : 'Derivado del nombre hasta que lo edites.',
                  }}
                  value={derivedSlug}
                  error={errors['client.slug']}
                  onChange={(v) => {
                    setSlugTouched(true);
                    setClient({ ...client, slug: String(v) });
                  }}
                />
                <Field
                  field={{ key: 'client.email', label: 'Correo de contacto', type: 'email' }}
                  value={client.email}
                  onChange={(v) => setClient({ ...client, email: String(v) })}
                />
              </div>
            )}
          </div>
        )}

        {step === 3 && (
          <>
            <div className="cmd-form__grid">
              <Field
                field={{ key: 'admin.name', label: 'Nombre del administrador' }}
                value={admin.name}
                error={errors['admin.name']}
                onChange={(v) => setAdmin({ ...admin, name: String(v) })}
              />
              <Field
                field={{ key: 'admin.email', label: 'Correo del administrador', type: 'email' }}
                value={admin.email}
                error={errors['admin.email']}
                onChange={(v) => setAdmin({ ...admin, email: String(v) })}
              />
              <Field
                field={{
                  key: 'admin.password',
                  label: 'Contraseña',
                  type: 'password',
                  helper: 'Doce caracteres como mínimo, con letras y números. Se envía una vez y no se recupera.',
                }}
                value={admin.password}
                error={errors['admin.password']}
                onChange={(v) => setAdmin({ ...admin, password: String(v) })}
              />
              <Field
                field={{
                  key: 'simulate_failure',
                  label: 'Simular un fallo de provisioning',
                  type: 'checkbox',
                  helper: 'Solo en el prototipo: fuerza que el trabajo termine en failed para ver el camino de retry.',
                }}
                value={simulateFailure}
                onChange={(v) => setSimulateFailure(Boolean(v))}
              />
            </div>
          </>
        )}

        {step === 4 && (
          <Panel title="Revisión">
            <Defs
              items={[
                { label: 'Modalidad', value: modeLabel(mode) },
                { label: 'Aplicación', value: application?.name ?? '—' },
                { label: 'Plan', value: plan ? `${plan.name} · ${fmtMoney(plan.monthly_price, plan.currency)}` : '—' },
                {
                  label: 'Cuenta',
                  value:
                    mode === 'new_client'
                      ? accountId
                        ? (accounts.find((a) => String(a.id) === accountId)?.name ?? '—')
                        : `${account.name} (nueva)`
                      : mode === 'group_item'
                        ? (accounts.find((a) => a.id === subscription?.account_id)?.name ?? '—')
                        : (accounts.find((a) => a.id === cliById.get(Number(clientId))?.account_id)?.name ?? '—'),
                },
                {
                  label: 'Contrato',
                  value:
                    mode === 'group_item'
                      ? (subscription?.name ?? '—')
                      : 'Se creará un contrato de catálogo con el precio del plan',
                },
                {
                  label: 'Cliente',
                  value:
                    mode === 'extra_app'
                      ? (cliById.get(Number(clientId))?.name ?? '—')
                      : `${client.name} · ${derivedSlug}`,
                },
                { label: 'Administrador tenant', value: `${admin.name} · ${admin.email}` },
                { label: 'Contraseña', value: '•'.repeat(Math.max(0, admin.password.length)) },
                {
                  label: 'Base de datos derivada',
                  value: <span className="cmd-mono">{mode === 'extra_app' ? 'La derivará el servidor' : derivedDatabase}</span>,
                },
              ]}
            />
            <p className="cmd-meter__caption">
              La base se muestra como información: la deriva el servidor y no es un campo editable.
            </p>
          </Panel>
        )}

        <div className="cmd-page__actions">
          {step > 0 && (
            <Button kind="secondary" onClick={() => setStep(step - 1)} disabled={busy}>
              Anterior
            </Button>
          )}
          {step < 4 && <Button onClick={next}>Siguiente</Button>}
          {step === 4 && (
            <Button onClick={submit} disabled={busy}>
              {busy ? 'Enviando…' : 'Dar de alta'}
            </Button>
          )}
          <Button kind="ghost" onClick={() => navigate('/instances')} disabled={busy}>
            Cancelar
          </Button>
        </div>

        {mode === 'group_item' && step === 2 && negotiated.length === 0 && (
          <Callout
            kind="info"
            lowContrast
            title="No hay contratos negociados"
            subtitle="Un grupo nuevo se prepara creando antes su cuenta y su contrato. Commander no finge una transacción distribuida que la API no ofrece."
          />
        )}
      </div>
    </>
  );
}

function modeLabel(mode: Mode) {
  if (mode === 'new_client') return 'Cliente individual nuevo';
  if (mode === 'extra_app') return 'Aplicación adicional';
  return 'Cabecera de grupo';
}
