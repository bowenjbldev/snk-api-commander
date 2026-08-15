import { useMemo, useState } from 'react';
import { Button, Callout, SkeletonText } from '@carbon/react';
import { Link, useParams } from 'react-router-dom';
import {
  INVOICE_STATUS,
  METRICS,
  PROVISIONING,
  QUOTA_ACTION,
  SUB_STATUS,
  fmtDate,
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
import { ErrorState, Forbidden } from '@shared/components/States';
import { quotaPercent } from '@features/quotas/model/quotaHealth';
import { SUBSCRIPTION_MODE_CATALOG } from '../model/subscriptionColumns';
import { invoicesReferencing, isSubscriptionDeletable } from '../model/subscriptionCapacity';
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

export function SubscriptionDetailPage() {
  const { can } = useApp();
  const subscriptionsService = useSubscriptionsService();
  const { id = '', tab = 'summary' } = useParams();
  const [dialog, setDialog] = useState<'shared-quota' | 'edit' | null>(null);

  const res = useResource(() => subscriptionsService.getWithContext(id), [id]);
  const [sub, accounts, applications, items, clients, instances, plans, quotas, invoices, activity] = pending(
    res.data,
    [null, [], [], [], [], [], [], [], [], []],
  );
  const cliById = useMemo(() => lut(clients), [clients]);
  const planById = useMemo(() => lut(plans), [plans]);

  if (!can('show subscriptions')) return <Forbidden perm="show subscriptions" />;
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
  const myInvoices = invoicesReferencing(invoices, sub.id);
  const myActivity = activity.filter((a) => a.subscription_id === sub.id);
  const usedMetrics = new Set(sharedQuotas.map((q) => q.metric_key));
  const deletable = isSubscriptionDeletable(myItems, sharedQuotas, myInvoices);

  return (
    <>
      <PageHeader
        title={sub.name}
        crumbs={[{ label: 'Contratos', to: '/subscriptions' }, { label: sub.name }]}
        lede={
          <>
            <StatusTag catalog={SUB_STATUS} value={sub.status} />{' '}
            <StatusTag catalog={SUBSCRIPTION_MODE_CATALOG} value={sub.mode} /> {activeItems.length} de{' '}
            {sub.max_instances} cabeceras activas
          </>
        }
        actions={
          <>
            {can('update subscriptions') && (
              <Button kind="tertiary" onClick={() => setDialog('edit')}>
                Editar términos
              </Button>
            )}
            {can('create quotas') && tab === 'quotas' && (
              <Button onClick={() => setDialog('shared-quota')}>Nueva cuota compartida</Button>
            )}
          </>
        }
      />

      <DetailTabs base={`/subscriptions/${sub.id}`} tabs={SUBSCRIPTION_TABS} current={tab}>
        <div className="cmd-stack">
          {tab === 'summary' && (
            <div className="cmd-panels">
              <Panel title="Términos">
                <Defs
                  items={[
                    { label: 'Cuenta', value: account ? <Link to={`/accounts/${account.id}`}>{account.name}</Link> : '—' },
                    {
                      label: 'Aplicación',
                      value: application ? <Link to={`/applications/${application.id}`}>{application.name}</Link> : '—',
                    },
                    { label: 'Modalidad', value: sub.mode === 'negotiated' ? 'Negociado' : 'Catálogo' },
                    { label: 'Precio pactado', value: fmtMoney(sub.monthly_amount, sub.currency) },
                    { label: 'Capacidad', value: `${activeItems.length} activas de ${sub.max_instances}` },
                  ]}
                />
                <p className="cmd-meter__caption">
                  El precio pactado no se recalcula al cambiar el plan operativo de una cabecera.
                </p>
              </Panel>
              <Panel title="Fechas">
                <Defs
                  items={[
                    { label: 'Inicio', value: fmtDateTime(sub.starts_at) },
                    { label: 'Fin', value: sub.ends_at ? fmtDateTime(sub.ends_at) : 'Sin fecha de fin' },
                    { label: 'Trial', value: sub.trial_ends_at ? `hasta ${fmtDateTime(sub.trial_ends_at)}` : 'Sin trial' },
                  ]}
                />
              </Panel>
              {!deletable && (
                <div style={{ gridColumn: '1 / -1' }}>
                  <Callout
                    kind="info"
                    lowContrast
                    title="No se ofrece borrar este contrato"
                    subtitle={`Tiene ${myItems.length} cabeceras, ${sharedQuotas.length} cuotas compartidas y ${myInvoices.length} facturas. Lo que corresponde es cancelarlo cambiando su estado.`}
                  />
                </div>
              )}
            </div>
          )}

          {tab === 'items' && (
            <DataView
              label="Cabeceras del contrato"
              rows={myItems}
              columns={[
                {
                  key: 'client',
                  header: 'Cliente',
                  value: (it) => cliById.get(it.client_id)?.name ?? '',
                  render: (it) => <Link to={`/clients/${it.client_id}`}>{cliById.get(it.client_id)?.name ?? '—'}</Link>,
                },
                {
                  key: 'instance',
                  header: 'Instancia',
                  value: (it) => it.instance_id,
                  render: (it) => (
                    <Link className="cmd-mono" to={`/instances/${it.instance_id}`}>
                      {it.instance_id}
                    </Link>
                  ),
                },
                { key: 'plan', header: 'Plan operativo', value: (it) => (it.plan_id ? (planById.get(it.plan_id)?.name ?? '') : '') },
                {
                  key: 'status',
                  header: 'Estado',
                  value: (it) => it.status,
                  render: (it) => <StatusTag catalog={ITEM_STATUS_CATALOG} value={it.status} />,
                },
                {
                  key: 'provisioning',
                  header: 'Provisioning',
                  render: (it) => {
                    const inst = instances.find((i) => i.id === it.instance_id);
                    return <StatusTag catalog={PROVISIONING} value={inst?.provisioning_status} />;
                  },
                },
                { key: 'started_at', header: 'Inicio', value: (it) => it.started_at, render: (it) => fmtDate(it.started_at) },
                { key: 'ended_at', header: 'Fin', value: (it) => it.ended_at, render: (it) => fmtDate(it.ended_at) },
              ]}
              emptyTitle="Sin cabeceras"
              emptyBody="Este contrato no tiene todavía ninguna instancia asociada."
            />
          )}

          {tab === 'quotas' && (
            <DataView
              label="Cuotas compartidas"
              rows={sharedQuotas}
              columns={[
                {
                  key: 'metric',
                  header: 'Métrica',
                  value: (q) => METRICS[q.metric_key]?.name ?? q.metric_key,
                  render: (q) => <Link to={`/quotas/${q.id}`}>{METRICS[q.metric_key]?.name ?? q.metric_key}</Link>,
                },
                {
                  key: 'current',
                  header: 'Consumo agregado',
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
                { key: 'action', header: 'Acción', value: (q) => q.action, render: (q) => <StatusTag catalog={QUOTA_ACTION} value={q.action} /> },
                { key: 'measured_at', header: 'Medición', value: (q) => q.measured_at, render: (q) => fmtRel(q.measured_at) },
              ]}
              emptyTitle="Sin cuotas compartidas"
              emptyBody="Una cuota compartida agrega el consumo de todas las cabeceras del contrato. Es el único sitio donde se crea una cuota."
            />
          )}

          {tab === 'invoices' && (
            <DataView
              label="Facturas que referencian el contrato"
              rows={myInvoices}
              columns={[
                {
                  key: 'number',
                  header: 'Número',
                  value: (i) => i.number,
                  render: (i) => (
                    <Link className="cmd-mono" to={`/invoices/${i.id}`}>
                      {i.number}
                    </Link>
                  ),
                },
                { key: 'status', header: 'Estado', value: (i) => i.status, render: (i) => <StatusTag catalog={INVOICE_STATUS} value={i.status} /> },
                { key: 'issued_at', header: 'Emisión', value: (i) => i.issued_at, render: (i) => fmtDate(i.issued_at) },
                { key: 'total', header: 'Total', align: 'right', value: (i) => i.total, render: (i) => fmtMoney(i.total, i.currency) },
              ]}
              defaultSort={{ key: 'issued_at', dir: 'desc' }}
              emptyTitle="Sin facturas"
              emptyBody="Ninguna línea de factura referencia este contrato."
            />
          )}

          {tab === 'activity' && (
            <DataView
              label="Actividad del contrato"
              rows={myActivity}
              columns={[
                { key: 'created_at', header: 'Fecha', value: (a) => a.created_at, render: (a) => fmtDateTime(a.created_at) },
                { key: 'label', header: 'Tipo', value: (a) => a.label, render: (a) => <Link to={`/activity/${a.id}`}>{a.label}</Link> },
                { key: 'message', header: 'Mensaje', value: (a) => a.message },
              ]}
              defaultSort={{ key: 'created_at', dir: 'desc' }}
              emptyTitle="Sin actividad"
              emptyBody="No hay eventos asociados a este contrato."
            />
          )}
        </div>
      </DetailTabs>

      <ActionModal
        open={dialog === 'edit'}
        title="Editar términos del contrato"
        body="Cuenta, aplicación y modalidad son inmutables. La capacidad nunca puede quedar por debajo de las cabeceras activas."
        submitLabel="Guardar"
        fields={[
          { key: 'name', label: 'Nombre' },
          { key: 'monthly_amount', label: 'Importe mensual', type: 'number' },
          { key: 'max_instances', label: 'Capacidad', type: 'number', helper: `Hay ${activeItems.length} cabeceras activas.` },
          {
            key: 'status',
            label: 'Estado',
            type: 'select',
            options: Object.entries(SUB_STATUS).map(([value, meta]) => ({ value, label: meta.label })),
          },
        ]}
        initial={{
          name: sub.name,
          monthly_amount: sub.monthly_amount,
          max_instances: sub.max_instances,
          status: sub.status,
        }}
        onClose={() => setDialog(null)}
        onSubmit={async (data) => {
          await subscriptionsService.updateTerms(sub.id, {
            name: String(data.name),
            monthly_amount: Number(data.monthly_amount),
            max_instances: Number(data.max_instances),
            status: sub.status,
          });
          res.reload();
        }}
      />

      <ActionModal
        open={dialog === 'shared-quota'}
        kicker="Cuota compartida"
        title="Nueva cuota compartida"
        body="Agrega el consumo de todas las cabeceras del contrato. Solo admite métricas de su misma aplicación."
        submitLabel="Crear cuota"
        fields={[
          {
            key: 'metric_key',
            label: 'Métrica',
            type: 'select',
            options: (application?.metrics ?? [])
              .filter((m) => !usedMetrics.has(m.key))
              .map((m) => ({ value: m.key, label: `${m.name} (${m.unit})` })),
          },
          { key: 'limit_value', label: 'Límite', type: 'number', helper: 'Vacío significa medir sin limitar.' },
          { key: 'warning_percent', label: 'Porcentaje de aviso', type: 'number' },
          {
            key: 'action',
            label: 'Acción',
            type: 'select',
            options: Object.entries(QUOTA_ACTION).map(([value, meta]) => ({ value, label: meta.label })),
          },
          { key: 'consecutive_periods', label: 'Periodos consecutivos', type: 'number' },
        ]}
        initial={{ metric_key: '', limit_value: 0, warning_percent: 85, action: 'warn', consecutive_periods: 2 }}
        onClose={() => setDialog(null)}
        onSubmit={async (data) => {
          await subscriptionsService.createSharedQuota(sub.id, {
            metric_key: String(data.metric_key),
            limit_value: data.limit_value === '' ? null : Number(data.limit_value),
            warning_percent: Number(data.warning_percent),
            consecutive_periods: Number(data.consecutive_periods),
          });
          res.reload();
        }}
      />
    </>
  );
}
