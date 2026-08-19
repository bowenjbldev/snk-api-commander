import { useMemo } from 'react';
import { Link } from '@shared/components/AppLink';
import { fmtNum } from '@services/api/mock/commander-mock';
import type { Application } from '@services/api/types';
import { useResource } from '@shared/hooks/useResource';
import { groupCount, pending } from '@shared/lib/data';
import { DataView } from '@shared/components/DataView';
import type { Column, FilterDef } from '@shared/components/DataView';
import { ListPage } from '@shared/components/ListPage';
import { StatusTag } from '@shared/components/StatusTag';
import { APPLICATION_STATUS_CATALOG, CODE_STATUS_CATALOG } from '../model/codeStatus';
import { useApplicationsService } from '../services/applicationsService';

const FILTERS: FilterDef<Application>[] = [
  {
    id: 'status',
    label: 'Estado',
    options: [
      { value: 'active', label: 'Activa' },
      { value: 'inactive', label: 'Inactiva' },
    ],
    test: (a, value) => a.status === value,
  },
  {
    id: 'code',
    label: 'Código',
    options: [
      { value: 'available', label: 'Disponible' },
      { value: 'missing', label: 'Ausente' },
    ],
    test: (a, value) => a.code_status === value,
  },
];

export function ApplicationsListPage() {
  const applicationsService = useApplicationsService();
  const res = useResource(() => applicationsService.listWithCounts(), []);
  const [applications, instances, plans] = pending(res.data, [[], [], []]);

  // Los dos conteos se agrupan una vez: recorrer el parque entero por fila y por
  // pintado es el mismo dato calculado tantas veces como filas haya.
  const byApplication = useMemo(
    () => ({
      instances: groupCount(instances, (i) => String(i.application_id)),
      plans: groupCount(plans, (p) => String(p.application_id)),
    }),
    [instances, plans],
  );

  const columns: Column<Application>[] = [
    {
      key: 'name',
      header: 'Nombre',
      value: (a) => a.name,
      render: (a) => <Link to={`/applications/${a.id}`}>{a.name}</Link>,
      sub: (a) => a.description,
    },
    {
      key: 'slug',
      header: 'Slug',
      value: (a) => a.slug,
      render: (a) => <span className="cmd-mono cmd-dim">{a.slug}</span>,
    },
    {
      key: 'pkg',
      header: 'Package',
      value: (a) => a.package_name,
      render: (a) => <span className="cmd-mono cmd-dim">{a.package_name}</span>,
    },
    {
      key: 'status',
      header: 'Estado',
      value: (a) => a.status,
      render: (a) => <StatusTag catalog={APPLICATION_STATUS_CATALOG} value={a.status} />,
    },
    {
      key: 'code',
      header: 'Código',
      value: (a) => a.code_status,
      render: (a) => <StatusTag catalog={CODE_STATUS_CATALOG} value={a.code_status} />,
    },
    {
      key: 'inst',
      header: 'Instancias',
      align: 'right',
      value: (a) => byApplication.instances[String(a.id)] ?? 0,
      render: (a) => fmtNum(byApplication.instances[String(a.id)] ?? 0),
    },
    {
      key: 'plans',
      header: 'Planes',
      align: 'right',
      value: (a) => byApplication.plans[String(a.id)] ?? 0,
      render: (a) => fmtNum(byApplication.plans[String(a.id)] ?? 0),
    },
  ];

  return (
    <ListPage
      perm="index applications"
      title="Aplicaciones"
      lede="El catálogo se descubre con commander:applications:sync. Desde el SPA solo se editan nombre, descripción y estado comercial."
    >
      <DataView
        label="Aplicaciones"
        rows={applications}
        columns={columns}
        search={(a) => `${a.name} ${a.slug} ${a.package_name}`}
        searchHint="Nombre, slug o package"
        filters={FILTERS}
        loading={res.loading}
        refreshing={res.refreshing}
        error={res.error}
        onRetry={res.reload}
      />
    </ListPage>
  );
}
