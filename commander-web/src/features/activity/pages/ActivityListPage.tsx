import { useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import { Link } from '@shared/components/AppLink';
import { fmtDateTime } from '@services/api/mock/commander-mock';
import type { Activity } from '@services/api/types';
import { useResource } from '@shared/hooks/useResource';
import { lut, pending } from '@shared/lib/data';
import { DataView } from '@shared/components/DataView';
import type { Column, FilterDef } from '@shared/components/DataView';
import { ListPage } from '@shared/components/ListPage';
import { useActivityService } from '../services/activityService';

export function ActivityListPage() {
  const activityService = useActivityService();
  const navigate = useNavigate();
  const res = useResource(() => activityService.listWithContext(), []);
  const [activity, instances, clients] = pending(res.data, [[], [], []]);

  const instById = useMemo(() => lut(instances), [instances]);
  const cliById = useMemo(() => lut(clients), [clients]);

  const columns: Column<Activity>[] = [
    { key: 'created_at', header: 'Fecha', value: (a) => a.created_at, render: (a) => fmtDateTime(a.created_at) },
    { key: 'label', header: 'Tipo', value: (a) => a.label, render: (a) => <Link to={`/activity/${a.id}`}>{a.label}</Link> },
    {
      key: 'instance',
      header: 'Instancia',
      value: (a) => a.instance_id,
      render: (a) =>
        a.instance_id ? (
          <Link to={`/instances/${a.instance_id}`}>
            {cliById.get(instById.get(a.instance_id)?.client_id ?? -1)?.name ?? a.instance_id}
          </Link>
        ) : (
          '—'
        ),
    },
    {
      key: 'subscription',
      header: 'Contrato',
      value: (a) => a.subscription_id,
      render: (a) => (a.subscription_id ? <Link to={`/subscriptions/${a.subscription_id}`}>{a.subscription_id}</Link> : '—'),
    },
    {
      key: 'invoice',
      header: 'Factura',
      value: (a) => a.invoice_id,
      render: (a) => (a.invoice_id ? <Link to={`/invoices/${a.invoice_id}`}>{a.invoice_id}</Link> : '—'),
    },
    { key: 'reason', header: 'Razón', value: (a) => a.reason, render: (a) => <span className="cmd-mono">{a.reason}</span> },
    { key: 'message', header: 'Mensaje', value: (a) => a.message },
  ];

  const filters: FilterDef<Activity>[] = [
    {
      id: 'type',
      label: 'Tipo',
      options: [...new Map(activity.map((a) => [a.type, a.label])).entries()].map(([value, label]) => ({ value, label })),
      test: (a, v) => a.type === v,
    },
  ];

  return (
    <ListPage
      perm="index activity"
      title="Actividad"
      lede="Auditoría operativa del control plane: estados de instancia, cuotas y contratos. No es un sistema de tareas ni se puede editar."
    >
      <DataView
        label="Actividad"
        rows={activity}
        columns={columns}
        filters={filters}
        search={(a) => `${a.label} ${a.reason} ${a.message}`}
        defaultSort={{ key: 'created_at', dir: 'desc' }}
        loading={res.loading}
        refreshing={res.refreshing}
        error={res.error}
        onRetry={res.reload}
        rowActions={(a) => [{ label: 'Ver evento', onClick: () => navigate(`/activity/${a.id}`) }]}
      />
    </ListPage>
  );
}
