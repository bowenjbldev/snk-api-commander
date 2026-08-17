import { useMemo } from 'react';
import { Button, Callout, SkeletonText } from '@carbon/react';
import { useNavigate, useParams } from 'react-router-dom';
import { Link } from '@shared/components/AppLink';
import {
  ENTITY_STATUS,
  INVOICE_STATUS,
  fmtDate,
  fmtDateTime,
  fmtMoney,
} from '@services/api/mock/commander-mock';
import { useApp } from '@app/provider';
import { useResource } from '@shared/hooks/useResource';
import { lut, pending } from '@shared/lib/data';
import { PageHeader } from '@shared/components/PageHeader';
import { Defs, DetailTabs, Panel } from '@shared/components/Detail';
import { DataView } from '@shared/components/DataView';
import { StatusTag } from '@shared/components/StatusTag';
import { ErrorState, Forbidden } from '@shared/components/States';
import { subscriptionColumns } from '@features/subscriptions/model/subscriptionColumns';
import { contractualMrr, healthyInstances, isAccountDeletable, unpaidInvoices } from '../model/accountTotals';
import { useAccountsService } from '../services/accountsService';

const ACCOUNT_TABS = [
  { slug: 'summary', label: 'Resumen' },
  { slug: 'clients', label: 'Clientes' },
  { slug: 'subscriptions', label: 'Contratos' },
  { slug: 'invoices', label: 'Facturas' },
  { slug: 'activity', label: 'Actividad' },
];

export function AccountDetailPage() {
  const { can } = useApp();
  const accountsService = useAccountsService();
  const { id = '', tab = 'summary' } = useParams();
  const navigate = useNavigate();

  const res = useResource(() => accountsService.getWithContext(id), [id]);
  const [account, allClients, allSubs, allInvoices, allInstances, applications, allActivity] = pending(res.data, [
    null,
    [],
    [],
    [],
    [],
    [],
    [],
  ]);
  const appById = useMemo(() => lut(applications), [applications]);
  const cliById = useMemo(() => lut(allClients), [allClients]);

  if (!can('show accounts')) return <Forbidden perm="show accounts" />;
  if (res.error) {
    return (
      <>
        <PageHeader title="Cuenta" crumbs={[{ label: 'Cuentas', to: '/accounts' }]} />
        <ErrorState error={res.error} onRetry={res.reload} />
      </>
    );
  }
  if (res.loading || !account) return <SkeletonText paragraph lineCount={8} />;

  const clients = allClients.filter((c) => c.account_id === account.id);
  const subs = allSubs.filter((s) => s.account_id === account.id);
  const invoices = allInvoices.filter((i) => i.account_id === account.id);
  const instances = allInstances.filter((i) => i.account_id === account.id);
  const activity = allActivity.filter((a) => a.account_id === account.id);

  const mrr = contractualMrr(subs);
  const unpaid = unpaidInvoices(invoices);
  const healthy = healthyInstances(instances);
  const deletable = isAccountDeletable(clients, subs, invoices);

  return (
    <>
      <PageHeader
        title={account.name}
        crumbs={[{ label: 'Cuentas', to: '/accounts' }, { label: account.name }]}
        lede={
          <>
            <StatusTag catalog={ENTITY_STATUS} value={account.status} />{' '}
            <span className="cmd-mono">{account.tax_id}</span>
          </>
        }
        actions={
          can('update accounts') && (
            <Button kind="tertiary" onClick={() => navigate(`/accounts/${account.id}/edit`)}>
              Editar cuenta
            </Button>
          )
        }
      />

      <DetailTabs base={`/accounts/${account.id}`} tabs={ACCOUNT_TABS} current={tab}>
        <div className="cmd-stack">
          {tab === 'summary' && (
            <>
              <Callout
                kind={healthy.length === instances.length ? 'success' : 'warning'}
                lowContrast
                title="Salud de sus clientes"
                subtitle={`${healthy.length} de ${instances.length} instancias sirven tráfico sin incidencia.`}
              />
              {!deletable && (
                <Callout
                  kind="info"
                  lowContrast
                  title="No se ofrece borrar esta cuenta"
                  subtitle={`Tiene ${clients.length} clientes, ${subs.length} contratos y ${invoices.length} facturas. Lo que corresponde es desactivarla y conservar la historia.`}
                />
              )}
              <div className="cmd-panels">
                <Panel title="Contacto">
                  <Defs
                    items={[
                      { label: 'Correo', value: account.email },
                      { label: 'Teléfono', value: account.phone },
                      { label: 'Correo de facturación', value: account.billing_email },
                    ]}
                  />
                </Panel>
                <Panel title="Facturación">
                  <Defs
                    items={[
                      { label: 'NIF', value: <span className="cmd-mono">{account.tax_id}</span> },
                      { label: 'Condiciones de pago', value: account.payment_terms },
                      { label: 'MRR contractual', value: fmtMoney(mrr) },
                      {
                        label: 'Pendiente de cobro',
                        value: `${fmtMoney(unpaid.reduce((t, i) => t + i.total, 0))} en ${unpaid.length} facturas`,
                      },
                    ]}
                  />
                </Panel>
                <Panel title="Dirección">
                  <Defs
                    items={[
                      { label: 'Dirección', value: account.address },
                      { label: 'País', value: account.country },
                    ]}
                  />
                </Panel>
                {account.notes && (
                  <Panel title="Notas internas">
                    <p className="cmd-page__lede">{account.notes}</p>
                  </Panel>
                )}
              </div>
            </>
          )}

          {tab === 'clients' && (
            <DataView
              label="Clientes de la cuenta"
              rows={clients}
              columns={[
                { key: 'name', header: 'Nombre', value: (c) => c.name, render: (c) => <Link to={`/clients/${c.id}`}>{c.name}</Link> },
                { key: 'slug', header: 'Subdominio', value: (c) => c.slug, render: (c) => <span className="cmd-mono">{c.slug}</span> },
                { key: 'status', header: 'Estado', value: (c) => c.status, render: (c) => <StatusTag catalog={ENTITY_STATUS} value={c.status} /> },
                {
                  key: 'instances',
                  header: 'Instancias',
                  align: 'right',
                  value: (c) => instances.filter((i) => i.client_id === c.id).length,
                },
              ]}
              emptyTitle="Sin clientes"
              emptyBody="Esta cuenta todavía no tiene tenants. El alta crea cuenta, cliente, contrato e instancia de una vez."
            />
          )}

          {tab === 'subscriptions' && (
            <DataView
              label="Contratos de la cuenta"
              rows={subs}
              columns={subscriptionColumns(appById)}
              emptyTitle="Sin contratos"
              emptyBody="Un contrato de catálogo se crea con el alta; uno negociado se crea a mano antes de añadir cabeceras."
            />
          )}

          {tab === 'invoices' && (
            <DataView
              label="Facturas de la cuenta"
              rows={invoices}
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
                { key: 'due_at', header: 'Vencimiento', value: (i) => i.due_at, render: (i) => fmtDate(i.due_at) },
                { key: 'total', header: 'Total', align: 'right', value: (i) => i.total, render: (i) => fmtMoney(i.total, i.currency) },
              ]}
              defaultSort={{ key: 'issued_at', dir: 'desc' }}
              emptyTitle="Sin facturas"
              emptyBody="Todavía no se ha emitido ninguna factura a esta cuenta."
            />
          )}

          {tab === 'activity' && (
            <DataView
              label="Actividad de la cuenta"
              rows={activity}
              columns={[
                { key: 'created_at', header: 'Fecha', value: (a) => a.created_at, render: (a) => fmtDateTime(a.created_at) },
                { key: 'label', header: 'Tipo', value: (a) => a.label, render: (a) => <Link to={`/activity/${a.id}`}>{a.label}</Link> },
                {
                  key: 'instance',
                  header: 'Instancia',
                  value: (a) => a.instance_id,
                  render: (a) =>
                    a.instance_id ? (
                      <Link to={`/instances/${a.instance_id}`}>
                        {cliById.get(allInstances.find((i) => i.id === a.instance_id)?.client_id ?? -1)?.name ??
                          a.instance_id}
                      </Link>
                    ) : (
                      '—'
                    ),
                },
                { key: 'message', header: 'Mensaje', value: (a) => a.message },
              ]}
              defaultSort={{ key: 'created_at', dir: 'desc' }}
              emptyTitle="Sin actividad"
              emptyBody="No hay eventos registrados para las entidades de esta cuenta."
            />
          )}
        </div>
      </DetailTabs>
    </>
  );
}
