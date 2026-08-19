import { useMemo } from 'react';
import {
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
import { useParams } from 'react-router-dom';
import { Link } from '@shared/components/AppLink';
import {
  ENTITY_STATUS,
  PROVISIONING,
  SERVICE,
  fmtMoney,
  fmtNum,
} from '@services/api/mock/commander-mock';
import { useApp } from '@app/provider';
import { useResource } from '@shared/hooks/useResource';
import { groupCount, lut, pending } from '@shared/lib/data';
import { PageHeader } from '@shared/components/PageHeader';
import { Defs, DetailTabs } from '@shared/components/Detail';
import { ChartBlock } from '@shared/components/charts';
import { StatusTag } from '@shared/components/StatusTag';
import { EmptyState, ErrorState, Forbidden } from '@shared/components/States';
import { summarizeLimits } from '@features/plans/model/planLimits';
import {
  APPLICATION_STATUS_CATALOG,
  CODE_STATUS_CATALOG,
  CODE_STATUS_HEADLINE,
} from '../model/codeStatus';
import { useApplicationsService } from '../services/applicationsService';

const APPLICATION_TABS = [
  { slug: 'summary', label: 'Resumen' },
  { slug: 'metrics', label: 'Métricas' },
  { slug: 'plans', label: 'Planes' },
  { slug: 'instances', label: 'Instancias' },
  { slug: 'code', label: 'Código' },
];

/** La tabla de una ficha no pagina: por encima de esto se sale al listado completo. */
const MAX_ROWS = 40;

const RIGHT = { textAlign: 'right' } as const;

export function ApplicationDetailPage() {
  const { can } = useApp();
  const applicationsService = useApplicationsService();
  const { id = '', tab = 'summary' } = useParams();

  const res = useResource(() => applicationsService.getWithContext(id), [id]);
  const [application, plans, instances, clients, accounts] = pending(res.data, [
    null,
    [],
    [],
    [],
    [],
  ]);
  const clientById = useMemo(() => lut(clients), [clients]);
  const accountById = useMemo(() => lut(accounts), [accounts]);
  const planById = useMemo(() => lut(plans), [plans]);
  // Una plantilla solo la usan instancias de su aplicación, así que agrupar el parque
  // entero por plan da el mismo recuento sin recorrerlo una vez por fila.
  const instancesByPlan = useMemo(
    () => groupCount(instances, (i) => String(i.plan_id)),
    [instances],
  );

  if (!can('show applications')) return <Forbidden perm="show applications" />;
  if (res.error) {
    return (
      <>
        <PageHeader title="Aplicación" crumbs={[{ label: 'Aplicaciones', to: '/applications' }]} />
        <ErrorState error={res.error} onRetry={res.reload} />
      </>
    );
  }
  if (res.loading || !application) return <SkeletonText paragraph lineCount={6} />;

  const appPlans = plans.filter((p) => p.application_id === application.id);
  const appInstances = instances.filter((i) => i.application_id === application.id);

  return (
    <>
      <PageHeader
        title={application.name}
        kicker={application.package_name}
        crumbs={[{ label: 'Aplicaciones', to: '/applications' }]}
        lede={application.description}
        statuses={
          <>
            <StatusTag catalog={APPLICATION_STATUS_CATALOG} value={application.status} />
            <StatusTag catalog={CODE_STATUS_HEADLINE} value={application.code_status} />
          </>
        }
      />

      <DetailTabs base={`/applications/${application.id}`} tabs={APPLICATION_TABS} current={tab}>
        <div className="cmd-stack">
          {tab === 'summary' && (
            <>
              {/* El aviso sube por encima del bloque: dice por qué esta ficha no tiene
                  botón de alta antes de que se busque. */}
              <Callout
                kind="info"
                lowContrast
                title="El alta y el borrado siguen en la CLI"
                subtitle="La aplicación se descubre con commander:applications:sync. El SPA no inventa un botón que la API no ofrece."
              />
              <ChartBlock
                title="Catálogo"
                help="Nombre, descripción y estado comercial se editan aquí. Slug, package, métricas y code_status proceden del código sincronizado."
              >
                <Defs
                  items={[
                    { label: 'Nombre', value: application.name, hint: 'editable' },
                    { label: 'Descripción', value: application.description, hint: 'editable' },
                    {
                      label: 'Estado comercial',
                      value: (
                        <StatusTag catalog={APPLICATION_STATUS_CATALOG} value={application.status} />
                      ),
                      hint: 'editable',
                    },
                    {
                      label: 'Slug',
                      value: <span className="cmd-mono">{application.slug}</span>,
                      hint: 'procede del código',
                    },
                    {
                      label: 'Package',
                      value: <span className="cmd-mono">{application.package_name}</span>,
                      hint: 'procede del código',
                    },
                    {
                      label: 'Disponibilidad de código',
                      value: (
                        <StatusTag catalog={CODE_STATUS_CATALOG} value={application.code_status} />
                      ),
                      hint: 'code_status sincronizado',
                    },
                    { label: 'Instancias', value: fmtNum(appInstances.length) },
                    { label: 'Planes', value: fmtNum(appPlans.length) },
                    { label: 'Métricas declaradas', value: fmtNum(application.metrics.length) },
                  ]}
                />
              </ChartBlock>
            </>
          )}

          {tab === 'metrics' && (
            <ChartBlock
              title="Métricas de la aplicación"
              help="users_total no sustituye a editorial_seats y los requests HTTP no sustituyen a lecturas. Un valor nulo significa «Sin medición», nunca cero."
            >
              <TableContainer className="cmd-table-scroll">
                <Table size="sm" aria-label="Métricas de la aplicación">
                  <TableHead>
                    <TableRow>
                      <TableHeader>Key</TableHeader>
                      <TableHeader>Nombre</TableHeader>
                      <TableHeader>Unidad</TableHeader>
                      <TableHeader>Ventana</TableHeader>
                      <TableHeader>Tipo</TableHeader>
                      <TableHeader>Medidor</TableHeader>
                      <TableHeader>Estado</TableHeader>
                    </TableRow>
                  </TableHead>
                  <TableBody>
                    {application.metrics.map((m) => (
                      <TableRow key={m.key}>
                        <TableCell className="cmd-mono cmd-dim">{m.key}</TableCell>
                        <TableCell>{m.name}</TableCell>
                        <TableCell className="cmd-dim">{m.unit}</TableCell>
                        <TableCell className="cmd-dim">{m.window}</TableCell>
                        <TableCell className="cmd-dim">{m.kind}</TableCell>
                        <TableCell className="cmd-dim">{m.meter}</TableCell>
                        <TableCell>
                          <StatusTag catalog={APPLICATION_STATUS_CATALOG} value={m.status} />
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </TableContainer>
            </ChartBlock>
          )}

          {tab === 'plans' &&
            (appPlans.length > 0 ? (
              <ChartBlock
                title="Planes de la aplicación"
                help="Plantillas de límites de esta aplicación. Modificar una no cambia las instancias ya aprovisionadas."
                linkTo={can('create plans') ? `/plans/new?app=${application.id}` : undefined}
                linkLabel={can('create plans') ? 'Crear plan' : undefined}
              >
                <TableContainer className="cmd-table-scroll">
                  <Table size="sm" aria-label="Planes de la aplicación">
                    <TableHead>
                      <TableRow>
                        <TableHeader>Plan</TableHeader>
                        <TableHeader>Slug</TableHeader>
                        <TableHeader style={RIGHT}>Precio</TableHeader>
                        <TableHeader>Estado</TableHeader>
                        <TableHeader>Resumen de límites</TableHeader>
                        <TableHeader style={RIGHT}>Instancias</TableHeader>
                      </TableRow>
                    </TableHead>
                    <TableBody>
                      {appPlans.map((p) => (
                        <TableRow key={p.id}>
                          <TableCell>
                            {/* Sin permiso de edición el nombre lleva al listado: el
                                formulario del plan es la única ficha que tiene. */}
                            <Link to={can('update plans') ? `/plans/${p.id}/edit` : '/plans'}>
                              {p.name}
                            </Link>
                          </TableCell>
                          <TableCell className="cmd-mono cmd-dim">{p.slug}</TableCell>
                          <TableCell style={RIGHT}>{fmtMoney(p.monthly_price, p.currency)}</TableCell>
                          <TableCell>
                            {/* El plan es masculino: aquí manda el catálogo común, no el
                                vocabulario propio de la aplicación. */}
                            <StatusTag catalog={ENTITY_STATUS} value={p.status} />
                          </TableCell>
                          <TableCell className="cmd-dim">{summarizeLimits(p.limits)}</TableCell>
                          <TableCell style={RIGHT}>
                            {fmtNum(instancesByPlan[String(p.id)] ?? 0)}
                          </TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </TableContainer>
              </ChartBlock>
            ) : (
              <EmptyState
                title="Sin planes"
                body="Una aplicación sin planes no puede aprovisionarse desde el catálogo."
              />
            ))}

          {tab === 'instances' &&
            (appInstances.length > 0 ? (
              <ChartBlock
                title="Clientes que ejecutan la aplicación"
                help="Quién tiene esta aplicación en marcha, con qué plan y en qué estado."
                summary={
                  appInstances.length > MAX_ROWS
                    ? `primeras ${MAX_ROWS} de ${fmtNum(appInstances.length)}`
                    : undefined
                }
                linkTo={`/instances?app=${application.id}`}
                linkLabel="Ver en instancias"
              >
                <TableContainer className="cmd-table-scroll">
                  <Table size="sm" aria-label="Clientes que ejecutan la aplicación">
                    <TableHead>
                      <TableRow>
                        <TableHeader>Cliente</TableHeader>
                        <TableHeader>Cuenta</TableHeader>
                        <TableHeader>Plan</TableHeader>
                        <TableHeader>Provisioning</TableHeader>
                        <TableHeader>Servicio</TableHeader>
                        <TableHeader style={RIGHT}>Requests 24 h</TableHeader>
                      </TableRow>
                    </TableHead>
                    <TableBody>
                      {appInstances.slice(0, MAX_ROWS).map((i) => (
                        <TableRow key={i.id}>
                          <TableCell>
                            <Link to={`/instances/${i.id}`}>
                              {clientById.get(i.client_id)?.name ?? '—'}
                            </Link>
                          </TableCell>
                          <TableCell>
                            <Link to={`/accounts/${i.account_id}`}>
                              {accountById.get(i.account_id)?.name ?? '—'}
                            </Link>
                          </TableCell>
                          <TableCell className="cmd-dim">
                            {(i.plan_id ? planById.get(i.plan_id)?.name : null) ?? 'Sin plan'}
                          </TableCell>
                          <TableCell>
                            <StatusTag catalog={PROVISIONING} value={i.provisioning_status} />
                          </TableCell>
                          <TableCell>
                            <StatusTag catalog={SERVICE} value={i.service_status} />
                          </TableCell>
                          <TableCell style={RIGHT}>{fmtNum(i.requests_24h)}</TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </TableContainer>
              </ChartBlock>
            ) : (
              <EmptyState title="Sin instancias" body="Ningún cliente ejecuta esta aplicación." />
            ))}

          {tab === 'code' &&
            (application.code_status === 'available' ? (
              // La actividad diaria y los commits del package salen de
              // GET /applications/:id/insights, que este cliente todavía no tiene:
              // se dice que falta el dato en vez de dibujar un historial inventado.
              <EmptyState
                title="Sin actividad de código"
                body={`GET /applications/${application.id}/insights no forma parte todavía del contrato que consume el SPA: la actividad diaria y los últimos commits del package aparecerán aquí cuando lo haga.`}
              />
            ) : (
              <Callout
                kind="warning"
                lowContrast
                title="Código ausente"
                subtitle={`GET /applications/${application.id}/insights no devuelve actividad porque el package no está presente en el servidor.`}
              />
            ))}
        </div>
      </DetailTabs>
    </>
  );
}
