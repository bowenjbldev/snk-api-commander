import { useMemo } from 'react';
import { Button } from '@carbon/react';
import { Add } from '@carbon/icons-react';
import { useNavigate } from 'react-router-dom';
import { Link } from '@shared/components/AppLink';
import { ENTITY_STATUS, fmtMoney } from '@services/api/mock/commander-mock';
import type { Plan } from '@services/api/types';
import { useApp } from '@app/provider';
import { useResource } from '@shared/hooks/useResource';
import { lut, pending } from '@shared/lib/data';
import { DataView } from '@shared/components/DataView';
import type { Column, FilterDef } from '@shared/components/DataView';
import { ListPage } from '@shared/components/ListPage';
import { StatusTag } from '@shared/components/StatusTag';
import { summarizeLimits } from '../model/planLimits';
import { PLAN_PRICE_BANDS, matchesPriceBand } from '../model/priceBands';
import { usePlansService } from '../services/plansService';

export function PlansListPage() {
  const { can } = useApp();
  const plansService = usePlansService();
  const navigate = useNavigate();
  const res = useResource(() => plansService.listWithApplications(), []);
  const [plans, applications] = pending(res.data, [[], []]);
  const appById = useMemo(() => lut(applications), [applications]);

  const columns: Column<Plan>[] = [
    {
      key: 'application',
      header: 'Aplicación',
      value: (p) => appById.get(p.application_id)?.name ?? '',
      render: (p) => <Link to={`/applications/${p.application_id}`}>{appById.get(p.application_id)?.name ?? '—'}</Link>,
    },
    { key: 'name', header: 'Nombre', value: (p) => p.name, render: (p) => <Link to={`/plans/${p.id}/edit`}>{p.name}</Link> },
    { key: 'slug', header: 'Slug', value: (p) => p.slug, render: (p) => <span className="cmd-mono">{p.slug}</span> },
    {
      key: 'monthly_price',
      header: 'Precio mensual',
      align: 'right',
      value: (p) => p.monthly_price,
      render: (p) => fmtMoney(p.monthly_price, p.currency),
    },
    { key: 'status', header: 'Estado', value: (p) => p.status, render: (p) => <StatusTag catalog={ENTITY_STATUS} value={p.status} /> },
    { key: 'limits', header: 'Límites', render: (p) => summarizeLimits(p.limits) },
  ];

  const filters: FilterDef<Plan>[] = [
    {
      id: 'application',
      label: 'Aplicación',
      options: applications.map((a) => ({ value: String(a.id), label: a.name })),
      test: (p, v) => String(p.application_id) === v,
    },
    {
      id: 'status',
      label: 'Estado',
      options: Object.entries(ENTITY_STATUS).map(([value, meta]) => ({ value, label: meta.label })),
      test: (p, v) => p.status === v,
    },
    {
      id: 'price',
      label: 'Precio',
      options: PLAN_PRICE_BANDS,
      test: (p, v) => matchesPriceBand(p.monthly_price, v),
    },
  ];

  return (
    <ListPage
      perm="index plans"
      title="Planes"
      lede="Plantilla de límites por aplicación. Editar un plan no cambia instancias existentes: solo el alta o el cambio de plan materializa las cuotas."
      actions={
        can('create plans') && (
          <Button renderIcon={Add} onClick={() => navigate('/plans/new')}>
            Nuevo plan
          </Button>
        )
      }
    >
      <DataView
        label="Planes"
        rows={plans}
        columns={columns}
        filters={filters}
        search={(p) => `${p.name} ${p.slug} ${appById.get(p.application_id)?.name ?? ''}`}
        defaultSort={{ key: 'application', dir: 'asc' }}
        loading={res.loading}
        refreshing={res.refreshing}
        error={res.error}
        onRetry={res.reload}
        rowActions={(p) => [{ label: 'Editar plan', onClick: () => navigate(`/plans/${p.id}/edit`) }]}
      />
    </ListPage>
  );
}
