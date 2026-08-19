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
import { useNavigate, useParams } from 'react-router-dom';
import { Link } from '@shared/components/AppLink';
import {
  INVOICE_STATUS,
  METRICS,
  QUOTA_ACTION,
  SUB_STATUS,
  fmtDate,
  fmtDateTime,
  fmtMoney,
  fmtNum,
} from '@services/api/mock/commander-mock';
import type { Quota } from '@services/api/types';
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
import { asQuotaAction, asSubscriptionStatus } from '@shared/lib/enums';
import { SUBSCRIPTION_MODE_CATALOG } from '../model/subscriptionColumns';
import { isCapacityFull, isSubscriptionDeletable, linesReferencing } from '../model/subscriptionCapacity';
import { useSubscriptionsService } from '../services/subscriptionsService';

const SUBSCRIPTION_TABS = [
  { slug: 'summary', label: 'Resumen' },
  { slug: 'items', label: 'Cabeceras' },
  { slug: 'quotas', label: 'Cuotas compartidas' },
  { slug: 'invoices', label: 'Facturas' },
  { slug: 'activity', label: 'Actividad' },
];

const ITEM_STATUS_CATALOG = {
  active: { label: 'Activa', tone: 'green' },
  terminated: { label: 'Terminada', tone: 'cool-gray' },
} as const;

const RIGHT = { textAlign: 'right' } as const;

/** El bloque de cuotas lleva su explicación tenga filas o no: es donde se crean. */
const QUOTAS_HELP =
  'Límites agregados por métrica para todas las cabeceras. Es el único sitio donde se crea una cuota.';

/** Las tablas de una ficha no paginan: enseñan lo reciente y el listado tiene el resto. */
const MAX_LINES = 40;
const MAX_ACTIVITY = 30;

export function SubscriptionDetailPage() {
  const { can } = useApp();
  const subscriptionsService = useSubscriptionsService();
  const navigate = useNavigate();
  const { id = '', tab = 'summary' } = useParams();
  const [dialog, setDialog] = useState<'shared-quota' | 'edit' | null>(null);
  const [editingQuota, setEditingQuota] = useState<Quota | null>(null);
  const [flash, setFlash] = useState<{ title: string; body: string } | null>(null);

  const res = useResource(() => subscriptionsService.getWithContext(id), [id]);
  const [sub, accounts, applications, items, clients, plans, quotas, invoices, activity] = pending(
    res.data,
    [null, [], [], [], [], [], [], [], []],
  );
  const cliById = useMemo(() => lut(clients), [clients]);
  const planById = useMemo(() => lut(plans), [plans]);

  if (!can('show subscriptions')) return <Forbidden perm="show subscriptions" />;
  // Un identificador que no está en los datos cargados no se arregla repitiendo la
  // petición: tiene página propia y dice por qué puede faltar.
  if (res.error?.status === 404) {
    return (
      <PageHeader
        title="No encontrado"
        kicker="404"
        crumbs={[{ label: 'Contratos', to: '/subscriptions' }]}
        lede={`El contrato ${id} no está en los datos cargados. No se asume que se haya borrado: puede tratarse de un identificador de otra sesión.`}
      />
    );
  }
  if (res.error) {
    return (
      <>
        <PageHeader title="Contrato" crumbs={[{ label: 'Contratos', to: '/subscriptions' }]} />
        <ErrorState error={res.error} onRetry={res.reload} />
      </>
    );
  }
  if (res.loading || !sub) return <SkeletonText paragraph lineCount={8} />;

  const account = lut(accounts).get(sub.account_id);
  const application = lut(applications).get(sub.application_id);
  const myItems = items.filter((it) => it.subscription_id === sub.id);
  const activeItems = myItems.filter((it) => it.status === 'active');
  const sharedQuotas = quotas.filter((q) => q.scope === 'subscription' && q.subscription_id === sub.id);
  const lines = linesReferencing(invoices, sub.id);
  const myActivity = activity.filter((a) => a.subscription_id === sub.id).slice(0, MAX_ACTIVITY);
  const usedMetrics = new Set(sharedQuotas.map((q) => q.metric_key));
  const full = isCapacityFull(sub, activeItems.length);
  const canAddItem = can('provision instances') && sub.mode === 'negotiated';

  return (
    <>
      <PageHeader
        title={sub.name}
        kicker={`ID ${sub.id}`}
        crumbs={[
          { label: 'Contratos', to: '/subscriptions' },
          { label: account?.name ?? '—', to: `/accounts/${sub.account_id}` },
        ]}
        lede={`${application?.name ?? '—'} · ${fmtMoney(sub.monthly_amount, sub.currency)} al mes · capacidad ${sub.max_instances}`}
        statuses={
          <>
            <StatusTag catalog={SUB_STATUS} value={sub.status} />
            <StatusTag catalog={SUBSCRIPTION_MODE_CATALOG} value={sub.mode} />
          </>
        }
        actions={
          <>
            {can('update subscriptions') && <Button onClick={() => setDialog('edit')}>Editar términos</Button>}
            {canAddItem && (
              <Button kind="tertiary" onClick={() => navigate(`/provisioning/new?subscription=${sub.id}`)}>
                Añadir cabecera
              </Button>
            )}
            {/* El prototipo pone este botón en el título del bloque de cuotas; el bloque
                del sistema no admite acciones, así que sube a la barra de la página. */}
            {tab === 'quotas' && can('create quotas') && (
              <Button kind="tertiary" onClick={() => setDialog('shared-quota')}>
                Crear cuota compartida
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

      <DetailTabs base={`/subscriptions/${sub.id}`} tabs={SUBSCRIPTION_TABS} current={tab}>
        <div className="cmd-stack">
          {tab === 'summary' && (
            <>
              {full && (
                <Callout
                  kind="warning"
                  lowContrast
                  title="Capacidad agotada"
                  subtitle="Una cabecera más se rechaza con 422 hasta ampliar la capacidad. La capacidad nunca puede quedar por debajo del número de ítems activos."
                />
              )}
              {!isSubscriptionDeletable(myItems, sharedQuotas, lines) && (
                <Callout
                  kind="info"
                  lowContrast
                  title="No se ofrece borrar este contrato"
                  subtitle={`Tiene ${myItems.length} ítems, ${sharedQuotas.length} cuotas compartidas y ${lines.length} líneas de factura. Lo que corresponde es cancelarlo cambiando su estado.`}
                />
              )}
              <ChartBlock
                title="Términos"
                help="Lo pactado con la cuenta. Modalidad, cuenta y aplicación quedan inmutables después de crear el contrato."
              >
                <Defs
                  items={[
                    {
                      label: 'Modalidad',
                      value: <StatusTag catalog={SUBSCRIPTION_MODE_CATALOG} value={sub.mode} />,
                      hint: 'inmutable tras crear',
                    },
                    { label: 'Estado', value: <StatusTag catalog={SUB_STATUS} value={sub.status} /> },
                    {
                      label: 'Cuenta',
                      value: <Link to={`/accounts/${sub.account_id}`}>{account?.name ?? '—'}</Link>,
                      hint: 'inmutable',
                    },
                    {
                      label: 'Aplicación',
                      value: <Link to={`/applications/${sub.application_id}`}>{application?.name ?? '—'}</Link>,
                      hint: 'inmutable',
                    },
                    {
                      label: 'Precio pactado',
                      value: fmtMoney(sub.monthly_amount, sub.currency),
                      hint: 'no se recalcula al cambiar el plan de una cabecera',
                    },
                    {
                      label: 'Capacidad',
                      value: `${activeItems.length} de ${sub.max_instances} cabeceras activas`,
                      hint: full ? 'agotada' : `${sub.max_instances - activeItems.length} libres`,
                    },
                    { label: 'Inicio', value: fmtDate(sub.starts_at) },
                    { label: 'Fin', value: sub.ends_at ? fmtDate(sub.ends_at) : '—' },
                    { label: 'Trial', value: sub.trial_ends_at ? `hasta ${fmtDate(sub.trial_ends_at)}` : 'sin trial' },
                  ]}
                />
              </ChartBlock>
            </>
          )}

          {tab === 'items' &&
            (myItems.length ? (
              <ChartBlock
                title="Cabeceras del contrato"
                // Con el enlace de alta la frase deja sitio a la regla de capacidad; sin él,
                // lo que hay que saber es que el plan de cada cabecera no toca el precio.
                help={
                  canAddItem
                    ? 'Cada cabecera es un cliente con su instancia y su plan operativo. La capacidad nunca puede quedar por debajo de las activas.'
                    : 'Cada cabecera puede usar un plan operativo distinto sin afectar al precio pactado.'
                }
                linkTo={canAddItem ? `/provisioning/new?subscription=${sub.id}` : undefined}
                linkLabel={canAddItem ? 'Añadir cabecera' : undefined}
              >
                <TableContainer className="cmd-table-scroll">
                  <Table size="sm" aria-label="Cabeceras del contrato">
                    <TableHead>
                      <TableRow>
                        <TableHeader>Cliente</TableHeader>
                        <TableHeader>Instancia</TableHeader>
                        <TableHeader>Plan operativo</TableHeader>
                        <TableHeader>Estado</TableHeader>
                        <TableHeader>Inicio</TableHeader>
                        <TableHeader>Fin</TableHeader>
                      </TableRow>
                    </TableHead>
                    <TableBody>
                      {myItems.map((it) => (
                        <TableRow key={it.id}>
                          <TableCell>
                            <Link to={`/clients/${it.client_id}`}>{cliById.get(it.client_id)?.name ?? '—'}</Link>
                          </TableCell>
                          <TableCell>
                            {it.instance_id ? (
                              <Link to={`/instances/${it.instance_id}`}>Instancia {it.instance_id}</Link>
                            ) : (
                              <span className="cmd-dim">—</span>
                            )}
                          </TableCell>
                          <TableCell className="cmd-dim">
                            {planById.get(it.plan_id ?? -1)?.name ?? 'Sin plan'}
                          </TableCell>
                          <TableCell>
                            <StatusTag catalog={ITEM_STATUS_CATALOG} value={it.status} />
                          </TableCell>
                          <TableCell className="cmd-dim">{fmtDate(it.started_at)}</TableCell>
                          <TableCell className="cmd-dim">{it.ended_at ? fmtDate(it.ended_at) : '—'}</TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </TableContainer>
              </ChartBlock>
            ) : (
              <EmptyBlock
                head="Cabeceras"
                title="Sin cabeceras"
                body="Un contrato negociado se prepara antes de dar de alta sus clientes."
              />
            ))}

          {tab === 'quotas' &&
            (sharedQuotas.length ? (
              <ChartBlock title="Cuotas compartidas" help={QUOTAS_HELP}>
                <TableContainer className="cmd-table-scroll">
                  <Table size="sm" aria-label="Cuotas compartidas">
                    <TableHead>
                      <TableRow>
                        <TableHeader>Métrica</TableHeader>
                        <TableHeader style={RIGHT}>Valor</TableHeader>
                        <TableHeader style={RIGHT}>Límite</TableHeader>
                        <TableHeader>Consumo</TableHeader>
                        <TableHeader>Acción</TableHeader>
                        <TableHeader>Último warning</TableHeader>
                        <TableHeader aria-label="Editar" style={RIGHT} />
                      </TableRow>
                    </TableHead>
                    <TableBody>
                      {sharedQuotas.map((quota) => {
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
                              <Meter value={quota.current_value} limit={quota.limit_value} />
                            </TableCell>
                            <TableCell>
                              <StatusTag catalog={QUOTA_ACTION} value={quota.action} />
                            </TableCell>
                            <TableCell className="cmd-dim">
                              {quota.last_warning_at ? fmtDateTime(quota.last_warning_at) : '—'}
                            </TableCell>
                            <TableCell style={RIGHT}>
                              {can('update quotas') && (
                                <Button kind="ghost" size="sm" onClick={() => setEditingQuota(quota)}>
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
                head="Cuotas compartidas"
                help={QUOTAS_HELP}
                title="Sin cuotas compartidas"
                body="Aquí es el único sitio donde se crea una cuota: límites agregados por métrica de la misma aplicación del contrato."
              />
            ))}

          {tab === 'invoices' &&
            (lines.length ? (
              <ChartBlock
                title="Líneas que referencian el contrato"
                help="Las líneas de factura que apuntan a este contrato, con la cabecera concreta cuando la tienen."
              >
                <TableContainer className="cmd-table-scroll">
                  <Table size="sm" aria-label="Líneas que referencian el contrato">
                    <TableHead>
                      <TableRow>
                        <TableHeader>Factura</TableHeader>
                        <TableHeader>Estado</TableHeader>
                        <TableHeader>Descripción</TableHeader>
                        <TableHeader style={RIGHT}>Cantidad</TableHeader>
                        <TableHeader style={RIGHT}>Precio</TableHeader>
                        <TableHeader style={RIGHT}>Importe</TableHeader>
                      </TableRow>
                    </TableHead>
                    <TableBody>
                      {lines.slice(0, MAX_LINES).map(({ id: lineId, invoice, line }) => (
                        <TableRow key={lineId}>
                          <TableCell>
                            <Link to={`/invoices/${invoice.id}`}>{invoice.number}</Link>
                          </TableCell>
                          <TableCell>
                            <StatusTag catalog={INVOICE_STATUS} value={invoice.status} />
                          </TableCell>
                          <TableCell className="cmd-dim">{line.description}</TableCell>
                          <TableCell style={RIGHT}>{fmtNum(line.quantity)}</TableCell>
                          <TableCell className="cmd-dim" style={RIGHT}>
                            {fmtMoney(line.unit_price, invoice.currency)}
                          </TableCell>
                          <TableCell style={RIGHT}>{fmtMoney(line.amount, invoice.currency)}</TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </TableContainer>
              </ChartBlock>
            ) : (
              <EmptyBlock
                head="Facturas"
                title="Sin líneas de factura"
                body="Ninguna factura referencia este contrato todavía."
              />
            ))}

          {tab === 'activity' &&
            (myActivity.length ? (
              <ChartBlock
                title="Warnings, recomendaciones y cambios"
                help="Lo que el servidor ha emitido sobre este contrato y sus instancias."
              >
                <TableContainer className="cmd-table-scroll">
                  <Table size="sm" aria-label="Warnings, recomendaciones y cambios">
                    <TableHead>
                      <TableRow>
                        <TableHeader>Fecha</TableHeader>
                        <TableHeader>Tipo</TableHeader>
                        <TableHeader>Instancia</TableHeader>
                        <TableHeader>Razón</TableHeader>
                        <TableHeader>Mensaje</TableHeader>
                      </TableRow>
                    </TableHead>
                    <TableBody>
                      {myActivity.map((a) => (
                        <TableRow key={a.id}>
                          <TableCell className="cmd-dim">{fmtDateTime(a.created_at)}</TableCell>
                          <TableCell>{a.label}</TableCell>
                          <TableCell>
                            {a.instance_id ? (
                              <Link to={`/instances/${a.instance_id}`}>{a.instance_id}</Link>
                            ) : (
                              <span className="cmd-dim">—</span>
                            )}
                          </TableCell>
                          <TableCell className="cmd-mono cmd-dim">{a.reason}</TableCell>
                          <TableCell className="cmd-dim">{a.message}</TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </TableContainer>
              </ChartBlock>
            ) : (
              <EmptyBlock head="Actividad" title="Sin actividad" body="Sin eventos asociados a este contrato." />
            ))}
        </div>
      </DetailTabs>

      <ActionModal
        open={dialog === 'edit'}
        kicker={`Contrato ${sub.id}`}
        title="Editar términos permitidos"
        body="Cuenta, aplicación y modalidad son inmutables. La capacidad no puede quedar por debajo de las cabeceras activas."
        submitLabel="Guardar términos"
        fields={[
          { key: 'name', label: 'Nombre' },
          {
            key: 'status',
            label: 'Estado',
            type: 'select',
            options: Object.entries(SUB_STATUS).map(([value, meta]) => ({ value, label: meta.label })),
            helper: 'Cancelar es un cambio de estado, no un borrado.',
          },
          { key: 'monthly_amount', label: 'Importe mensual' },
          { key: 'max_instances', label: 'Capacidad' },
        ]}
        initial={{
          name: sub.name,
          status: sub.status,
          monthly_amount: sub.monthly_amount,
          max_instances: sub.max_instances,
        }}
        onClose={() => setDialog(null)}
        onSubmit={async (data) => {
          await subscriptionsService.updateTerms(sub.id, {
            name: String(data.name),
            status: asSubscriptionStatus(String(data.status)),
            monthly_amount: Number(data.monthly_amount),
            max_instances: Number(data.max_instances),
          });
          res.reload();
          setFlash({ title: 'Contrato actualizado', body: 'Los términos permitidos se han guardado.' });
        }}
      />

      <ActionModal
        open={dialog === 'shared-quota'}
        kicker={`Contrato ${sub.id}`}
        title="Crear cuota compartida"
        body="Límite agregado por métrica para todas las cabeceras del contrato. Solo métricas de su misma aplicación."
        submitLabel="Crear cuota"
        fields={[
          {
            key: 'metric_key',
            label: 'Métrica',
            type: 'select',
            options: [{ value: '', label: 'Elige una métrica' }].concat(
              (application?.metrics ?? [])
                .filter((m) => !usedMetrics.has(m.key))
                .map((m) => ({ value: m.key, label: m.name })),
            ),
          },
          { key: 'limit_value', label: 'Límite agregado', placeholder: 'Sin límite' },
          { key: 'warning_percent', label: 'Porcentaje de aviso' },
          {
            key: 'action',
            label: 'Acción',
            type: 'select',
            options: Object.entries(QUOTA_ACTION).map(([value, meta]) => ({ value, label: meta.label })),
          },
          { key: 'consecutive_periods', label: 'Periodos consecutivos' },
        ]}
        initial={{ metric_key: '', limit_value: '', warning_percent: 85, action: 'recommend', consecutive_periods: 2 }}
        onClose={() => setDialog(null)}
        onSubmit={async (data) => {
          await subscriptionsService.createSharedQuota(sub.id, {
            metric_key: String(data.metric_key),
            // Vacío es medir sin limitar, que no es lo mismo que un límite de cero.
            limit_value: data.limit_value === '' ? null : Number(data.limit_value),
            warning_percent: data.warning_percent === '' ? null : Number(data.warning_percent),
            action: asQuotaAction(String(data.action)),
            consecutive_periods: Number(data.consecutive_periods) || 1,
          });
          res.reload();
          setFlash({ title: 'Cuota compartida creada', body: 'Ya mide el consumo agregado de las cabeceras.' });
        }}
      />

      <ActionModal
        open={editingQuota !== null}
        kicker={`Cuota ${editingQuota?.id ?? ''}`}
        title={`Editar ${METRICS[editingQuota?.metric_key ?? '']?.name ?? 'la cuota'}`}
        body="Un límite vacío significa medir sin limitar. La API no permite borrar cuotas."
        submitLabel="Guardar cuota"
        fields={[
          {
            key: 'limit_value',
            label: `Límite (${METRICS[editingQuota?.metric_key ?? '']?.unit ?? ''})`,
            placeholder: 'Sin límite',
          },
          { key: 'warning_percent', label: 'Porcentaje de aviso' },
          {
            key: 'action',
            label: 'Acción',
            type: 'select',
            options: Object.entries(QUOTA_ACTION).map(([value, meta]) => ({ value, label: meta.label })),
            helper: editingQuota ? QUOTA_ACTION[editingQuota.action]?.help : undefined,
          },
          { key: 'consecutive_periods', label: 'Periodos consecutivos' },
        ]}
        initial={{
          limit_value: editingQuota?.limit_value ?? '',
          warning_percent: editingQuota?.warning_percent ?? '',
          action: editingQuota?.action ?? 'warn',
          consecutive_periods: editingQuota?.consecutive_periods ?? 1,
        }}
        onClose={() => setEditingQuota(null)}
        onSubmit={async (data) => {
          if (!editingQuota) return;
          await subscriptionsService.updateQuota(editingQuota.id, {
            limit_value: data.limit_value === '' ? null : Number(data.limit_value),
            warning_percent: data.warning_percent === '' ? null : Number(data.warning_percent),
            action: asQuotaAction(String(data.action)),
            consecutive_periods: Number(data.consecutive_periods) || 1,
          });
          res.reload();
          setFlash({ title: 'Cuota guardada', body: 'Los cambios aplican a partir de la siguiente medición.' });
        }}
      />
    </>
  );
}

/** Un bloque vacío es su título fuera de la caja y el motivo dentro, como cualquier otro. */
function EmptyBlock({
  head,
  help,
  title,
  body,
}: {
  head: string;
  help?: string;
  title: string;
  body: string;
}) {
  return (
    <section className="cmd-chart">
      <h2 className="cmd-chart__title">{head}</h2>
      {help && <span className="cmd-chart__help">{help}</span>}
      <EmptyState title={title} body={body} />
    </section>
  );
}
