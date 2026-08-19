import { useMemo, useState } from 'react';
import {
  Button,
  Callout,
  InlineNotification,
  OverflowMenu,
  OverflowMenuItem,
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
  METRICS,
  PROVISIONING,
  SERVICE,
  fmtDateTime,
  fmtMoney,
  fmtNum,
  fmtRel,
} from '@services/api/mock/commander-mock';
import type { Instance } from '@services/api/types';
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
import { worstQuotaByInstance } from '@features/quotas/model/quotaHealth';
import {
  ACTION_LABEL,
  ACTION_PERM,
  actionDialog,
  availableActions,
} from '@features/instances/model/instanceActions';
import type { InstanceAction } from '@features/instances/model/instanceActions';
import { useInstancesService } from '@features/instances/services/instancesService';
import { useClientsService } from '../services/clientsService';

const CLIENT_TABS = [
  { slug: 'summary', label: 'Resumen' },
  { slug: 'instances', label: 'Instancias' },
];

const RIGHT = { textAlign: 'right' } as const;

export function ClientDetailPage() {
  const { can, store } = useApp();
  const clientsService = useClientsService();
  const instancesService = useInstancesService();
  const { id = '', tab = 'summary' } = useParams();
  const navigate = useNavigate();
  const [dialog, setDialog] = useState<{ action: InstanceAction; instance: Instance } | null>(null);
  const [flash, setFlash] = useState<string | null>(null);

  const res = useResource(() => clientsService.getWithContext(id), [id]);
  const [client, accounts, allInstances, applications, plans, quotas] = pending(res.data, [
    null,
    [],
    [],
    [],
    [],
    [],
  ]);
  const appById = useMemo(() => lut(applications), [applications]);
  const planById = useMemo(() => lut(plans), [plans]);
  // La cuota más alta solo cuenta si hay límite contra el que medir y medición que comparar:
  // sin una de las dos no hay porcentaje y la celda queda en un guion.
  const worstQuota = useMemo(
    () => worstQuotaByInstance(quotas.filter((q) => q.limit_value !== null && q.current_value !== null)),
    [quotas],
  );

  if (!can('show clients')) return <Forbidden perm="show clients" />;
  // Un identificador que no está en los datos cargados no es un fallo que se arregle
  // repitiendo la petición: tiene página propia y dice por qué puede faltar.
  if (res.error?.status === 404) {
    return (
      <PageHeader
        title="No encontrado"
        kicker="404"
        crumbs={[{ label: 'Clientes', to: '/clients' }]}
        lede={`El cliente ${id} no está en los datos cargados. No se asume que se haya borrado: puede tratarse de un identificador de otra sesión.`}
      />
    );
  }
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
  const provisioningTo = `/provisioning/new?client=${client.id}`;
  const spec =
    dialog &&
    actionDialog(dialog.action, dialog.instance, {
      clientName: client.name,
      clientSlug: client.slug,
      graceHours: store.settings.default_grace_hours,
      currentPlan: (dialog.instance.plan_id ? planById.get(dialog.instance.plan_id)?.name : null) ?? '—',
      planOptions: plans
        .filter(
          (p) =>
            p.application_id === dialog.instance.application_id &&
            p.status === 'active' &&
            p.id !== dialog.instance.plan_id,
        )
        .map((p) => ({ value: String(p.id), label: `${p.name} · ${fmtMoney(p.monthly_price, p.currency)}` })),
    });

  return (
    <>
      <PageHeader
        title={client.name}
        kicker={`tenant ID ${client.id}`}
        crumbs={[
          { label: 'Clientes', to: '/clients' },
          { label: account?.name ?? '—', to: `/accounts/${client.account_id}` },
        ]}
        lede={`${client.slug}.commander.app · ${client.email}`}
        statuses={<StatusTag catalog={ENTITY_STATUS} value={client.status} />}
        actions={
          <>
            {can('update clients') && (
              <Button onClick={() => navigate(`/clients/${client.id}/edit`)}>Editar cliente</Button>
            )}
            {can('provision instances') && (
              <Button kind="tertiary" onClick={() => navigate(provisioningTo)}>
                Añadir aplicación
              </Button>
            )}
          </>
        }
      />

      {flash && (
        <InlineNotification
          kind="success"
          lowContrast
          title="Acción aplicada"
          subtitle={flash}
          onClose={() => setFlash(null)}
        />
      )}

      <DetailTabs base={`/clients/${client.id}`} tabs={CLIENT_TABS} current={tab}>
        <div className="cmd-stack">
          {tab === 'summary' && (
            <>
              {/* Los avisos suben por encima de los bloques, como en el resto de fichas:
                  acotan qué NO administra Commander antes de que se lea el registro. */}
              <Callout
                kind="info"
                lowContrast
                title="Sin pestañas de usuarios, roles ni contenido"
                subtitle="Commander solo conserva el administrador inicial enviado durante provisioning. El resto de los usuarios editoriales vive en la instancia."
              />
              {instances.length > 0 && (
                <Callout
                  kind="info"
                  lowContrast
                  title="No se ofrece borrar este cliente"
                  subtitle={`Tiene ${instances.length} instancias. Un cliente con historia se desactiva.`}
                />
              )}
              <div className="cmd-panels">
                <ChartBlock
                  title="Identidad"
                  help="Los datos fijos del registro. Lo que queda inmutable tras el alta se marca como tal; el resto se edita desde su formulario."
                >
                  <Defs
                    items={[
                      { label: 'Estado', value: <StatusTag catalog={ENTITY_STATUS} value={client.status} /> },
                      {
                        label: 'Cuenta pagadora',
                        value: <Link to={`/accounts/${client.account_id}`}>{account?.name ?? '—'}</Link>,
                      },
                      {
                        label: 'Slug',
                        value: <span className="cmd-mono">{client.slug}</span>,
                        hint: 'inmutable tras el alta',
                      },
                      {
                        label: 'Subdominio técnico',
                        value: <span className="cmd-mono">{client.slug}.commander.app</span>,
                      },
                      { label: 'Correo de contacto', value: client.email },
                      { label: 'Alta', value: fmtDateTime(client.created_at) },
                      {
                        label: 'Actualización',
                        value: fmtDateTime(client.updated_at),
                        hint: fmtRel(client.updated_at),
                      },
                    ]}
                  />
                </ChartBlock>
                {client.notes && (
                  <ChartBlock
                    title="Notas"
                    help="Solo las ve el operador. No se envían al tenant ni aparecen en su instancia."
                  >
                    <Defs items={[{ label: 'Nota', value: client.notes }]} />
                  </ChartBlock>
                )}
              </div>
            </>
          )}

          {tab === 'instances' &&
            (instances.length > 0 ? (
              <ChartBlock
                title="Una fila por aplicación"
                help="Una instancia por cada aplicación que ejecuta el cliente, con su plan, su contrato y su consumo."
                linkTo={can('provision instances') ? provisioningTo : undefined}
                linkLabel={can('provision instances') ? 'Añadir aplicación' : undefined}
              >
                <TableContainer className="cmd-table-scroll">
                  <Table size="sm" aria-label="Instancias del cliente">
                    <TableHead>
                      <TableRow>
                        <TableHeader>Aplicación</TableHeader>
                        <TableHeader>Plan</TableHeader>
                        <TableHeader>Contrato</TableHeader>
                        <TableHeader>Provisioning</TableHeader>
                        <TableHeader>Servicio</TableHeader>
                        <TableHeader>Cuota más alta</TableHeader>
                        <TableHeader style={RIGHT}>Requests 24 h</TableHeader>
                        <TableHeader aria-label="Acciones de fila" />
                      </TableRow>
                    </TableHead>
                    <TableBody>
                      {instances.map((i) => {
                        const quota = worstQuota.get(i.id);
                        return (
                          <TableRow key={i.id}>
                            <TableCell>
                              <Link to={`/instances/${i.id}`}>{appById.get(i.application_id)?.name ?? '—'}</Link>
                            </TableCell>
                            <TableCell className="cmd-dim">
                              {(i.plan_id && planById.get(i.plan_id)?.name) || 'Sin plan'}
                            </TableCell>
                            <TableCell>
                              {i.subscription_id ? (
                                <Link to={`/subscriptions/${i.subscription_id}`}>#{i.subscription_id}</Link>
                              ) : (
                                <span className="cmd-dim">—</span>
                              )}
                            </TableCell>
                            <TableCell>
                              <StatusTag catalog={PROVISIONING} value={i.provisioning_status} />
                            </TableCell>
                            <TableCell>
                              <StatusTag catalog={SERVICE} value={i.service_status} />
                            </TableCell>
                            <TableCell>
                              {quota ? (
                                <Meter
                                  value={quota.current_value}
                                  limit={quota.limit_value}
                                  caption={METRICS[quota.metric_key]?.name ?? quota.metric_key}
                                />
                              ) : (
                                <span className="cmd-dim">—</span>
                              )}
                            </TableCell>
                            <TableCell style={RIGHT}>{fmtNum(i.requests_24h)}</TableCell>
                            {/* El mismo menú que el listado de instancias: operar desde aquí ahorra
                                el rodeo por la ficha, y los diálogos ya viven en `instanceActions`. */}
                            <TableCell className="cds--table-column-menu">
                              <OverflowMenu size="sm" flipped aria-label="Acciones de la instancia">
                                <OverflowMenuItem
                                  itemText="Abrir ficha"
                                  onClick={() => navigate(`/instances/${i.id}`)}
                                />
                                {availableActions(i)
                                  .filter((action) => can(ACTION_PERM[action]))
                                  .map((action) => (
                                    <OverflowMenuItem
                                      key={action}
                                      itemText={ACTION_LABEL[action]}
                                      isDelete={action === 'deprovision'}
                                      hasDivider={action === 'deprovision'}
                                      onClick={() => setDialog({ action, instance: i })}
                                    />
                                  ))}
                              </OverflowMenu>
                            </TableCell>
                          </TableRow>
                        );
                      })}
                    </TableBody>
                  </Table>
                </TableContainer>
              </ChartBlock>
            ) : (
              <ChartBlock title="Instancias">
                <EmptyState
                  title="Sin instancias"
                  body="Este cliente no ejecuta ninguna aplicación todavía."
                />
              </ChartBlock>
            ))}
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
            await instancesService.run(dialog.instance.id, dialog.action, data);
            res.reload();
            setFlash(spec.done);
          }}
        />
      )}
    </>
  );
}
