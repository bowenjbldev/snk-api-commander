import { useMemo, useState } from 'react';
import {
  Button,
  Callout,
  InlineNotification,
  SkeletonText,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableHeader,
  TableRow,
} from '@carbon/react';
import { useParams } from 'react-router-dom';
import { Link } from '@shared/components/AppLink';
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
import type { Quota, QuotaAction } from '@services/api/types';
import { useApp } from '@app/provider';
import { useResource } from '@shared/hooks/useResource';
import { lut, pending } from '@shared/lib/data';
import { PageHeader } from '@shared/components/PageHeader';
import { Defs, DetailTabs } from '@shared/components/Detail';
import { ChartBlock } from '@shared/components/charts';
import { StatusTag } from '@shared/components/StatusTag';
import { Meter } from '@shared/components/Meter';
import { ActionModal } from '@shared/components/ActionModal';
import { EmptyState, ErrorState, Forbidden } from '@shared/components/States';
import { statusTone } from '@features/logs/model/httpStatus';
import { InstanceUsagePanel } from '../components/InstanceUsagePanel';
import { ACTION_LABEL, ACTION_PERM, actionDialog, availableActions, isInFlight } from '../model/instanceActions';
import type { InstanceAction } from '../model/instanceActions';
import { useInstancePolling } from '../hooks/useInstancePolling';
import { useInstancesService } from '../services/instancesService';

/** Las cifras se leen por la unidad, no por el primer dígito: van pegadas a la derecha. */
const RIGHT = { textAlign: 'right' } as const;

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
  const { can, store } = useApp();
  const instancesService = useInstancesService();
  const { id = '', tab = 'summary' } = useParams();
  const [dialog, setDialog] = useState<InstanceAction | null>(null);
  const [quotaDialog, setQuotaDialog] = useState<Quota | null>(null);
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
  const instanceActivity = useMemo(
    () => activity.filter((a) => a.instance_id === Number(id)).slice(0, 30),
    [activity, id],
  );

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
  const provisioning = PROVISIONING[instance.provisioning_status];
  const service = SERVICE[instance.service_status];
  const ready = instance.provisioning_status === 'ready';
  const retryable = ['failed', 'deprovisioned'].includes(instance.provisioning_status);
  const actions = availableActions(instance).filter((action) => can(ACTION_PERM[action]));

  const consultState = () => {
    core.reload();
    setFlash({
      title: 'Estado consultado',
      body: `GET /instances/${instance.id} respondió con el estado actual.`,
    });
  };

  const spec =
    dialog &&
    actionDialog(dialog, instance, {
      clientName: client?.name ?? '—',
      clientSlug: client?.slug ?? '',
      graceHours: store.settings.default_grace_hours,
      currentPlan: plan?.name ?? '—',
      planOptions: plans
        .filter((p) => p.application_id === instance.application_id && p.status === 'active' && p.id !== instance.plan_id)
        .map((p) => ({ value: String(p.id), label: `${p.name} · ${fmtMoney(p.monthly_price, p.currency)}` })),
    });

  return (
    <>
      <PageHeader
        kicker={`Instancia ${instance.id}`}
        title={`${client?.name ?? '—'} · ${application?.name ?? '—'}`}
        crumbs={[
          { label: 'Instancias', to: '/instances' },
          { label: client?.name ?? '—', to: `/clients/${instance.client_id}` },
        ]}
        lede={`${account?.name ?? '—'} · base ${instance.database_name}`}
        statuses={
          <>
            <StatusTag catalog={PROVISIONING} value={instance.provisioning_status} />
            <StatusTag catalog={SERVICE} value={instance.service_status} />
          </>
        }
        actions={
          <>
            {can('control instances') && ready && Boolean(instance.plan_id) && (
              <Button onClick={() => setDialog('change-plan')}>Cambiar plan</Button>
            )}
            {can('provision instances') && retryable && (
              <Button onClick={() => setDialog('retry')}>Reintentar alta</Button>
            )}
            {/* Consultar es releer la ficha entera: el estado real solo llega del servidor. */}
            <Button kind="tertiary" onClick={consultState}>
              Consultar estado
            </Button>
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

      <DetailTabs base={`/instances/${instance.id}`} tabs={TABS} current={tab}>
        <div className="cmd-stack">
          {/* Los avisos suben por encima de los bloques: acotan qué se está viendo antes de
              que el operador lea un estado que todavía puede cambiar. */}
          {inFlight && (
            <Callout
              kind="info"
              lowContrast
              title={`Trabajo asíncrono en curso: ${provisioning.label.toLowerCase()}`}
              subtitle={`El SPA consulta GET /instances/${instance.id} cada cinco segundos y no da por buena ninguna transición hasta recibirla del servidor.`}
            />
          )}
          {pollExpired && (
            <Callout
              kind="warning"
              lowContrast
              title="Se ha dejado de consultar"
              subtitle="Han pasado diez minutos: se ha dejado de consultar. El trabajo continúa en el servidor, puedes refrescar manualmente."
            />
          )}
          {tab === 'summary' && (
            <Callout
              kind="info"
              lowContrast
              title="Datos de negocio fuera de alcance"
              subtitle="Commander no lista usuarios, roles ni contenido del tenant: viven en la base aislada de esta instancia."
            />
          )}
          {tab === 'domains' && (
            <Callout
              kind="info"
              lowContrast
              title="Dominios propios todavía no disponibles"
              subtitle="No existe API. Esta pestaña no renderiza controles inertes ni inventa endpoints: cuando exista, separará propiedad, DNS, TLS y health check, y un hostname pendiente nunca aparecerá como activo."
            />
          )}

          <div className="cmd-panels">
            {tab === 'summary' && (
              <ChartBlock
                title="Identidad"
                help="Los datos fijos del registro. Lo que queda inmutable tras el alta se marca como tal; el resto se edita desde su formulario."
              >
                <Defs
                  items={[
                    { label: 'ID de instancia', value: <span className="cmd-mono">{instance.id}</span> },
                    {
                      label: 'Cliente',
                      value: <Link to={`/clients/${instance.client_id}`}>{client?.name ?? '—'}</Link>,
                      hint: `tenant · ${client?.slug ?? '—'}`,
                    },
                    {
                      label: 'Cuenta',
                      value: <Link to={`/accounts/${instance.account_id}`}>{account?.name ?? '—'}</Link>,
                      hint: 'pagador',
                    },
                    {
                      label: 'Aplicación',
                      value: (
                        <Link to={`/applications/${instance.application_id}`}>{application?.name ?? '—'}</Link>
                      ),
                      hint: application?.package_name,
                    },
                    {
                      label: 'Plan operativo',
                      // El plan solo es navegable si se puede editar: su ficha ES su formulario.
                      value:
                        plan && can('update plans') ? (
                          <Link to={`/plans/${plan.id}/edit`}>{plan.name}</Link>
                        ) : (
                          (plan?.name ?? 'Sin plan')
                        ),
                    },
                    {
                      label: 'Contrato',
                      value: subscription ? (
                        <Link to={`/subscriptions/${subscription.id}`}>{subscription.name}</Link>
                      ) : (
                        '—'
                      ),
                      hint: subscription && (subscription.mode === 'catalog' ? 'catálogo' : 'negociado'),
                    },
                    { label: 'Base tenant', value: <span className="cmd-mono">{instance.database_name}</span> },
                    {
                      label: 'Usuario runtime',
                      value: <span className="cmd-mono">{instance.database_user}</span>,
                      hint: 'la contraseña nunca se muestra',
                    },
                    {
                      label: 'Creada',
                      value: fmtDateTime(instance.created_at),
                      hint: fmtRel(instance.created_at),
                    },
                    {
                      label: 'Aprovisionada',
                      value: instance.provisioned_at ? fmtDateTime(instance.provisioned_at) : '—',
                    },
                    {
                      label: 'Actualizada',
                      value: fmtDateTime(instance.updated_at),
                      hint: fmtRel(instance.updated_at),
                    },
                    { label: 'Requests 24 h', value: fmtNum(instance.requests_24h) },
                  ]}
                />
              </ChartBlock>
            )}

            {tab === 'operation' && (
              <>
                <ChartBlock
                  title="Estado operativo"
                  help="Lo que dice el servidor ahora mismo: aprovisionamiento, servicio y la razón registrada en el último cambio."
                >
                  <Defs
                    items={[
                      {
                        label: 'Provisioning',
                        value: <StatusTag catalog={PROVISIONING} value={instance.provisioning_status} />,
                        hint: `severidad ${provisioning.sev}`,
                      },
                      {
                        label: 'Servicio',
                        value: <StatusTag catalog={SERVICE} value={instance.service_status} />,
                        hint: service.note,
                      },
                      {
                        label: 'Razón',
                        value: <span className="cmd-mono">{instance.status_reason ?? '—'}</span>,
                      },
                      { label: 'Mensaje', value: instance.status_message ?? '—' },
                      {
                        label: 'Gracia hasta',
                        value: instance.grace_until ? fmtDateTime(instance.grace_until) : '—',
                        hint: instance.grace_until ? fmtRel(instance.grace_until) : undefined,
                      },
                      { label: 'Última actualización', value: fmtDateTime(instance.updated_at) },
                    ]}
                  />
                </ChartBlock>

                {instance.last_error && (
                  <ChartBlock
                    title="Último error"
                    help="Se conserva tal cual lo devuelve el servidor. Retry reutiliza el mismo ID de instancia: no se crea otra para tapar el fallo."
                  >
                    <pre className="cmd-mono cmd-table-scroll">{instance.last_error}</pre>
                  </ChartBlock>
                )}

                <ChartBlock
                  title="Acciones"
                  help="Cada acción abre un diálogo con su razón y su mensaje, y espera la respuesta del servidor antes de dar nada por hecho."
                >
                  <div className="cmd-page__actions">
                    {actions.length > 0 ? (
                      actions.map((action) => (
                        <Button
                          key={action}
                          size="sm"
                          kind={action === 'deprovision' ? 'danger' : 'tertiary'}
                          onClick={() => setDialog(action)}
                        >
                          {ACTION_LABEL[action]}
                        </Button>
                      ))
                    ) : (
                      <Button size="sm" kind="ghost" disabled title="Tu rol no permite operar esta instancia">
                        Sin acciones disponibles
                      </Button>
                    )}
                    <p className="cmd-page__lede">
                      {can('control instances')
                        ? 'Cada acción envía su POST y espera la respuesta: la interfaz no simula el éxito antes de recibirlo.'
                        : 'Tu rol no tiene «control instances», así que la interfaz no ofrece estas acciones.'}
                    </p>
                  </div>
                </ChartBlock>
              </>
            )}

            {tab === 'usage' && <InstanceUsagePanel instanceId={id} />}

            {tab === 'quotas' &&
              (instanceQuotas.length > 0 ? (
                <ChartBlock
                  title="Cuotas propias"
                  help="Las cuotas de instancia nacen del plan al aprovisionar o al cambiar de plan. La API no permite borrarlas, solo editarlas."
                >
                  <TableContainer className="cmd-table-scroll">
                    <Table size="sm" aria-label="Cuotas propias">
                      <TableHead>
                        <TableRow>
                          <TableHeader>Métrica</TableHeader>
                          <TableHeader style={RIGHT}>Valor</TableHeader>
                          <TableHeader style={RIGHT}>Límite</TableHeader>
                          <TableHeader>Consumo</TableHeader>
                          <TableHeader style={RIGHT}>Aviso</TableHeader>
                          <TableHeader>Acción</TableHeader>
                          <TableHeader style={RIGHT}>Periodos</TableHeader>
                          <TableHeader>Última medición</TableHeader>
                          <TableHeader aria-label="Editar" style={RIGHT} />
                        </TableRow>
                      </TableHead>
                      <TableBody>
                        {instanceQuotas.map((quota) => {
                          const metric = METRICS[quota.metric_key];
                          return (
                            <TableRow key={quota.id}>
                              <TableCell>
                                <Link to={`/quotas/${quota.id}`}>{metric?.name ?? quota.metric_key}</Link>
                                <span className="cmd-cell__sub">{quota.metric_key}</span>
                              </TableCell>
                              <TableCell style={RIGHT}>
                                {fmtNum(quota.current_value)}
                                <span className="cmd-cell__sub">{metric?.unit}</span>
                              </TableCell>
                              <TableCell
                                className={quota.limit_value === null ? 'cmd-dim' : undefined}
                                style={RIGHT}
                              >
                                {quota.limit_value === null ? 'Sin límite' : fmtNum(quota.limit_value)}
                              </TableCell>
                              <TableCell>
                                {/* `pct` divide un valor nulo y devuelve cero: sin medición no
                                    hay barra que pintar, y un cero mentiría sobre el consumo. */}
                                {quota.current_value === null && quota.limit_value !== null ? (
                                  <span className="cmd-meter__caption">Sin medición</span>
                                ) : (
                                  <Meter value={quota.current_value} limit={quota.limit_value} />
                                )}
                              </TableCell>
                              <TableCell className="cmd-dim" style={RIGHT}>
                                {quota.warning_percent === null ? '—' : `${quota.warning_percent} %`}
                              </TableCell>
                              <TableCell>
                                <StatusTag catalog={QUOTA_ACTION} value={quota.action} />
                              </TableCell>
                              <TableCell className="cmd-dim" style={RIGHT}>
                                {quota.consecutive_count} / {quota.consecutive_periods ?? '—'}
                              </TableCell>
                              <TableCell className="cmd-dim">{fmtDateTime(quota.measured_at)}</TableCell>
                              <TableCell style={RIGHT}>
                                {can('update quotas') && (
                                  <Button kind="ghost" size="sm" onClick={() => setQuotaDialog(quota)}>
                                    Editar
                                  </Button>
                                )}
                              </TableCell>
                            </TableRow>
                          );
                        })}
                      </TableBody>
                    </Table>
                  </TableContainer>
                </ChartBlock>
              ) : (
                <EmptyBlock
                  head="Cuotas"
                  title="Sin cuotas materializadas"
                  body="Los límites se materializan al aprovisionar o al cambiar de plan. Una instancia dada de baja no conserva cuotas."
                />
              ))}

            {tab === 'logs' && <InstanceLogsPanel instanceId={id} />}

            {tab === 'activity' &&
              (instanceActivity.length > 0 ? (
                <ChartBlock
                  title="Eventos de la instancia"
                  help="Cambios de estado, cambios de plan, warnings y recomendaciones emitidos para esta instancia."
                >
                  <TableContainer className="cmd-table-scroll">
                    <Table size="sm" aria-label="Eventos de la instancia">
                      <TableHead>
                        <TableRow>
                          <TableHeader>Fecha</TableHeader>
                          <TableHeader>Tipo</TableHeader>
                          <TableHeader>Razón</TableHeader>
                          <TableHeader>Mensaje</TableHeader>
                        </TableRow>
                      </TableHead>
                      <TableBody>
                        {instanceActivity.map((event) => (
                          <TableRow key={event.id}>
                            <TableCell className="cmd-dim">{fmtDateTime(event.created_at)}</TableCell>
                            <TableCell>{event.label}</TableCell>
                            <TableCell className="cmd-mono cmd-dim">{event.reason}</TableCell>
                            <TableCell className="cmd-dim">{event.message}</TableCell>
                          </TableRow>
                        ))}
                      </TableBody>
                    </Table>
                  </TableContainer>
                </ChartBlock>
              ) : (
                <EmptyBlock
                  head="Actividad"
                  title="Sin actividad registrada"
                  body="Los eventos aparecen cuando el servidor los emite."
                />
              ))}

            {tab === 'domains' && (
              <ChartBlock
                title="Lo único servible hoy"
                help="El subdominio técnico es el fallback que siempre queda visible, incluso cuando falle un dominio propio."
              >
                <Defs
                  items={[
                    {
                      label: 'Subdominio técnico',
                      value: <span className="cmd-mono">{client?.slug ?? '—'}.commander.app</span>,
                      hint: 'fallback siempre visible',
                    },
                  ]}
                />
              </ChartBlock>
            )}
          </div>
        </div>
      </DetailTabs>

      {dialog && spec && (
        <ActionModal
          open
          kicker={spec.kicker}
          title={spec.title}
          body={spec.body}
          danger={spec.danger}
          submitLabel={spec.submitLabel}
          fields={spec.fields}
          initial={spec.initial}
          onClose={() => setDialog(null)}
          onSubmit={async (data) => {
            await instancesService.run(instance.id, dialog, data);
            core.reload();
            setFlash({ title: 'Acción aplicada', body: spec.done });
          }}
        />
      )}

      {quotaDialog && (
        <ActionModal
          open
          kicker={`Cuota ${quotaDialog.id}`}
          title={`Editar ${METRICS[quotaDialog.metric_key]?.name ?? quotaDialog.metric_key}`}
          body="Un límite vacío significa medir sin limitar. La API no permite borrar cuotas."
          submitLabel="Guardar cuota"
          fields={[
            {
              key: 'limit_value',
              label: `Límite (${METRICS[quotaDialog.metric_key]?.unit ?? ''})`,
              placeholder: 'Sin límite',
            },
            { key: 'warning_percent', label: 'Porcentaje de aviso' },
            {
              key: 'action',
              label: 'Acción',
              type: 'select',
              helper: QUOTA_ACTION[quotaDialog.action]?.help,
              options: Object.entries(QUOTA_ACTION).map(([value, meta]) => ({ value, label: meta.label })),
            },
            { key: 'consecutive_periods', label: 'Periodos consecutivos' },
          ]}
          initial={{
            limit_value: quotaDialog.limit_value ?? '',
            warning_percent: quotaDialog.warning_percent ?? '',
            action: quotaDialog.action,
            consecutive_periods: quotaDialog.consecutive_periods ?? 1,
          }}
          onClose={() => setQuotaDialog(null)}
          onSubmit={async (data) => {
            await instancesService.saveQuota(quotaDialog.id, {
              limit_value: data.limit_value === '' ? null : Number(data.limit_value),
              warning_percent: data.warning_percent === '' ? null : Number(data.warning_percent),
              action: String(data.action) as QuotaAction,
              consecutive_periods: Number(data.consecutive_periods) || 1,
            });
            core.reload();
            setFlash({
              title: 'Cuota guardada',
              body: 'Los cambios aplican a partir de la siguiente medición.',
            });
          }}
        />
      )}
    </>
  );
}

/** Un bloque vacío es su título fuera de la caja y el motivo dentro, como cualquier otro. */
function EmptyBlock({ head, title, body }: { head: string; title: string; body: string }) {
  return (
    <section className="cmd-chart">
      <h2 className="cmd-chart__title">{head}</h2>
      <EmptyState title={title} body={body} />
    </section>
  );
}

function InstanceLogsPanel({ instanceId }: { instanceId: string }) {
  const instancesService = useInstancesService();
  const res = useResource(() => instancesService.logs(instanceId), [instanceId]);

  if (res.error) return <ErrorState error={res.error} onRetry={res.reload} />;
  if (res.loading) return <SkeletonText paragraph lineCount={4} />;

  const rows = (res.data ?? []).slice(0, 40);
  if (rows.length === 0) {
    return (
      <EmptyBlock
        head="Logs"
        title="Sin logs para esta instancia"
        body="Los logs pueden desaparecer por retención o por deprovisioning."
      />
    );
  }

  return (
    <ChartBlock
      title="Últimos requests"
      help="Los cuarenta requests más recientes de esta instancia. Pueden desaparecer por retención o por deprovisioning."
      linkTo={`/logs?instance=${instanceId}`}
      linkLabel="Ver en logs globales"
    >
      <TableContainer className="cmd-table-scroll">
        <Table size="sm" aria-label="Últimos requests">
          <TableHead>
            <TableRow>
              <TableHeader>Fecha</TableHeader>
              <TableHeader>Método</TableHeader>
              <TableHeader>Ruta</TableHeader>
              <TableHeader style={RIGHT}>Estado</TableHeader>
              <TableHeader style={RIGHT}>Duración</TableHeader>
              <TableHeader>Actor</TableHeader>
              <TableHeader>IP</TableHeader>
              <TableHeader style={RIGHT}>Bytes</TableHeader>
            </TableRow>
          </TableHead>
          <TableBody>
            {rows.map((log) => (
              <TableRow key={log.id}>
                <TableCell className="cmd-dim">{fmtDateTime(log.created_at)}</TableCell>
                <TableCell className="cmd-mono cmd-dim">{log.method}</TableCell>
                <TableCell className="cmd-mono cmd-dim">{log.path}</TableCell>
                <TableCell className={`cmd-text-${statusTone(log.status)}`} style={RIGHT}>
                  {log.status}
                </TableCell>
                <TableCell className="cmd-mono cmd-dim" style={RIGHT}>
                  {log.duration_ms} ms
                </TableCell>
                <TableCell className="cmd-mono cmd-dim">{log.actor}</TableCell>
                <TableCell className="cmd-mono cmd-dim">{log.ip}</TableCell>
                <TableCell style={RIGHT}>{fmtNum(log.bytes)}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </TableContainer>
    </ChartBlock>
  );
}
