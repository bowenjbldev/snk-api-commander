import { useSearchParams } from 'react-router-dom';
import { Modal } from '@carbon/react';
import { Link } from '@shared/components/AppLink';
import { fmtDateTime } from '@services/api/mock/commander-mock';
import type { Activity } from '@services/api/types';
import { useResource } from '@shared/hooks/useResource';
import { pending } from '@shared/lib/data';
import { DataView } from '@shared/components/DataView';
import type { Column, FilterDef } from '@shared/components/DataView';
import { Defs } from '@shared/components/Detail';
import { ListPage } from '@shared/components/ListPage';
import { useActivityService } from '../services/activityService';

export function ActivityListPage() {
  const activityService = useActivityService();
  const res = useResource(() => activityService.listWithContext(), []);
  const [activity, accounts] = pending(res.data, [[], []]);
  // El evento abierto viaja en la URL y no en un estado local: la actividad reciente
  // del panel lateral enlaza a /activity/:id, y esa ruta redirige aquí con el evento
  // ya elegido. `useUrlState` no sirve porque devuelve la tabla a la primera página.
  const [params, setParams] = useSearchParams();
  const detail = activity.find((a) => String(a.id) === params.get('event'));
  const setEvent = (id: number | null) =>
    setParams(
      (prev) => {
        if (id === null) prev.delete('event');
        else prev.set('event', String(id));
        return prev;
      },
      // Abrir empuja historia para que Atrás cierre el diálogo; cerrar no la duplica.
      { replace: id === null },
    );

  const columns: Column<Activity>[] = [
    {
      key: 'date',
      header: 'Fecha',
      value: (a) => a.created_at,
      render: (a) => <span className="cmd-dim">{fmtDateTime(a.created_at)}</span>,
    },
    { key: 'type', header: 'Tipo', value: (a) => a.label },
    {
      key: 'inst',
      header: 'Instancia',
      align: 'right',
      value: (a) => a.instance_id,
      render: (a) =>
        a.instance_id ? (
          <Link to={`/instances/${a.instance_id}`}>{a.instance_id}</Link>
        ) : (
          <span className="cmd-dim">—</span>
        ),
    },
    {
      key: 'sub',
      header: 'Contrato',
      align: 'right',
      value: (a) => a.subscription_id,
      render: (a) =>
        a.subscription_id ? (
          <Link to={`/subscriptions/${a.subscription_id}`}>{a.subscription_id}</Link>
        ) : (
          <span className="cmd-dim">—</span>
        ),
    },
    {
      key: 'inv',
      header: 'Factura',
      align: 'right',
      value: (a) => a.invoice_id,
      render: (a) =>
        a.invoice_id ? (
          <Link to={`/invoices/${a.invoice_id}`}>{a.invoice_id}</Link>
        ) : (
          <span className="cmd-dim">—</span>
        ),
    },
    {
      key: 'reason',
      header: 'Razón',
      value: (a) => a.reason,
      render: (a) => <span className="cmd-mono cmd-dim">{a.reason}</span>,
    },
    { key: 'msg', header: 'Mensaje', render: (a) => <span className="cmd-dim">{a.message}</span> },
  ];

  const filters: FilterDef<Activity>[] = [
    {
      id: 'type',
      // El catálogo de tipos no viene de la API: sale de los propios eventos cargados,
      // y cada uno se rotula con la etiqueta que ya trae el evento.
      label: 'Tipo',
      options: [...new Map(activity.map((a) => [a.type, a.label])).entries()].map(
        ([value, label]) => ({ value, label }),
      ),
      test: (a, v) => a.type === v,
    },
    {
      id: 'account',
      label: 'Cuenta',
      options: [...accounts]
        .sort((a, b) => a.name.localeCompare(b.name))
        .map((a) => ({ value: String(a.id), label: a.name })),
      test: (a, v) => String(a.account_id) === v,
    },
  ];

  return (
    <ListPage
      perm="index activity"
      title="Actividad"
      lede="Auditoría operativa, no un sistema genérico de tareas. Mientras el endpoint no acepte filtros, la búsqueda y el filtrado son locales."
    >
      <DataView
        label="Actividad"
        rows={activity}
        columns={columns}
        filters={filters}
        search={(a) => `${a.label} ${a.reason} ${a.message}`}
        searchHint="Razón, mensaje o tipo"
        loading={res.loading}
        refreshing={res.refreshing}
        error={res.error}
        onRetry={res.reload}
        rowActions={(a) => [{ label: 'Ver el evento completo', onClick: () => setEvent(a.id) }]}
      />

      {/* Leer un evento entero no es una acción, así que no usa `ActionModal`: es un
          diálogo pasivo, igual que la fila completa de un log. */}
      {detail && (
        <Modal
          open
          size="sm"
          modalLabel={`Evento ${detail.id}`}
          modalHeading={detail.label}
          primaryButtonText="Cerrar"
          closeButtonLabel="Cerrar"
          onRequestClose={() => setEvent(null)}
          onRequestSubmit={() => setEvent(null)}
        >
          <div className="cmd-stack">
            <p className="cmd-page__lede">
              El detalle no permite editar ni borrar: es auditoría operativa.
            </p>
            <Defs
              items={[
                ['Fecha', fmtDateTime(detail.created_at)],
                ['Tipo', detail.type],
                ['Razón', detail.reason],
                ['Mensaje', detail.message],
                [
                  'Referencias',
                  [
                    detail.instance_id && `instancia ${detail.instance_id}`,
                    detail.subscription_id && `contrato ${detail.subscription_id}`,
                    detail.invoice_id && `factura ${detail.invoice_id}`,
                  ]
                    .filter(Boolean)
                    .join(' · ') || '—',
                ],
              ].map(([label, value]) => ({
                label,
                value: <span className="cmd-mono">{value}</span>,
              }))}
            />
          </div>
        </Modal>
      )}
    </ListPage>
  );
}
