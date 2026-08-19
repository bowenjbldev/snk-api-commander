import { useState } from 'react';
import type { CSSProperties } from 'react';
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
import { METRICS, QUOTA_ACTION, fmtMoney, fmtNum } from '@services/api/mock/commander-mock';
import type { ApiError } from '@services/api/commanderApi';
import { useApp } from '@app/provider';
import { useResource } from '@shared/hooks/useResource';
import { pending } from '@shared/lib/data';
import { PageHeader } from '@shared/components/PageHeader';
import { Defs, Panel } from '@shared/components/Detail';
import { Field } from '@shared/components/FormPage';
import type { FormField } from '@shared/components/FormPage';
import { ErrorState, Forbidden } from '@shared/components/States';

type Mode = 'new_client' | 'extra_app' | 'group_item';

const STEPS = [
  'Modalidad',
  'Aplicación y plan',
  'Cuenta, contrato y cliente',
  'Administrador tenant',
  'Revisión',
];

const MODES: { value: Mode; title: string; desc: string; payload: string }[] = [
  {
    value: 'new_client',
    title: 'Cliente individual nuevo',
    desc: 'Plan, datos de cliente y cuenta, y credenciales del administrador. Commander crea cuenta, cliente, contrato e instancia.',
    payload: 'POST /provisioning',
  },
  {
    value: 'extra_app',
    title: 'Aplicación adicional',
    desc: 'Un cliente que ya existe estrena otra aplicación con su propio plan y credenciales.',
    payload: 'POST /provisioning · client_id',
  },
  {
    value: 'group_item',
    title: 'Cabecera de grupo',
    desc: 'Un contrato negociado que ya existe recibe un cliente nuevo de la misma cuenta.',
    payload: 'POST /provisioning · subscription_id + client.account_id',
  },
];

const PAYMENT_TERMS = ['15 días', '30 días', '45 días', '60 días', 'Al contado'].map((value) => ({
  value,
  label: value,
}));

/** Tres campos por fila, el reparto que `formColumns` da a una sección de tres o seis. */
const THREE_COLS = { '--cmd-form-cols': 3 } as CSSProperties;

const FULL_SPAN = { gridColumn: '1 / -1' } as CSSProperties;

/** Un 422 del servidor devuelve al paso donde se escribió el campo, no al principio. */
function stepFor(key: string): number {
  if (key.startsWith('admin')) return 4;
  if (key.startsWith('client') || key.startsWith('account') || key === 'subscription_id') return 3;
  return 2;
}

function slugify(value: string): string {
  return value
    .toLowerCase()
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/(^-|-$)/g, '');
}

/**
 * Dato que el asistente enseña sin dejar editar: la cuenta que hereda del cliente, la
 * capacidad del contrato, los límites del plan. Un `Defs` de un solo elemento cae en su
 * columna de la rejilla como un campo más, sin fingir un input que nadie puede escribir.
 */
function Derived({ label, value, hint }: { label: string; value: string; hint: string }) {
  return <Defs items={[{ label, value, hint }]} />;
}

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
        api.list('instances'),
      ]),
    [],
  );
  const [applications, plans, accounts, clients, subscriptions, items, instances] = pending(
    res.data,
    [[], [], [], [], [], [], []],
  );

  // Las fichas enlazan aquí con la modalidad ya decidida: desde un cliente se estrena
  // aplicación, desde un contrato negociado se añade cabecera. Eso salta el paso 1.
  const preClient = params.get('client') ?? '';
  const preSubscription = params.get('subscription') ?? '';
  const preAccount = params.get('account') ?? '';

  const [step, setStep] = useState(preClient || preSubscription ? 2 : 1);
  // El cliente manda sobre el contrato: llegar con los dos parámetros es estrenar
  // aplicación para ese cliente, no colgar una cabecera del grupo.
  const [mode, setMode] = useState<Mode | ''>(
    preClient ? 'extra_app' : preSubscription ? 'group_item' : '',
  );
  const [applicationId, setApplicationId] = useState('');
  const [planId, setPlanId] = useState('');
  const [clientId, setClientId] = useState(preClient);
  const [subscriptionId, setSubscriptionId] = useState(preSubscription);
  const [accountMode, setAccountMode] = useState(preAccount ? 'existing' : 'new');
  const [accountId, setAccountId] = useState(preAccount);
  const [account, setAccount] = useState({
    name: '',
    email: '',
    billing_email: '',
    tax_id: '',
    payment_terms: '30 días',
    address: '',
  });
  const [client, setClient] = useState({ name: '', slug: '', email: '' });
  const [slugTouched, setSlugTouched] = useState(false);
  const [admin, setAdmin] = useState({ name: '', email: '', password: '' });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [global, setGlobal] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  if (!can('provision instances')) return <Forbidden perm="provision instances" />;
  if (res.error) return <ErrorState error={res.error} onRetry={res.reload} />;
  if (res.loading) return <SkeletonText paragraph lineCount={8} />;

  const subscription = subscriptions.find((s) => String(s.id) === subscriptionId);
  // En una cabecera de grupo la aplicación no se elige: la impone el contrato negociado.
  const fixedApp = mode === 'group_item' && subscription !== undefined;
  const appId = fixedApp ? String(subscription.application_id) : applicationId;

  const application = applications.find((a) => String(a.id) === appId);
  const appPlans = plans.filter((p) => String(p.application_id) === appId && p.status === 'active');
  const plan = plans.find((p) => String(p.id) === planId);
  const selectedClient = clients.find((c) => String(c.id) === clientId);
  // El subdominio de la revisión: el del cliente elegido, o el que se está tecleando.
  const slug = (mode === 'extra_app' ? selectedClient?.slug : client.slug) ?? '';
  const active = (subId: number) =>
    items.filter((it) => it.subscription_id === subId && it.status === 'active').length;

  const clearError = (key: string) =>
    setErrors((prev) => {
      if (!prev[key]) return prev;
      const next = { ...prev };
      delete next[key];
      return next;
    });

  // Todo campo del asistente pinta su propio error y lo retira al escribir; la validación
  // completa vuelve a correr al continuar.
  const field = (def: FormField, value: string, onChange: (next: string) => void) => (
    <Field
      key={def.key}
      field={def}
      value={value}
      error={errors[def.key]}
      onChange={(next) => {
        clearError(def.key);
        onChange(String(next));
      }}
    />
  );

  const clientSection = (legend: string) => (
    <fieldset className="cmd-form__section">
      <legend className="cmd-form__legend">{legend}</legend>
      <div className="cmd-form__grid" style={THREE_COLS}>
        {field({ key: 'client.name', label: 'Nombre del cliente' }, client.name, (next) =>
          setClient({
            ...client,
            name: next,
            slug: slugTouched ? client.slug : slugify(next),
          }),
        )}
        {field(
          {
            key: 'client.slug',
            label: 'Slug / subdominio',
            helper: 'Minúsculas, números y guiones. Debe ser único: un duplicado se rechaza con 422.',
          },
          client.slug,
          (next) => {
            setSlugTouched(true);
            setClient({ ...client, slug: next });
          },
        )}
        {field(
          { key: 'client.email', label: 'Correo de contacto', type: 'email' },
          client.email,
          (next) => setClient({ ...client, email: next }),
        )}
      </div>
    </fieldset>
  );

  function advance() {
    const found: Record<string, string> = {};
    if (step === 2) {
      if (!appId) found.application_id = 'Elige la aplicación.';
      if (!planId) found.plan_id = 'Elige el plan.';
    }
    if (step === 3) {
      if (mode === 'extra_app' && !clientId) found.client_id = 'Elige el cliente existente.';
      if (mode === 'group_item' && !subscriptionId) {
        found.subscription_id = 'Elige el contrato negociado.';
      }
      if (mode === 'new_client') {
        if (accountMode === 'existing' && !accountId) found.account_id = 'Elige la cuenta.';
        if (accountMode === 'new' && !account.name) {
          found['account.name'] = 'Nombre de la cuenta obligatorio.';
        }
      }
      if (mode !== 'extra_app') {
        if (!client.name) found['client.name'] = 'Nombre obligatorio.';
        if (!client.slug) found['client.slug'] = 'Slug obligatorio.';
      }
    }
    if (step === 4) {
      if (!admin.name) found['admin.name'] = 'Nombre obligatorio.';
      if (!admin.email) found['admin.email'] = 'Correo obligatorio.';
      if (admin.password.length < 12) found['admin.password'] = 'Al menos doce caracteres.';
      else if (!/[a-zA-Z]/.test(admin.password) || !/[0-9]/.test(admin.password)) {
        found['admin.password'] = 'Debe combinar letras y números.';
      }
    }
    if (Object.keys(found).length) {
      setErrors(found);
      setGlobal('Revisa los campos marcados antes de continuar.');
      return;
    }
    setErrors({});
    setGlobal(null);
    setStep(step + 1);
  }

  async function submit() {
    if (busy || !mode) return;
    setBusy(true);
    setErrors({});
    setGlobal(null);
    try {
      const result = await api.provision({
        mode,
        application_id: appId,
        plan_id: planId,
        account_id: mode === 'new_client' && accountMode === 'existing' ? accountId : null,
        account,
        client,
        client_id: clientId,
        subscription_id: subscriptionId,
        admin,
      });
      // 202: el trabajo queda encolado, así que se sigue desde la pestaña de operación.
      navigate(`/instances/${result.instance.id}/operation`);
    } catch (e) {
      const err = e as ApiError;
      const keys = Object.keys(err.fields ?? {});
      setErrors(err.fields ?? {});
      setGlobal(err.message);
      if (keys.length) setStep(Math.min(...keys.map(stepFor)));
      setBusy(false);
    }
  }

  return (
    <>
      <PageHeader
        kicker={`paso ${step} de 5`}
        title="Nueva alta"
        lede="El alta es asíncrona: la respuesta 202 significa trabajo encolado, no instancia lista."
        crumbs={[{ label: 'Instancias', to: '/instances' }]}
      />

      <div className="cmd-form">
        <ProgressIndicator currentIndex={step - 1} spaceEqually>
          {STEPS.map((label) => (
            <ProgressStep key={label} label={label} />
          ))}
        </ProgressIndicator>

        {global && (
          <InlineNotification
            kind="error"
            lowContrast
            hideCloseButton
            title="No se pudo enviar el alta"
            subtitle={global}
          />
        )}

        {step === 1 && (
          <>
            <TileGroup
              name="mode"
              legend="Modalidad"
              valueSelected={mode}
              onChange={(value) => {
                setMode(value as Mode);
                setStep(2);
              }}
            >
              {MODES.map((m) => (
                <RadioTile key={m.value} id={`mode-${m.value}`} value={m.value}>
                  <strong>{m.title}</strong>
                  <p className="cmd-page__lede">{m.desc}</p>
                  <p className="cmd-mono cmd-cell__sub">{m.payload}</p>
                </RadioTile>
              ))}
            </TileGroup>
            <p className="cmd-page__lede">
              Un grupo nuevo se prepara creando primero la cuenta y el contrato negociado. El
              asistente enlaza esas pantallas y permite volver: no finge una transacción
              distribuida que la API no ofrece.
            </p>
          </>
        )}

        {step === 2 && (
          <>
            <fieldset className="cmd-form__section">
              <legend className="cmd-form__legend">Aplicación y plan</legend>
              <p className="cmd-page__lede">
                {fixedApp
                  ? 'La aplicación viene fijada por el contrato negociado elegido.'
                  : 'El plan determina los límites que se materializan al aprovisionar.'}
              </p>
              <div className="cmd-form__grid">
                {field(
                  {
                    key: 'application_id',
                    label: 'Aplicación',
                    type: 'select',
                    readOnly: fixedApp,
                    options: [
                      { value: '', label: 'Elige una aplicación' },
                      ...applications
                        .filter((a) => a.status === 'active')
                        .map((a) => ({
                          value: String(a.id),
                          label: `${a.name} · ${a.package_name}`,
                        })),
                    ],
                  },
                  appId,
                  (nextApp) => {
                    setApplicationId(nextApp);
                    // Otra aplicación son otros planes: el elegido ya no pertenece a ella.
                    setPlanId('');
                  },
                )}
                {field(
                  {
                    key: 'plan_id',
                    label: 'Plan',
                    type: 'select',
                    options: [
                      {
                        value: '',
                        label: appPlans.length ? 'Elige un plan' : 'Elige primero una aplicación',
                      },
                      ...appPlans.map((p) => ({
                        value: String(p.id),
                        label: `${p.name} · ${fmtMoney(p.monthly_price, p.currency)}`,
                      })),
                    ],
                  },
                  planId,
                  setPlanId,
                )}
              </div>
            </fieldset>

            {plan && (
              <fieldset className="cmd-form__section">
                <legend className="cmd-form__legend">Límites que se materializarán</legend>
                <p className="cmd-page__lede">
                  Copia de la plantilla en el momento del alta. Cambiar el plan más tarde vuelve a
                  materializarlos.
                </p>
                <Defs
                  items={plan.limits.map((limit) => ({
                    label: METRICS[limit.metric_key]?.name ?? limit.metric_key,
                    value:
                      limit.limit_value === null
                        ? 'Sin límite'
                        : `${fmtNum(limit.limit_value)} ${METRICS[limit.metric_key]?.unit ?? ''}`,
                    hint:
                      limit.limit_value === null
                        ? 'medir sin limitar'
                        : `aviso al ${limit.warning_percent} % · acción ${QUOTA_ACTION[limit.action].label.toLowerCase()}`,
                  }))}
                />
              </fieldset>
            )}
          </>
        )}

        {step === 3 && mode === 'extra_app' && (
          <fieldset className="cmd-form__section">
            <legend className="cmd-form__legend">Cliente existente</legend>
            <p className="cmd-page__lede">
              Se envía client_id: no se crean cuenta ni cliente nuevos.
            </p>
            <div className="cmd-form__grid" style={THREE_COLS}>
              {field(
                {
                  key: 'client_id',
                  label: 'Cliente',
                  type: 'select',
                  options: [
                    { value: '', label: 'Elige un cliente' },
                    ...clients
                      .filter((c) => c.status === 'active')
                      .slice()
                      .sort((a, b) => a.name.localeCompare(b.name))
                      .map((c) => ({ value: String(c.id), label: `${c.name} · ${c.slug}` })),
                  ],
                },
                clientId,
                setClientId,
              )}
              <Derived
                label="Cuenta pagadora"
                value={
                  accounts.find((a) => a.id === selectedClient?.account_id)?.name ?? '—'
                }
                hint="se deriva del cliente"
              />
              <Derived
                label="Aplicaciones que ya ejecuta"
                value={
                  selectedClient
                    ? instances
                        .filter((i) => i.client_id === selectedClient.id)
                        .map(
                          (i) =>
                            applications.find((a) => a.id === i.application_id)?.name ?? '',
                        )
                        .join(', ') || 'ninguna'
                    : '—'
                }
                hint="un duplicado se rechaza con 409"
              />
            </div>
          </fieldset>
        )}

        {step === 3 && mode === 'group_item' && (
          <>
            <fieldset className="cmd-form__section">
              <legend className="cmd-form__legend">Contrato negociado</legend>
              <p className="cmd-page__lede">
                Se envían subscription_id y client.account_id. Si la capacidad está agotada, el
                servidor responde 422.
              </p>
              <div className="cmd-form__grid" style={THREE_COLS}>
                {field(
                  {
                    key: 'subscription_id',
                    label: 'Contrato',
                    type: 'select',
                    options: [
                      { value: '', label: 'Elige un contrato' },
                      ...subscriptions
                        .filter(
                          (s) =>
                            s.mode === 'negotiated' &&
                            (s.status === 'active' || s.status === 'trialing'),
                        )
                        .map((s) => ({
                          value: String(s.id),
                          label: `${s.name} · ${active(s.id)}/${s.max_instances}`,
                        })),
                    ],
                  },
                  subscriptionId,
                  (nextSub) => {
                    setSubscriptionId(nextSub);
                    // El contrato fija la aplicación, y con ella cambian los planes válidos.
                    setPlanId('');
                  },
                )}
                <Derived
                  label="Capacidad"
                  value={
                    subscription
                      ? `${active(subscription.id)} de ${subscription.max_instances} cabeceras activas`
                      : '—'
                  }
                  hint={
                    subscription && active(subscription.id) >= subscription.max_instances
                      ? 'agotada: el alta se rechazará'
                      : 'queda hueco'
                  }
                />
                <Derived
                  label="Cuenta del grupo"
                  value={accounts.find((a) => a.id === subscription?.account_id)?.name ?? '—'}
                  hint="el cliente nuevo pertenece a esta cuenta"
                />
              </div>
              {can('create subscriptions') && (
                <div>
                  <Button
                    kind="tertiary"
                    size="sm"
                    onClick={() => navigate('/subscriptions/new')}
                  >
                    Crear antes un contrato negociado
                  </Button>
                </div>
              )}
            </fieldset>
            {clientSection('Cliente nuevo de la misma cuenta')}
          </>
        )}

        {step === 3 && mode === 'new_client' && (
          <>
            <fieldset className="cmd-form__section">
              <legend className="cmd-form__legend">Cuenta pagadora</legend>
              <p className="cmd-page__lede">
                Commander crea cuenta, cliente, contrato de catálogo e instancia con un solo POST.
              </p>
              <div
                className="cmd-form__grid"
                style={accountMode === 'existing' ? undefined : THREE_COLS}
              >
                <div style={FULL_SPAN}>
                  {field(
                    {
                      key: 'account_mode',
                      label: '¿La cuenta ya existe?',
                      type: 'select',
                      options: [
                        { value: 'new', label: 'No: crear cuenta nueva' },
                        { value: 'existing', label: 'Sí: usar una existente' },
                      ],
                    },
                    accountMode,
                    setAccountMode,
                  )}
                </div>
                {accountMode === 'existing' ? (
                  <div style={FULL_SPAN}>
                    {field(
                      {
                        key: 'account_id',
                        label: 'Cuenta',
                        type: 'select',
                        options: [
                          { value: '', label: 'Elige una cuenta' },
                          ...accounts
                            .slice()
                            .sort((a, b) => a.name.localeCompare(b.name))
                            .map((a) => ({ value: String(a.id), label: a.name })),
                        ],
                      },
                      accountId,
                      setAccountId,
                    )}
                  </div>
                ) : (
                  <>
                    {field(
                      { key: 'account.name', label: 'Nombre de la cuenta' },
                      account.name,
                      (next) => setAccount({ ...account, name: next }),
                    )}
                    {field(
                      { key: 'account.email', label: 'Correo', type: 'email' },
                      account.email,
                      (next) => setAccount({ ...account, email: next }),
                    )}
                    {field(
                      { key: 'account.billing_email', label: 'Correo de facturación', type: 'email' },
                      account.billing_email,
                      (next) => setAccount({ ...account, billing_email: next }),
                    )}
                    {field({ key: 'account.tax_id', label: 'NIF' }, account.tax_id, (next) =>
                      setAccount({ ...account, tax_id: next }),
                    )}
                    {field(
                      {
                        key: 'account.payment_terms',
                        label: 'Condiciones de pago',
                        type: 'select',
                        options: PAYMENT_TERMS,
                      },
                      account.payment_terms,
                      (next) => setAccount({ ...account, payment_terms: next }),
                    )}
                    {field({ key: 'account.address', label: 'Dirección' }, account.address, (next) =>
                      setAccount({ ...account, address: next }),
                    )}
                  </>
                )}
              </div>
            </fieldset>
            {clientSection('Cliente')}
          </>
        )}

        {step === 4 && (
          <fieldset className="cmd-form__section">
            <legend className="cmd-form__legend">Administrador tenant</legend>
            <p className="cmd-page__lede">
              Commander solo conserva este administrador inicial. La contraseña se envía una vez y
              no se recupera.
            </p>
            <div className="cmd-form__grid" style={THREE_COLS}>
              {field({ key: 'admin.name', label: 'Nombre' }, admin.name, (next) =>
                setAdmin({ ...admin, name: next }),
              )}
              {field({ key: 'admin.email', label: 'Correo', type: 'email' }, admin.email, (next) =>
                setAdmin({ ...admin, email: next }),
              )}
              {field(
                {
                  key: 'admin.password',
                  label: 'Contraseña',
                  type: 'password',
                  helper: 'Al menos doce caracteres, con letras y números.',
                },
                admin.password,
                (next) => setAdmin({ ...admin, password: next }),
              )}
            </div>
          </fieldset>
        )}

        {step === 5 && (
          <>
            <Panel>
              <Defs
                items={[
                  {
                    label: 'Modalidad',
                    value: MODES.find((m) => m.value === mode)?.title ?? '—',
                  },
                  {
                    label: 'Aplicación',
                    value: application?.name ?? '—',
                    hint: application?.package_name,
                  },
                  {
                    label: 'Plan',
                    value: plan?.name ?? '—',
                    hint: plan ? `${fmtMoney(plan.monthly_price, plan.currency)} al mes` : undefined,
                  },
                  {
                    label: 'Cuenta',
                    value:
                      (mode === 'extra_app'
                        ? accounts.find((a) => a.id === selectedClient?.account_id)?.name
                        : mode === 'group_item'
                          ? accounts.find((a) => a.id === subscription?.account_id)?.name
                          : accountMode === 'existing'
                            ? accounts.find((a) => String(a.id) === accountId)?.name
                            : account.name) || '—',
                  },
                  {
                    label: 'Cliente',
                    value: (mode === 'extra_app' ? selectedClient?.name : client.name) || '—',
                    hint: slug ? `${slug}.commander.app` : undefined,
                  },
                  {
                    label: 'Contrato',
                    value:
                      mode === 'group_item'
                        ? (subscription?.name ?? '—')
                        : 'Se creará de catálogo con el precio del plan',
                  },
                  { label: 'Administrador', value: admin.email || '—', hint: admin.name },
                  {
                    label: 'Contraseña',
                    value: admin.password ? '············' : '—',
                    hint: 'se envía una vez, no se recupera',
                  },
                  {
                    label: 'Base derivada',
                    value: slug ? (
                      <span className="cmd-mono">
                        {`kk_${slug.replace(/-/g, '_')}_${application?.slug.slice(0, 6) ?? ''}`}
                      </span>
                    ) : (
                      '—'
                    ),
                    hint: 'información, no campo editable',
                  },
                ]}
              />
            </Panel>
            <Callout
              kind="info"
              lowContrast
              title="Un solo POST /provisioning"
              subtitle="La respuesta es 202: Commander encola el trabajo y el SPA abre la ficha para seguir su estado. La contraseña se envía una vez y no se puede recuperar."
            />
          </>
        )}

        <div className="cmd-form__foot">
          {step > 1 && (
            <Button kind="secondary" onClick={() => setStep(step - 1)}>
              Atrás
            </Button>
          )}
          {step > 1 && step < 5 && <Button onClick={advance}>Continuar</Button>}
          {step === 5 && (
            <Button onClick={submit} disabled={busy}>
              {busy ? 'Enviando…' : 'Enviar POST /provisioning'}
            </Button>
          )}
          <Button kind="ghost" onClick={() => navigate('/instances')}>
            Salir del asistente
          </Button>
        </div>
      </div>
    </>
  );
}
