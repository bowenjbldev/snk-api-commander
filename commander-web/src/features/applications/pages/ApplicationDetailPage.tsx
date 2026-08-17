import { useMemo } from 'react';
import { SkeletonText } from '@carbon/react';
import { useParams } from 'react-router-dom';
import { Link } from '@shared/components/AppLink';
import { ENTITY_STATUS, PROVISIONING, SERVICE, fmtMoney, fmtNum } from '@services/api/mock/commander-mock';
import { useApp } from '@app/provider';
import { useResource } from '@shared/hooks/useResource';
import { lut, pending } from '@shared/lib/data';
import { PageHeader } from '@shared/components/PageHeader';
import { Defs, DetailTabs, Panel } from '@shared/components/Detail';
import { DataView } from '@shared/components/DataView';
import { StatusTag } from '@shared/components/StatusTag';
import { ErrorState, Forbidden } from '@shared/components/States';
import { summarizeLimits } from '@features/plans/model/planLimits';
import { CODE_STATUS_CATALOG } from '../model/codeStatus';
import { useApplicationsService } from '../services/applicationsService';

const APPLICATION_TABS = [
  { slug: 'summary', label: 'Resumen' },
  { slug: 'metrics', label: 'Métricas' },
  { slug: 'plans', label: 'Planes' },
  { slug: 'instances', label: 'Instancias' },
  { slug: 'code', label: 'Código' },
];

export function ApplicationDetailPage() {
  const { can } = useApp();
  const applicationsService = useApplicationsService();
  const { id = '', tab = 'summary' } = useParams();

  const res = useResource(() => applicationsService.getWithContext(id), [id]);
  const [application, plans, instances, clients] = pending(res.data, [null, [], [], []]);
  const cliById = useMemo(() => lut(clients), [clients]);

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
        crumbs={[{ label: 'Aplicaciones', to: '/applications' }, { label: application.name }]}
        lede={
          <>
            <StatusTag catalog={ENTITY_STATUS} value={application.status} />{' '}
            <span className="cmd-mono">{application.package_name}</span>
          </>
        }
      />

      <DetailTabs base={`/applications/${application.id}`} tabs={APPLICATION_TABS} current={tab}>
        <div className="cmd-stack">
          {tab === 'summary' && (
            <div className="cmd-panels">
              <Panel title="Identidad">
                <Defs
                  items={[
                    { label: 'Nombre', value: application.name },
                    { label: 'Slug', value: <span className="cmd-mono">{application.slug}</span> },
                    { label: 'Package', value: <span className="cmd-mono">{application.package_name}</span> },
                    { label: 'Descripción', value: application.description },
                  ]}
                />
                <p className="cmd-meter__caption">
                  Solo nombre, descripción y estado son editables. Slug, package, métricas y estado del código proceden
                  del código sincronizado con commander:applications:sync.
                </p>
              </Panel>
              <Panel title="Conteos">
                <Defs
                  items={[
                    { label: 'Métricas declaradas', value: fmtNum(application.metrics.length) },
                    { label: 'Planes', value: fmtNum(appPlans.length) },
                    { label: 'Instancias', value: fmtNum(appInstances.length) },
                    {
                      label: 'Listas',
                      value: fmtNum(appInstances.filter((i) => i.provisioning_status === 'ready').length),
                    },
                  ]}
                />
              </Panel>
            </div>
          )}

          {tab === 'metrics' && (
            <DataView
              label="Métricas de la aplicación"
              rows={application.metrics.map((m, index) => ({ ...m, id: index }))}
              columns={[
                { key: 'key', header: 'Key', value: (m) => m.key, render: (m) => <span className="cmd-mono">{m.key}</span> },
                { key: 'name', header: 'Nombre', value: (m) => m.name },
                { key: 'unit', header: 'Unidad', value: (m) => m.unit },
                { key: 'window', header: 'Ventana', value: (m) => m.window },
                { key: 'kind', header: 'Tipo', value: (m) => m.kind },
                { key: 'meter', header: 'Medidor', value: (m) => m.meter },
              ]}
            />
          )}

          {tab === 'plans' && (
            <DataView
              label="Planes de la aplicación"
              rows={appPlans}
              columns={[
                { key: 'name', header: 'Nombre', value: (p) => p.name, render: (p) => <Link to={`/plans/${p.id}/edit`}>{p.name}</Link> },
                {
                  key: 'monthly_price',
                  header: 'Precio',
                  align: 'right',
                  value: (p) => p.monthly_price,
                  render: (p) => fmtMoney(p.monthly_price, p.currency),
                },
                { key: 'status', header: 'Estado', value: (p) => p.status, render: (p) => <StatusTag catalog={ENTITY_STATUS} value={p.status} /> },
                { key: 'limits', header: 'Límites', render: (p) => summarizeLimits(p.limits) },
              ]}
              emptyTitle="Sin planes"
              emptyBody="Esta aplicación no tiene todavía ninguna plantilla de límites."
            />
          )}

          {tab === 'instances' && (
            <DataView
              label="Instancias de la aplicación"
              rows={appInstances}
              columns={[
                {
                  key: 'client',
                  header: 'Cliente',
                  value: (i) => cliById.get(i.client_id)?.name ?? '',
                  render: (i) => <Link to={`/instances/${i.id}`}>{cliById.get(i.client_id)?.name ?? '—'}</Link>,
                },
                {
                  key: 'provisioning',
                  header: 'Provisioning',
                  value: (i) => i.provisioning_status,
                  render: (i) => <StatusTag catalog={PROVISIONING} value={i.provisioning_status} />,
                },
                { key: 'service', header: 'Servicio', value: (i) => i.service_status, render: (i) => <StatusTag catalog={SERVICE} value={i.service_status} /> },
                { key: 'requests_24h', header: 'Requests 24 h', align: 'right', value: (i) => i.requests_24h, render: (i) => fmtNum(i.requests_24h) },
              ]}
              emptyTitle="Sin instancias"
              emptyBody="Ningún cliente ejecuta esta aplicación."
            />
          )}

          {tab === 'code' && (
            <Panel title="Estado del código">
              <Defs
                items={[
                  { label: 'Código', value: <StatusTag catalog={CODE_STATUS_CATALOG} value={application.code_status} /> },
                  { label: 'Package', value: <span className="cmd-mono">{application.package_name}</span> },
                ]}
              />
              <p className="cmd-meter__caption">
                La actividad diaria y los últimos commits vienen de GET /applications/:id/insights. Este prototipo
                trabaja sobre datos simulados y no inventa un historial de repositorio que no existe.
              </p>
            </Panel>
          )}
        </div>
      </DetailTabs>
    </>
  );
}
