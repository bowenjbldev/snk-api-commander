import { useMemo } from 'react';
import { Button, Callout, SkeletonText } from '@carbon/react';
import { useNavigate, useParams } from 'react-router-dom';
import { Link } from '@shared/components/AppLink';
import { ENTITY_STATUS, METRICS, PROVISIONING, SERVICE, fmtDateTime } from '@services/api/mock/commander-mock';
import { useApp } from '@app/provider';
import { useResource } from '@shared/hooks/useResource';
import { lut, pending } from '@shared/lib/data';
import { PageHeader } from '@shared/components/PageHeader';
import { Defs, DetailTabs, Panel } from '@shared/components/Detail';
import { DataView } from '@shared/components/DataView';
import { StatusTag } from '@shared/components/StatusTag';
import { Meter } from '@shared/components/Meter';
import { ErrorState, Forbidden } from '@shared/components/States';
import { worstQuotaByInstance } from '@features/quotas/model/quotaHealth';
import { useClientsService } from '../services/clientsService';

const CLIENT_TABS = [
  { slug: 'summary', label: 'Resumen' },
  { slug: 'instances', label: 'Instancias' },
];

export function ClientDetailPage() {
  const { can } = useApp();
  const clientsService = useClientsService();
  const { id = '', tab = 'summary' } = useParams();
  const navigate = useNavigate();

  const res = useResource(() => clientsService.getWithContext(id), [id]);
  const [client, accounts, allInstances, applications, plans, subscriptions, quotas] = pending(res.data, [
    null,
    [],
    [],
    [],
    [],
    [],
    [],
  ]);
  const appById = useMemo(() => lut(applications), [applications]);
  const planById = useMemo(() => lut(plans), [plans]);
  const subById = useMemo(() => lut(subscriptions), [subscriptions]);
  const worstQuota = useMemo(() => worstQuotaByInstance(quotas), [quotas]);

  if (!can('show clients')) return <Forbidden perm="show clients" />;
  if (res.error) {
    return (
      <>
        <PageHeader title="Cliente" crumbs={[{ label: 'Clientes', to: '/clients' }]} />
        <ErrorState error={res.error} onRetry={res.reload} />
      </>
    );
  }
  if (res.loading || !client) return <SkeletonText paragraph lineCount={6} />;

  const account = lut(accounts).get(client.account_id);
  const instances = allInstances.filter((i) => i.client_id === client.id);

  return (
    <>
      <PageHeader
        title={client.name}
        crumbs={[{ label: 'Clientes', to: '/clients' }, { label: client.name }]}
        lede={
          <>
            <StatusTag catalog={ENTITY_STATUS} value={client.status} />{' '}
            <span className="cmd-mono">{client.slug}</span>
          </>
        }
        actions={
          can('update clients') && (
            <Button kind="tertiary" onClick={() => navigate(`/clients/${client.id}/edit`)}>
              Editar cliente
            </Button>
          )
        }
      />

      <DetailTabs base={`/clients/${client.id}`} tabs={CLIENT_TABS} current={tab}>
        <div className="cmd-stack">
          {tab === 'summary' && (
            <>
              {instances.length > 0 && (
                <Callout
                  kind="info"
                  lowContrast
                  title="No se ofrece borrar este cliente"
                  subtitle={`Tiene ${instances.length} instancias. Un cliente con historia se desactiva, no se borra.`}
                />
              )}
              <div className="cmd-panels">
                <Panel title="Identidad">
                  <Defs
                    items={[
                      { label: 'Nombre', value: client.name },
                      { label: 'Subdominio técnico', value: <span className="cmd-mono">{client.slug}</span> },
                      {
                        label: 'Cuenta pagadora',
                        value: account ? <Link to={`/accounts/${account.id}`}>{account.name}</Link> : '—',
                      },
                      { label: 'Correo de contacto', value: client.email },
                      { label: 'Estado', value: <StatusTag catalog={ENTITY_STATUS} value={client.status} /> },
                    ]}
                  />
                </Panel>
                <Panel title="Fechas">
                  <Defs
                    items={[
                      { label: 'Alta', value: fmtDateTime(client.created_at) },
                      { label: 'Actualización', value: fmtDateTime(client.updated_at) },
                    ]}
                  />
                </Panel>
                {client.notes && (
                  <Panel title="Notas internas">
                    <p className="cmd-page__lede">{client.notes}</p>
                  </Panel>
                )}
              </div>
            </>
          )}

          {tab === 'instances' && (
            <DataView
              label="Instancias del cliente"
              rows={instances}
              columns={[
                {
                  key: 'application',
                  header: 'Aplicación',
                  value: (i) => appById.get(i.application_id)?.name ?? '',
                  render: (i) => <Link to={`/instances/${i.id}`}>{appById.get(i.application_id)?.name ?? '—'}</Link>,
                },
                { key: 'plan', header: 'Plan', value: (i) => (i.plan_id ? (planById.get(i.plan_id)?.name ?? '') : '') },
                {
                  key: 'subscription',
                  header: 'Contrato',
                  value: (i) => (i.subscription_id ? (subById.get(i.subscription_id)?.name ?? '') : ''),
                  render: (i) =>
                    i.subscription_id ? (
                      <Link to={`/subscriptions/${i.subscription_id}`}>{subById.get(i.subscription_id)?.name ?? '—'}</Link>
                    ) : (
                      '—'
                    ),
                },
                {
                  key: 'provisioning',
                  header: 'Provisioning',
                  value: (i) => i.provisioning_status,
                  render: (i) => <StatusTag catalog={PROVISIONING} value={i.provisioning_status} />,
                },
                { key: 'service', header: 'Servicio', value: (i) => i.service_status, render: (i) => <StatusTag catalog={SERVICE} value={i.service_status} /> },
                {
                  key: 'quota',
                  header: 'Consumo máximo',
                  render: (i) => {
                    const q = worstQuota.get(i.id);
                    return q ? (
                      <Meter value={q.current_value} limit={q.limit_value} caption={METRICS[q.metric_key]?.name} />
                    ) : (
                      <span className="cmd-meter__caption">Sin cuotas</span>
                    );
                  },
                },
              ]}
              emptyTitle="Sin instancias"
              emptyBody="Este cliente no ejecuta ninguna aplicación todavía."
            />
          )}
        </div>
      </DetailTabs>
    </>
  );
}
