import { useMemo, useState } from 'react';
import { Button, Callout, InlineNotification, SkeletonText } from '@carbon/react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import {
  METRICS,
  PROVISIONING,
  QUOTA_ACTION,
  SERVICE,
  fmtDateTime,
  fmtMoney,
  fmtNum,
  fmtRel,
} from '@services/api/mock/commander-mock';
import { useApp } from '@app/provider';
import { useResource } from '@shared/hooks/useResource';
import { lut, pending } from '@shared/lib/data';
import { PageHeader } from '@shared/components/PageHeader';
import { Defs, DetailTabs, Panel } from '@shared/components/Detail';
import { DataView } from '@shared/components/DataView';
import { StatusTag } from '@shared/components/StatusTag';
import { Meter } from '@shared/components/Meter';
import { ActionModal } from '@shared/components/ActionModal';
import type { ModalField } from '@shared/components/ActionModal';
import { ErrorState, Forbidden } from '@shared/components/States';
import { quotaPercent } from '@features/quotas/model/quotaHealth';
import { statusTone } from '@features/logs/model/httpStatus';
import { InstanceUsagePanel } from '../components/InstanceUsagePanel';
import { availableActions, isInFlight } from '../model/instanceActions';
import type { InstanceAction } from '../model/instanceActions';
import { useInstancePolling } from '../hooks/useInstancePolling';
import { useInstancesService } from '../services/instancesService';

const TABS = [
  { slug: 'summary', label: 'Resumen' },
  { slug: 'operation', label: 'Operación' },
  { slug: 'usage', label: 'Uso' },
  { slug: 'quotas', label: 'Cuotas' },
  { slug: 'logs', label: 'Logs' },
  { slug: 'activity', label: 'Actividad' },
  { slug: 'domains', label: 'Dominios' },
];

export function InstanceDetailPage() {
  const { can } = useApp();
  const instancesService = useInstancesService();
  const { id = '', tab = 'summary' } = useParams();
  const navigate = useNavigate();
  const [dialog, setDialog] = useState<InstanceAction | null>(null);
  const [flash, setFlash] = useState<{ title: string; body: string } | null>(null);

  const core = useResource(() => instancesService.getWithContext(id), [id]);
  const [instance, clients, accounts, applications, plans, subscriptions, quotas, activity] = pending(core.data, [
    null, [], [], [], [], [], [], [],
  ]);

  const inFlight = instance ? isInFlight(instance) : false;
  const pollExpired = useInstancePolling(inFlight, core.reload, id);

  const instanceQuotas = useMemo(
    () => quotas.filter((q) => q.scope === 'instance' && q.instance_id === Number(id)),
    [quotas, id],
  );
  const instanceActivity = useMemo(() => activity.filter((a) => a.instance_id === Number(id)), [activity, id]);

  async function runAction(action: InstanceAction, data: Record<string, unknown>) {
    const updated = await instancesService.run(id, action, data);
    core.reload();
    setFlash({ title: 'Acción aceptada', body: updated.status_message ?? 'El servidor confirmó el cambio.' });
  }

  if (!can('show instances')) return <Forbidden perm="show instances" />;
  if (core.error) {
    return (
      <>
        <PageHeader title="Instancia" crumbs={[{ label: 'Instancias', to: '/instances' }]} />
        <ErrorState error={core.error} onRetry={core.reload} />
      </>
    );
  }
  if (core.loading || !instance) return <SkeletonText paragraph lineCount={8} />;

  const client = lut(clients).get(instance.client_id);
  const account = lut(accounts).get(instance.account_id);
  const application = lut(applications).get(instance.application_id);
  const plan = instance.plan_id ? lut(plans).get(instance.plan_id) : undefined;
  const subscription = instance.subscription_id ? lut(subscriptions).get(instance.subscription_id) : undefined;
  const allowed = availableActions(instance);
  const offers = (action: InstanceAction) => allowed.includes(action);

  return (
    <>
      <PageHeader
        title={`${client?.name ?? 'Instancia'} · ${application?.name ?? ''}`}
        crumbs={[{ label: 'Instancias', to: '/instances' }, { label: `Instancia ${instance.id}` }]}
        lede={
          <>
            <StatusTag catalog={PROVISIONING} value={instance.provisioning_status} />{' '}
            <StatusTag catalog={SERVICE} value={instance.service_status} />{' '}
            <span className="cmd-mono">#{instance.id}</span>
          </>
        }
        actions={
          <>
            {can('control instances') && offers('grace') && (
              <Button kind="tertiary" size="md" onClick={() => setDialog('grace')}>
                Gracia
              </Button>
            )}
            {can('control instances') && offers('suspend') && (
              <Button kind="tertiary" size="md" onClick={() => setDialog('suspend')}>
                Suspender
              </Button>
            )}
            {can('control instances') && offers('resume') && (
              <Button kind="tertiary" size="md" onClick={() => setDialog('resume')}>
                Reanudar
              </Button>
            )}
            {can('control instances') && offers('change-plan') && (
              <Button kind="tertiary" size="md" onClick={() => setDialog('change-plan')}>
                Cambiar plan
              </Button>
            )}
            {can('provision instances') && offers('retry') && (
              <Button kind="tertiary" size="md" onClick={() => setDialog('retry')}>
                Reintentar
              </Button>
            )}
            {can('control instances') && offers('deprovision') && (
              <Button kind="danger--tertiary" size="md" onClick={() => setDialog('deprovision')}>
                Dar de baja
              </Button>
            )}
          </>
        }
      />

      {flash && (
        <InlineNotification
          kind="success"
          lowContrast
          title={flash.title}
          subtitle={flash.body}
          onClose={() => setFlash(null)}
        />
      )}

      {inFlight && !pollExpired && (
        <Callout
          kind="info"
          lowContrast
          title="Trabajo en curso"
          subtitle="Se consulta el estado cada cinco segundos hasta que el servidor confirme el resultado."
        />
      )}
      {pollExpired && (
        <Callout
          kind="warning"
          lowContrast
          title="Han pasado diez minutos"
          subtitle="Se dejó de consultar automáticamente. El trabajo continúa en el servidor; refresca para ver su estado."
        />
      )}

      <DetailTabs base={`/instances/${instance.id}`} tabs={TABS} current={tab}>
        <div className="cmd-stack">
          {tab === 'summary' && (
            <div className="cmd-panels">
              <Panel title="Identidad">
                <Defs
                  items={[
                    { label: 'ID', value: <span className="cmd-mono">{instance.id}</span> },
                    { label: 'Cliente', value: client ? <Link to={`/clients/${client.id}`}>{client.name}</Link> : '—' },
                    { label: 'Cuenta', value: account ? <Link to={`/accounts/${account.id}`}>{account.name}</Link> : '—' },
                    {
                      label: 'Aplicación',
                      value: application ? <Link to={`/applications/${application.id}`}>{application.name}</Link> : '—',
                    },
                    { label: 'Plan operativo', value: plan?.name ?? 'Sin plan' },
                    {
                      label: 'Contrato',
                      value: subscription ? (
                        <Link to={`/subscriptions/${subscription.id}`}>{subscription.name}</Link>
                      ) : (
                        '—'
                      ),
                    },
                  ]}
                />
              </Panel>

              <Panel title="Base tenant">
                <Defs
                  items={[
                    { label: 'Base de datos', value: <span className="cmd-mono">{instance.database_name}</span> },
                    { label: 'Usuario runtime', value: <span className="cmd-mono">{instance.database_user}</span> },
                    { label: 'Contraseña', value: <span className="cmd-meter__caption">Nunca se muestra ni se recupera.</span> },
                  ]}
                />
              </Panel>

              <Panel title="Fechas">
                <Defs
                  items={[
                    { label: 'Creada', value: `${fmtDateTime(instance.created_at)} (${fmtRel(instance.created_at)})` },
                    {
                      label: 'Aprovisionada',
                      value: instance.provisioned_at ? fmtDateTime(instance.provisioned_at) : 'Todavía no',
                    },
                    { label: 'Actualizada', value: fmtDateTime(instance.updated_at) },
                    {
                      label: 'Última actividad',
                      value: instance.last_activity_at ? fmtDateTime(instance.last_activity_at) : 'Sin actividad',
                    },
                    { label: 'Requests 24 h', value: fmtNum(instance.requests_24h) },
                  ]}
                />
              </Panel>
            </div>
          )}

          {tab === 'operation' && (
            <div className="cmd-panels">
              <Panel title="Estado">
                <Defs
                  items={[
                    { label: 'Provisioning', value: <StatusTag catalog={PROVISIONING} value={instance.provisioning_status} /> },
                    { label: 'Servicio', value: <StatusTag catalog={SERVICE} value={instance.service_status} /> },
                    {
                      label: 'Razón',
                      value: instance.status_reason ? <span className="cmd-mono">{instance.status_reason}</span> : '—',
                    },
                    { label: 'Mensaje', value: instance.status_message ?? '—' },
                    {
                      label: 'Gracia hasta',
                      value: instance.grace_until
                        ? `${fmtDateTime(instance.grace_until)} (${fmtRel(instance.grace_until)})`
                        : 'Sin gracia abierta',
                    },
                  ]}
                />
              </Panel>

              {instance.last_error && (
                <div style={{ gridColumn: '1 / -1' }}>
                  <Panel title="Último error">
                    <p className="cmd-mono">{instance.last_error}</p>
                    <p className="cmd-meter__caption">
                      Retry reutiliza este mismo ID de instancia; nunca se crea otra para tapar un fallo.
                    </p>
                  </Panel>
                </div>
              )}
            </div>
          )}

          {tab === 'usage' && <InstanceUsagePanel instanceId={id} />}

          {tab === 'quotas' && (
            <DataView
              label="Cuotas de la instancia"
              rows={instanceQuotas}
              columns={[
                {
                  key: 'metric',
                  header: 'Métrica',
                  value: (q) => METRICS[q.metric_key]?.name ?? q.metric_key,
                  render: (q) => <Link to={`/quotas/${q.id}`}>{METRICS[q.metric_key]?.name ?? q.metric_key}</Link>,
                },
                {
                  key: 'current',
                  header: 'Consumo',
                  value: (q) => quotaPercent(q),
                  render: (q) => <Meter value={q.current_value} limit={q.limit_value} />,
                },
                {
                  key: 'limit',
                  header: 'Límite',
                  align: 'right',
                  value: (q) => q.limit_value,
                  render: (q) => (q.limit_value === null ? 'Sin límite' : fmtNum(q.limit_value)),
                },
                { key: 'action', header: 'Enforcement', value: (q) => q.action, render: (q) => <StatusTag catalog={QUOTA_ACTION} value={q.action} /> },
                {
                  key: 'consecutive',
                  header: 'Periodos',
                  align: 'right',
                  value: (q) => q.consecutive_count,
                  render: (q) => `${q.consecutive_count} / ${q.consecutive_periods ?? '—'}`,
                },
                { key: 'measured_at', header: 'Medición', value: (q) => q.measured_at, render: (q) => fmtRel(q.measured_at) },
              ]}
              emptyTitle="Sin cuotas materializadas"
              emptyBody="Las cuotas nacen al aprovisionar o al cambiar de plan. Una instancia que nunca llegó a ready no tiene ninguna."
            />
          )}

          {tab === 'logs' && <InstanceLogsPanel instanceId={id} />}

          {tab === 'activity' && (
            <DataView
              label="Actividad de la instancia"
              rows={instanceActivity}
              columns={[
                { key: 'created_at', header: 'Fecha', value: (a) => a.created_at, render: (a) => fmtDateTime(a.created_at) },
                { key: 'label', header: 'Tipo', value: (a) => a.label, render: (a) => <Link to={`/activity/${a.id}`}>{a.label}</Link> },
                { key: 'reason', header: 'Razón', value: (a) => a.reason, render: (a) => <span className="cmd-mono">{a.reason}</span> },
                { key: 'message', header: 'Mensaje', value: (a) => a.message },
              ]}
              defaultSort={{ key: 'created_at', dir: 'desc' }}
              emptyTitle="Sin eventos"
              emptyBody="Esta instancia todavía no ha generado auditoría operativa."
            />
          )}

          {tab === 'domains' && (
            <Panel title="Dominios propios">
              <Defs items={[{ label: 'Subdominio técnico', value: <span className="cmd-mono">{client?.slug ?? '—'}</span> }]} />
              <p className="cmd-meter__caption">
                Dominios propios todavía no disponibles. Mientras no exista API no se muestran controles que no harían
                nada.
              </p>
            </Panel>
          )}
        </div>
      </DetailTabs>

      <ActionModal
        open={dialog === 'grace'}
        kicker="Instancia lista"
        title="Abrir periodo de gracia"
        body="La instancia sigue sirviendo tráfico hasta que venza la gracia. Es una decisión operativa: queda registrada con su razón."
        submitLabel="Abrir gracia"
        fields={[
          { key: 'hours', label: 'Horas de gracia', type: 'number', helper: 'Entre 1 y 720 horas.' },
          { key: 'reason', label: 'Razón operativa', placeholder: 'quota_exceeded, invoice_overdue…' },
          { key: 'message', label: 'Mensaje para la ficha', type: 'textarea' },
        ]}
        initial={{ hours: 48, reason: '', message: '' }}
        onClose={() => setDialog(null)}
        onSubmit={(data) => runAction('grace', data)}
      />

      <ActionModal
        open={dialog === 'suspend'}
        kicker="Instancia lista"
        title="Suspender el servicio"
        body="La instancia dejará de servir tráfico y responderá 402 hasta que se reanude."
        danger
        submitLabel="Suspender"
        fields={[
          { key: 'reason', label: 'Razón operativa', placeholder: 'invoice_overdue, abuse…' },
          { key: 'message', label: 'Mensaje para la ficha', type: 'textarea' },
        ]}
        initial={{ reason: '', message: '' }}
        onClose={() => setDialog(null)}
        onSubmit={(data) => runAction('suspend', data)}
      />

      <ActionModal
        open={dialog === 'resume'}
        title="Reanudar el servicio"
        body="La instancia vuelve a servir tráfico de inmediato."
        submitLabel="Reanudar"
        fields={[{ key: 'message', label: 'Mensaje para la ficha', type: 'textarea' }]}
        initial={{ message: '' }}
        onClose={() => setDialog(null)}
        onSubmit={(data) => runAction('resume', data)}
      />

      <ActionModal
        open={dialog === 'change-plan'}
        title="Cambiar el plan operativo"
        body="Las cuotas se rehacen desde la plantilla del plan destino. El precio pactado del contrato no se recalcula."
        submitLabel="Cambiar plan"
        fields={
          [
            {
              key: 'plan_id',
              label: 'Plan destino',
              type: 'select',
              options: plans
                .filter((p) => p.application_id === instance.application_id && p.status === 'active')
                .map((p) => ({ value: String(p.id), label: `${p.name} · ${fmtMoney(p.monthly_price, p.currency)}` })),
            },
          ] as ModalField[]
        }
        initial={{ plan_id: String(instance.plan_id ?? '') }}
        onClose={() => setDialog(null)}
        onSubmit={(data) => runAction('change-plan', data)}
      />

      <ActionModal
        open={dialog === 'retry'}
        kicker="Reintento"
        title="Reintentar el alta"
        body="Se reutiliza el mismo ID de instancia. Hace falta una contraseña nueva para el administrador tenant."
        submitLabel="Reintentar"
        fields={[
          {
            key: 'password',
            label: 'Contraseña del administrador',
            type: 'password',
            helper: 'Doce caracteres como mínimo, con letras y números. No se recupera después.',
          },
        ]}
        initial={{ password: '' }}
        onClose={() => setDialog(null)}
        onSubmit={(data) => runAction('retry', data)}
      />

      <ActionModal
        open={dialog === 'deprovision'}
        kicker="Acción destructiva"
        title="Dar de baja la instancia"
        body={`Se eliminan los recursos de la instancia. La ficha histórica se conserva. Escribe «${client?.slug ?? ''}» para confirmar.`}
        danger
        submitLabel="Dar de baja"
        fields={[
          { key: 'confirm', label: 'Slug del cliente', placeholder: client?.slug },
          { key: 'reason', label: 'Razón operativa', placeholder: 'contract_ended, operator_request…' },
        ]}
        initial={{ confirm: '', reason: 'operator_request' }}
        onClose={() => setDialog(null)}
        onSubmit={async (data) => {
          await runAction('deprovision', data);
          navigate(`/instances/${instance.id}/operation`);
        }}
      />
    </>
  );
}

function InstanceLogsPanel({ instanceId }: { instanceId: string }) {
  const instancesService = useInstancesService();
  const res = useResource(() => instancesService.logs(instanceId), [instanceId]);

  return (
    <DataView
      label="Logs de la instancia"
      rows={res.data}
      loading={res.loading}
      refreshing={res.refreshing}
      error={res.error}
      onRetry={res.reload}
      columns={[
        {
          key: 'created_at',
          header: 'Fecha',
          value: (l) => l.created_at,
          render: (l) => <span className="cmd-mono">{fmtDateTime(l.created_at)}</span>,
        },
        { key: 'method', header: 'Método', value: (l) => l.method, render: (l) => <span className="cmd-mono">{l.method}</span> },
        { key: 'path', header: 'Ruta', value: (l) => l.path, render: (l) => <span className="cmd-mono">{l.path}</span> },
        {
          key: 'status',
          header: 'Estado',
          align: 'right',
          value: (l) => l.status,
          render: (l) => {
            const tone = statusTone(l.status);
            return <span className={tone ? `cmd-text-${tone}` : undefined}>{l.status}</span>;
          },
        },
        {
          key: 'duration_ms',
          header: 'Duración',
          align: 'right',
          value: (l) => l.duration_ms,
          render: (l) => `${fmtNum(l.duration_ms)} ms`,
        },
        { key: 'actor', header: 'Actor', value: (l) => l.actor, render: (l) => <span className="cmd-mono">{l.actor}</span> },
        { key: 'ip', header: 'IP', value: (l) => l.ip, render: (l) => <span className="cmd-mono">{l.ip}</span> },
      ]}
      search={(l) => `${l.path} ${l.actor} ${l.ip} ${l.status}`}
      defaultSort={{ key: 'created_at', dir: 'desc' }}
      emptyTitle="Sin logs"
      emptyBody="No hay requests registrados para esta instancia dentro de la ventana de retención."
    />
  );
}
