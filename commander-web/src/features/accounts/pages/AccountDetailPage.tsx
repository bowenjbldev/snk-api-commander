import { useMemo } from 'react';
import {
  Button,
  Callout,
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
  ENTITY_STATUS,
  INVOICE_STATUS,
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
import { Defs, DetailTabs } from '@shared/components/Detail';
import { ChartBlock } from '@shared/components/charts';
import { KpiGrid } from '@shared/components/KpiGrid';
import { StatusTag } from '@shared/components/StatusTag';
import { EmptyState, ErrorState, Forbidden } from '@shared/components/States';
import { SUBSCRIPTION_MODE_CATALOG } from '@features/subscriptions/model/subscriptionColumns';
import {
  contractualMrr,
  deletionBlockers,
  healthyInstances,
  troubledInstances,
  unpaidInvoices,
} from '../model/accountTotals';
import { useAccountsService } from '../services/accountsService';

const RIGHT = { textAlign: 'right' } as const;

/** La misma frase con y sin contratos: el bloque se explica aunque esté vacío. */
const CONTRACTS_HELP =
  'Cada contrato fija modalidad, precio y capacidad. El importe pactado no se recalcula al cambiar el plan de una cabecera.';

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
  const [account, allClients, allSubs, allItems, allInvoices, allInstances, applications, allActivity] =
    pending(res.data, [null, [], [], [], [], [], [], []]);
  const appById = useMemo(() => lut(applications), [applications]);

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
  const activity = allActivity.filter((a) => a.account_id === account.id).slice(0, 30);

  const unpaidTotal = unpaidInvoices(invoices).reduce((total, i) => total + i.total, 0);
  const healthy = healthyInstances(instances);
  const troubled = troubledInstances(instances);
  const blockers = deletionBlockers(clients, subs, invoices);
  const health = instances.length
    ? `${healthy.length} de ${instances.length} instancias sirven tráfico sin incidencia.`
    : 'La cuenta no tiene instancias.';

  return (
    <>
      <PageHeader
        title={account.name}
        kicker={`Cuenta · ID ${account.id}`}
        crumbs={[{ label: 'Cuentas', to: '/accounts' }]}
        lede={`${account.email} · ${account.payment_terms} · ${account.tax_id}`}
        statuses={<StatusTag catalog={ENTITY_STATUS} value={account.status} />}
        actions={
          <>
            {can('update accounts') && (
              <Button onClick={() => navigate(`/accounts/${account.id}/edit`)}>Editar cuenta</Button>
            )}
            {can('provision instances') && (
              <Button kind="tertiary" onClick={() => navigate(`/provisioning/new?account=${account.id}`)}>
                Nueva alta
              </Button>
            )}
          </>
        }
      />

      <DetailTabs base={`/accounts/${account.id}`} tabs={ACCOUNT_TABS} current={tab}>
        <div className="cmd-stack">
          {tab === 'summary' && (
            <>
              <KpiGrid
                items={[
                  {
                    label: 'MRR contractual',
                    value: fmtMoney(contractualMrr(subs)),
                    note: 'contratos active y trialing',
                  },
                  {
                    label: 'Facturación pendiente',
                    value: fmtMoney(unpaidTotal),
                    note: `${invoices.filter((i) => i.status === 'overdue').length} vencidas`,
                    tone: unpaidTotal > 0 ? 'warn' : undefined,
                  },
                  {
                    label: 'Clientes',
                    value: fmtNum(clients.length),
                    note: `${clients.filter((c) => c.status === 'active').length} activos`,
                  },
                  {
                    label: 'Instancias con incidencia',
                    value: fmtNum(troubled.length),
                    note: `de ${instances.length}`,
                    tone: troubled.length > 0 ? 'crit' : undefined,
                  },
                ]}
              />

              {/* Los avisos van tras los indicadores: las cifras son el estado y el aviso
                  explica lo que se acaba de leer. */}
              <Callout
                kind={troubled.length ? 'warning' : 'success'}
                lowContrast
                title="Salud de sus clientes"
                subtitle={`${health}${troubled.length ? ` ${troubled.length} requieren atención.` : ''}`}
              />
              {blockers.length > 0 && (
                <Callout
                  kind="info"
                  lowContrast
                  title="No se ofrece borrar esta cuenta"
                  subtitle={`Tiene ${blockers.join(', ')}. Lo que corresponde es desactivarla y conservar la historia.`}
                />
              )}

              <div className="cmd-panels">
                <ChartBlock
                  title="Contacto y facturación"
                  help="A quién se factura y con qué condiciones. La cuenta es el pagador: no contiene datos de negocio de ningún tenant."
                >
                  <Defs
                    items={[
                      { label: 'Estado', value: <StatusTag catalog={ENTITY_STATUS} value={account.status} /> },
                      { label: 'Correo', value: account.email },
                      { label: 'Correo de facturación', value: account.billing_email },
                      { label: 'NIF', value: <span className="cmd-mono">{account.tax_id}</span> },
                      { label: 'Condiciones de pago', value: account.payment_terms },
                      { label: 'Teléfono', value: <span className="cmd-mono">{account.phone}</span> },
                      { label: 'Dirección', value: account.address },
                      { label: 'País', value: account.country },
                      {
                        label: 'Alta',
                        value: fmtDateTime(account.created_at),
                        hint: fmtRel(account.created_at),
                      },
                    ]}
                  />
                </ChartBlock>

                {account.notes && (
                  <ChartBlock
                    title="Notas internas"
                    help="Solo las ve el operador. No se envían al tenant ni aparecen en su instancia."
                  >
                    <Defs items={[{ label: 'Nota', value: account.notes }]} />
                  </ChartBlock>
                )}
              </div>
            </>
          )}

          {tab === 'clients' &&
            (clients.length ? (
              <ChartBlock
                title="Tenants de la cuenta"
                help="Los clientes que paga esta cuenta, con su subdominio y cuántas instancias ejecuta cada uno."
                linkTo={can('create clients') ? `/clients/new?account=${account.id}` : undefined}
                linkLabel="Añadir cliente"
              >
                <TableContainer className="cmd-table-scroll">
                  <Table size="sm" aria-label="Tenants de la cuenta">
                    <TableHead>
                      <TableRow>
                        <TableHeader>Cliente</TableHeader>
                        <TableHeader>Subdominio</TableHeader>
                        <TableHeader>Estado</TableHeader>
                        <TableHeader style={RIGHT}>Instancias</TableHeader>
                        <TableHeader>Alta</TableHeader>
                      </TableRow>
                    </TableHead>
                    <TableBody>
                      {clients.map((c) => (
                        <TableRow key={c.id}>
                          <TableCell>
                            <Link to={`/clients/${c.id}`}>{c.name}</Link>
                          </TableCell>
                          <TableCell className="cmd-mono cmd-dim">{c.slug}</TableCell>
                          <TableCell>
                            <StatusTag catalog={ENTITY_STATUS} value={c.status} />
                          </TableCell>
                          <TableCell style={RIGHT}>
                            {fmtNum(allInstances.filter((i) => i.client_id === c.id).length)}
                          </TableCell>
                          <TableCell className="cmd-dim">{fmtDate(c.created_at)}</TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </TableContainer>
              </ChartBlock>
            ) : (
              <EmptyBlock
                head="Clientes"
                title="Sin clientes"
                body="Una cuenta puede existir antes de tener tenants: es el paso previo a un contrato negociado."
              />
            ))}

          {tab === 'subscriptions' &&
            (subs.length ? (
              <ChartBlock title="Contratos" help={CONTRACTS_HELP}>
                <TableContainer className="cmd-table-scroll">
                  <Table size="sm" aria-label="Contratos de la cuenta">
                    <TableHead>
                      <TableRow>
                        <TableHeader>Contrato</TableHeader>
                        <TableHeader>Aplicación</TableHeader>
                        <TableHeader>Modalidad</TableHeader>
                        <TableHeader>Estado</TableHeader>
                        <TableHeader style={RIGHT}>Importe</TableHeader>
                        <TableHeader style={RIGHT}>Capacidad</TableHeader>
                        <TableHeader>Inicio</TableHeader>
                      </TableRow>
                    </TableHead>
                    <TableBody>
                      {subs.map((s) => (
                        <TableRow key={s.id}>
                          <TableCell>
                            <Link to={`/subscriptions/${s.id}`}>{s.name}</Link>
                          </TableCell>
                          <TableCell className="cmd-dim">{appById.get(s.application_id)?.name ?? '—'}</TableCell>
                          <TableCell>
                            <StatusTag catalog={SUBSCRIPTION_MODE_CATALOG} value={s.mode} />
                          </TableCell>
                          <TableCell>
                            <StatusTag catalog={SUB_STATUS} value={s.status} />
                          </TableCell>
                          <TableCell style={RIGHT}>{fmtMoney(s.monthly_amount, s.currency)}</TableCell>
                          {/* Capacidad es la fracción: las cabeceras activas frente al máximo pactado. */}
                          <TableCell className="cmd-dim" style={RIGHT}>
                            {`${allItems.filter((it) => it.subscription_id === s.id && it.status === 'active').length} / ${s.max_instances}`}
                          </TableCell>
                          <TableCell className="cmd-dim">{fmtDate(s.starts_at)}</TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </TableContainer>
              </ChartBlock>
            ) : (
              <EmptyBlock
                head="Contratos"
                help={CONTRACTS_HELP}
                title="Sin contratos"
                body="El alta normal crea un contrato de catálogo; uno negociado se crea desde Contratos."
              />
            ))}

          {tab === 'invoices' &&
            (invoices.length ? (
              <ChartBlock
                title="Ledger de la cuenta"
                help="Facturas emitidas a esta cuenta. Resaltar una vencida no altera el servicio por sí solo."
                summary={`${fmtMoney(unpaidTotal)} pendientes de cobro`}
              >
                <TableContainer className="cmd-table-scroll">
                  <Table size="sm" aria-label="Ledger de la cuenta">
                    <TableHead>
                      <TableRow>
                        <TableHeader>Número</TableHeader>
                        <TableHeader>Estado</TableHeader>
                        <TableHeader>Emisión</TableHeader>
                        <TableHeader>Vencimiento</TableHeader>
                        <TableHeader style={RIGHT}>Total</TableHeader>
                      </TableRow>
                    </TableHead>
                    <TableBody>
                      {invoices.map((i) => (
                        <TableRow key={i.id}>
                          <TableCell>
                            <Link to={`/invoices/${i.id}`}>{i.number}</Link>
                          </TableCell>
                          <TableCell>
                            <StatusTag catalog={INVOICE_STATUS} value={i.status} />
                          </TableCell>
                          <TableCell className="cmd-dim">{fmtDate(i.issued_at)}</TableCell>
                          {/* El vencimiento de una vencida se lee en rojo: es el dato que la explica. */}
                          <TableCell className={i.status === 'overdue' ? 'cmd-text-error' : 'cmd-dim'}>
                            {fmtDate(i.due_at)}
                          </TableCell>
                          <TableCell style={RIGHT}>{fmtMoney(i.total, i.currency)}</TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </TableContainer>
              </ChartBlock>
            ) : (
              <EmptyBlock head="Facturas" title="Sin facturas" body="No hay ledger para esta cuenta todavía." />
            ))}

          {tab === 'activity' &&
            (activity.length ? (
              <ChartBlock
                title="Eventos de sus instancias, contratos y facturas"
                help="Auditoría operativa de todo lo que cuelga de la cuenta, no un sistema de tareas."
              >
                <TableContainer className="cmd-table-scroll">
                  <Table size="sm" aria-label="Actividad de la cuenta">
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
                      {activity.map((a) => (
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
              <EmptyBlock
                head="Actividad"
                title="Sin actividad"
                body="Cuando el endpoint emita eventos de esta cuenta aparecerán aquí."
              />
            ))}
        </div>
      </DetailTabs>
    </>
  );
}

/**
 * Un bloque vacío es su título fuera de la caja y el motivo dentro. `ChartBlock`
 * no sirve: su cuerpo ya trae fondo y relleno, y encajaría una caja dentro de otra.
 */
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
