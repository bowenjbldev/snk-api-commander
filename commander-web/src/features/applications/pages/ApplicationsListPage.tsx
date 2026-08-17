import { useNavigate } from 'react-router-dom';
import { Link } from '@shared/components/AppLink';
import { ENTITY_STATUS } from '@services/api/mock/commander-mock';
import type { Application } from '@services/api/types';
import { useResource } from '@shared/hooks/useResource';
import { pending } from '@shared/lib/data';
import { DataView } from '@shared/components/DataView';
import type { Column } from '@shared/components/DataView';
import { ListPage } from '@shared/components/ListPage';
import { StatusTag } from '@shared/components/StatusTag';
import { CODE_STATUS_CATALOG } from '../model/codeStatus';
import { useApplicationsService } from '../services/applicationsService';

export function ApplicationsListPage() {
  const applicationsService = useApplicationsService();
  const navigate = useNavigate();
  const res = useResource(() => applicationsService.listWithCounts(), []);
  const [applications, instances, plans] = pending(res.data, [[], [], []]);

  const columns: Column<Application>[] = [
    {
      key: 'name',
      header: 'Nombre',
      value: (a) => a.name,
      render: (a) => <Link to={`/applications/${a.id}`}>{a.name}</Link>,
    },
    { key: 'slug', header: 'Slug', value: (a) => a.slug, render: (a) => <span className="cmd-mono">{a.slug}</span> },
    {
      key: 'package_name',
      header: 'Package',
      value: (a) => a.package_name,
      render: (a) => <span className="cmd-mono">{a.package_name}</span>,
    },
    {
      key: 'status',
      header: 'Estado comercial',
      value: (a) => a.status,
      render: (a) => <StatusTag catalog={ENTITY_STATUS} value={a.status} />,
    },
    {
      key: 'code_status',
      header: 'Código',
      value: (a) => a.code_status,
      render: (a) => <StatusTag catalog={CODE_STATUS_CATALOG} value={a.code_status} />,
    },
    {
      key: 'instances',
      header: 'Instancias',
      align: 'right',
      value: (a) => instances.filter((i) => i.application_id === a.id).length,
    },
    {
      key: 'plans',
      header: 'Planes',
      align: 'right',
      value: (a) => plans.filter((p) => p.application_id === a.id).length,
    },
  ];

  return (
    <ListPage
      perm="index applications"
      title="Aplicaciones"
      lede="Catálogo code-first: las aplicaciones y sus métricas se descubren con commander:applications:sync, no se dan de alta desde aquí."
    >
      <DataView
        label="Aplicaciones"
        rows={applications}
        columns={columns}
        search={(a) => `${a.name} ${a.slug} ${a.package_name}`}
        defaultSort={{ key: 'name', dir: 'asc' }}
        loading={res.loading}
        refreshing={res.refreshing}
        error={res.error}
        onRetry={res.reload}
        rowActions={(a) => [
          { label: 'Abrir ficha', onClick: () => navigate(`/applications/${a.id}`) },
          { label: 'Planes de la aplicación', onClick: () => navigate(`/applications/${a.id}/plans`) },
        ]}
      />
    </ListPage>
  );
}
