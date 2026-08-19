import { useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Modal } from '@carbon/react';
import { Link } from '@shared/components/AppLink';
import { fmtDateTime, fmtNum, now } from '@services/api/mock/commander-mock';
import type { LogEntry } from '@services/api/types';
import { useResource } from '@shared/hooks/useResource';
import { lut, pending } from '@shared/lib/data';
import { DataView } from '@shared/components/DataView';
import type { Column, FilterDef } from '@shared/components/DataView';
import { Defs } from '@shared/components/Detail';
import { ListPage } from '@shared/components/ListPage';
import { statusTone } from '../model/httpStatus';
import { useLogsService } from '../services/logsService';

/** Filas por petición cuando nadie ha elegido otra cosa. */
const DEFAULT_LIMIT = 250;

/** Por encima del segundo la petición se señala aunque haya respondido bien. */
const SLOW_MS = 1000;

export function LogsListPage() {
  const logsService = useLogsService();
  const res = useResource(() => logsService.listGlobal(), []);
  const [logs, instances, clients, applications, accounts] = pending(res.data, [
    [],
    [],
    [],
    [],
    [],
  ]);
  // El límite es de petición, no de filtro: recorta lo que se trae antes de mirarlo,
  // así que la página lo lee de la URL en vez de dejárselo entero a `DataView`.
  const [params] = useSearchParams();
  const [detail, setDetail] = useState<LogEntry | null>(null);

  const instById = useMemo(() => lut(instances), [instances]);
  const cliById = useMemo(() => lut(clients), [clients]);
  const appById = useMemo(() => lut(applications), [applications]);

  const limit = Number(params.get('limit')) || DEFAULT_LIMIT;
  const rows = useMemo(() => logs.slice(0, limit), [logs, limit]);

  const clientName = (l: LogEntry) =>
    cliById.get(instById.get(l.instance_id)?.client_id ?? -1)?.name ?? '';

  const columns: Column<LogEntry>[] = [
    {
      key: 'date',
      header: 'Fecha',
      value: (l) => l.created_at,
      render: (l) => <span className="cmd-dim">{fmtDateTime(l.created_at)}</span>,
    },
    {
      key: 'client',
      header: 'Cliente e instancia',
      value: (l) => clientName(l),
      render: (l) => <Link to={`/instances/${l.instance_id}`}>{clientName(l) || '—'}</Link>,
      sub: (l) => `#${l.instance_id} · ${appById.get(l.application_id)?.name ?? ''}`,
    },
    {
      key: 'path',
      header: 'Petición',
      value: (l) => l.path,
      render: (l) => (
        <span className="cmd-mono">
          {l.method} {l.path}
        </span>
      ),
    },
    {
      key: 'status',
      header: 'Estado',
      align: 'right',
      value: (l) => l.status,
      render: (l) => <span className={`cmd-text-${statusTone(l.status)}`}>{l.status}</span>,
    },
    {
      key: 'ms',
      header: 'Duración',
      align: 'right',
      value: (l) => l.duration_ms,
      render: (l) => (
        <span className={`cmd-mono ${l.duration_ms > SLOW_MS ? 'cmd-text-warn' : 'cmd-dim'}`}>
          {l.duration_ms} ms
        </span>
      ),
    },
    {
      key: 'actor',
      header: 'Actor e IP',
      value: (l) => l.actor,
      render: (l) => <span className="cmd-mono cmd-dim">{l.actor}</span>,
      sub: (l) => l.ip,
    },
    {
      key: 'bytes',
      header: 'Bytes y memoria',
      align: 'right',
      value: (l) => l.bytes,
      render: (l) => fmtNum(l.bytes),
      sub: (l) => `${l.memory_mb} MB`,
    },
  ];

  const filters: FilterDef<LogEntry>[] = [
    {
      id: 'app',
      label: 'Aplicación',
      options: applications.map((a) => ({ value: String(a.id), label: a.name })),
      test: (l, v) => String(l.application_id) === v,
    },
    {
      id: 'account',
      label: 'Cuenta',
      options: [...accounts]
        .sort((a, b) => a.name.localeCompare(b.name))
        .map((a) => ({ value: String(a.id), label: a.name })),
      test: (l, v) => String(l.account_id) === v,
    },
    {
      id: 'since',
      label: 'Desde',
      all: '72 h',
      options: [
        { value: '1', label: 'Última hora' },
        { value: '6', label: 'Últimas 6 h' },
        { value: '24', label: 'Últimas 24 h' },
      ],
      test: (l, v) =>
        new Date(l.created_at) >= new Date(now().getTime() - Number(v) * 3600000),
    },
    {
      id: 'class',
      label: 'Clase de estado',
      options: [
        { value: '2', label: '2xx' },
        { value: '3', label: '3xx' },
        { value: '4', label: '4xx' },
        { value: '5', label: '5xx' },
      ],
      test: (l, v) => String(l.status).charAt(0) === v,
    },
    {
      id: 'method',
      label: 'Método',
      options: ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'].map((m) => ({ value: m, label: m })),
      test: (l, v) => l.method === v,
    },
    {
      id: 'limit',
      label: 'Límite',
      all: String(DEFAULT_LIMIT),
      options: [
        { value: '100', label: '100' },
        { value: '500', label: '500' },
      ],
      // Ya recortó la lista antes de llegar aquí; como filtro no descarta ninguna fila.
      test: () => true,
    },
  ];

  return (
    <ListPage
      perm="index instances"
      title="Logs de requests"
      lede={`Alta densidad, ${limit} filas como máximo por petición. No hay borrado ni exportación porque no existen endpoints; los logs pueden desaparecer por retención o baja.`}
    >
      <DataView
        label="Logs de requests"
        rows={rows}
        columns={columns}
        filters={filters}
        search={(l) => `${l.path} ${l.actor} ${l.ip} ${l.method}`}
        searchHint="Ruta, actor o IP"
        loading={res.loading}
        refreshing={res.refreshing}
        error={res.error}
        onRetry={res.reload}
        rowActions={(l) => [{ label: 'Ver la fila completa', onClick: () => setDetail(l) }]}
      />

      {/* Leer una fila entera no es una acción, así que no usa `ActionModal`: es un
          diálogo pasivo con lo que la tabla resume o no enseña. */}
      {detail && (
        <Modal
          open
          size="sm"
          modalLabel={`Log ${detail.id}`}
          modalHeading={`${detail.method} ${detail.path}`}
          primaryButtonText="Cerrar"
          closeButtonLabel="Cerrar"
          onRequestClose={() => setDetail(null)}
          onRequestSubmit={() => setDetail(null)}
        >
          <div className="cmd-stack">
            <p className="cmd-page__lede">
              Los identificadores de usuario y token son informativos: no se enlazan a usuarios de
              Commander.
            </p>
            <Defs
              items={[
                ['Fecha', fmtDateTime(detail.created_at)],
                ['Instancia', `${detail.instance_id} · ${clientName(detail)}`],
                ['Estado y duración', `${detail.status} · ${detail.duration_ms} ms`],
                ['Actor e IP', `${detail.actor} · ${detail.ip}`],
                ['Bytes y memoria', `${fmtNum(detail.bytes)} B · ${detail.memory_mb} MB`],
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
