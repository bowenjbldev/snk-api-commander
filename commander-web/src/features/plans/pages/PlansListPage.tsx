import { useMemo, useState } from 'react';
import { Button, ToastNotification } from '@carbon/react';
import { Add } from '@carbon/icons-react';
import { useNavigate } from 'react-router-dom';
import { Link } from '@shared/components/AppLink';
import { ENTITY_STATUS, fmtMoney } from '@services/api/mock/commander-mock';
import type { Plan } from '@services/api/types';
import { useApp } from '@app/provider';
import { useResource } from '@shared/hooks/useResource';
import { lut, pending } from '@shared/lib/data';
import { DataView } from '@shared/components/DataView';
import type { Column, FilterDef, RowAction } from '@shared/components/DataView';
import { ListPage } from '@shared/components/ListPage';
import { StatusTag } from '@shared/components/StatusTag';
import { summarizeLimits } from '../model/planLimits';
import { PLAN_PRICE_BANDS, priceBand } from '../model/priceBands';
import { usePlansService } from '../services/plansService';

/** Un plan del catálogo se activa o se desactiva; `suspended` es de cuentas y clientes. */
const STATUS_OPTIONS = ['active', 'inactive'].map((value) => ({
  value,
  label: ENTITY_STATUS[value].label,
}));

/** Aviso de una acción que no llega a escribir nada. `id` fuerza el remontaje al repetirla. */
interface Notice {
  id: number;
  kind: 'warning' | 'info';
  title: string;
  body: string;
}

export function PlansListPage() {
  const { can } = useApp();
  const plansService = usePlansService();
  const navigate = useNavigate();
  const res = useResource(() => plansService.load(can), [can]);
  const [plans, applications, instances] = pending(res.data, [[], [], []]);
  const appById = useMemo(() => lut(applications), [applications]);
  const [notice, setNotice] = useState<Notice | null>(null);

  const columns: Column<Plan>[] = [
    {
      key: 'app',
      header: 'Aplicación',
      value: (p) => appById.get(p.application_id)?.name ?? '',
      render: (p) => (
        <Link to={`/applications/${p.application_id}`}>
          {appById.get(p.application_id)?.name ?? '—'}
        </Link>
      ),
    },
    {
      key: 'name',
      header: 'Plan',
      value: (p) => p.name.toLowerCase(),
      // Sin permiso de edición el nombre sigue siendo enlace, pero a la propia lista: la
      // ficha de aplicación hace lo mismo y así el plan se lee igual en las dos.
      render: (p) => <Link to={can('update plans') ? `/plans/${p.id}/edit` : '/plans'}>{p.name}</Link>,
    },
    {
      key: 'slug',
      header: 'Slug',
      value: (p) => p.slug,
      render: (p) => <span className="cmd-mono cmd-dim">{p.slug}</span>,
    },
    {
      key: 'price',
      header: 'Precio mensual',
      align: 'right',
      value: (p) => p.monthly_price,
      render: (p) => fmtMoney(p.monthly_price, p.currency),
    },
    {
      key: 'status',
      header: 'Estado',
      value: (p) => p.status,
      render: (p) => <StatusTag catalog={ENTITY_STATUS} value={p.status} />,
    },
    {
      key: 'limits',
      header: 'Resumen de límites',
      render: (p) => <span className="cmd-dim">{summarizeLimits(p.limits)}</span>,
    },
  ];

  const filters: FilterDef<Plan>[] = [
    {
      id: 'app',
      label: 'Aplicación',
      options: applications.map((a) => ({ value: String(a.id), label: a.name })),
      test: (p, v) => String(p.application_id) === v,
    },
    {
      id: 'status',
      label: 'Estado',
      options: STATUS_OPTIONS,
      test: (p, v) => p.status === v,
    },
    {
      id: 'price',
      label: 'Precio',
      all: 'Cualquiera',
      options: PLAN_PRICE_BANDS,
      test: (p, v) => priceBand(p.monthly_price) === v,
    },
  ];

  function rowActions(plan: Plan): RowAction[] {
    const actions: RowAction[] = [];
    if (can('update plans')) {
      actions.push({ label: 'Editar plantilla', onClick: () => navigate(`/plans/${plan.id}/edit`) });
    }
    actions.push({
      label: 'Ver instancias con el plan',
      onClick: () => navigate(`/instances?plan=${plan.id}`),
    });
    if (can('delete plans')) {
      const inUse = instances.some((i) => i.plan_id === plan.id);
      actions.push(
        inUse
          ? {
              label: 'No se puede borrar: en uso',
              onClick: () =>
                setNotice({
                  id: Date.now(),
                  kind: 'warning',
                  title: 'Plan en uso',
                  body: 'Tiene instancias asociadas. Desactívalo en lugar de borrarlo.',
                }),
            }
          : {
              label: 'Borrar plan',
              danger: true,
              onClick: () =>
                setNotice({
                  id: Date.now(),
                  kind: 'info',
                  title: 'Sin endpoint en el prototipo',
                  body: 'El borrado de planes sin ítems existe en la API, pero aquí no se simula la escritura destructiva del catálogo.',
                }),
            },
      );
    }
    return actions;
  }

  return (
    <ListPage
      perm="index plans"
      title="Planes"
      lede="Cada plan es una plantilla de límites de una aplicación. Modificarlo no cambia las instancias ya aprovisionadas."
      actions={
        can('create plans') && (
          <Button renderIcon={Add} onClick={() => navigate('/plans/new')}>
            Nuevo plan
          </Button>
        )
      }
    >
      {notice && (
        <ToastNotification
          key={notice.id}
          kind={notice.kind}
          lowContrast
          title={notice.title}
          subtitle={notice.body}
          onClose={() => setNotice(null)}
        />
      )}

      <DataView
        label="Planes"
        rows={plans}
        columns={columns}
        filters={filters}
        search={(p) => `${p.name} ${p.slug} ${appById.get(p.application_id)?.name ?? ''}`}
        searchHint="Nombre o slug"
        loading={res.loading}
        refreshing={res.refreshing}
        error={res.error}
        onRetry={res.reload}
        rowActions={rowActions}
      />
    </ListPage>
  );
}
