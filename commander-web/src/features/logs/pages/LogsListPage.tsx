import { useMemo } from 'react';
import { Link } from '@shared/components/AppLink';
import { fmtDateTime, fmtNum } from '@services/api/mock/commander-mock';
import type { LogEntry } from '@services/api/types';
import { useResource } from '@shared/hooks/useResource';
import { lut, pending } from '@shared/lib/data';
import { DataView } from '@shared/components/DataView';
import type { Column, FilterDef } from '@shared/components/DataView';
import { ListPage } from '@shared/components/ListPage';
import { HTTP_METHODS, STATUS_CLASS_OPTIONS, statusClass, statusTone } from '../model/httpStatus';
import { useLogsService } from '../services/logsService';

export function LogsListPage() {
  const logsService = useLogsService();
  const res = useResource(() => logsService.listGlobal(), []);
  const [logs, instances, clients, applications, accounts] = pending(res.data, [[], [], [], [], []]);

  const instById = useMemo(() => lut(instances), [instances]);
  const cliById = useMemo(() => lut(clients), [clients]);
  const appById = useMemo(() => lut(applications), [applications]);

  const columns: Column<LogEntry>[] = [
    {
      key: 'created_at',
      header: 'Fecha',
      value: (l) => l.created_at,
      render: (l) => <span className="cmd-mono">{fmtDateTime(l.created_at)}</span>,
    },
    {
      key: 'client',
      header: 'Cliente',
      value: (l) => cliById.get(instById.get(l.instance_id)?.client_id ?? -1)?.name ?? '',
    },
    { key: 'application', header: 'Aplicación', value: (l) => appById.get(l.application_id)?.name ?? '' },
    {
      key: 'instance',
      header: 'Instancia',
      value: (l) => l.instance_id,
      render: (l) => (
        <Link className="cmd-mono" to={`/instances/${l.instance_id}/logs`}>
          {l.instance_id}
        </Link>
      ),
    },
    { key: 'method', header: 'Método', value: (l) => l.method, render: (l) => <span className="cmd-mono">{l.method}</span> },
    { key: 'path', header: 'Ruta', value: (l) => l.path, render: (l) => <span className="cmd-mono">{l.path}</span> },
    {
      key: 'status',
      header: 'Estado',
      align: 'right',
      value: (l) => l.status,
      render: (l) => {
        const tone = statusTone(l.status);
        return <span className={tone ? `cmd-text-${tone}` : undefined}>{l.status}</span>;
      },
    },
    {
      key: 'duration_ms',
      header: 'Duración',
      align: 'right',
      value: (l) => l.duration_ms,
      render: (l) => `${fmtNum(l.duration_ms)} ms`,
    },
    { key: 'actor', header: 'Actor', value: (l) => l.actor, render: (l) => <span className="cmd-mono">{l.actor}</span> },
    { key: 'ip', header: 'IP', value: (l) => l.ip, render: (l) => <span className="cmd-mono">{l.ip}</span> },
    { key: 'bytes', header: 'Bytes', align: 'right', value: (l) => l.bytes, render: (l) => fmtNum(l.bytes) },
    { key: 'memory_mb', header: 'Memoria', align: 'right', value: (l) => l.memory_mb, render: (l) => `${l.memory_mb} MB` },
  ];

  const filters: FilterDef<LogEntry>[] = [
    {
      id: 'application',
      label: 'Aplicación',
      options: applications.map((a) => ({ value: String(a.id), label: a.name })),
      test: (l, v) => String(l.application_id) === v,
    },
    {
      id: 'account',
      label: 'Cuenta',
      options: accounts.map((a) => ({ value: String(a.id), label: a.name })),
      test: (l, v) => String(l.account_id) === v,
    },
    {
      id: 'class',
      label: 'Clase de estado',
      options: STATUS_CLASS_OPTIONS,
      test: (l, v) => statusClass(l.status) === v,
    },
    {
      id: 'method',
      label: 'Método',
      options: HTTP_METHODS.map((m) => ({ value: m, label: m })),
      test: (l, v) => l.method === v,
    },
  ];

  return (
    <ListPage
      perm="index instances"
      title="Logs"
      lede="Últimos requests servidos por las instancias. Pueden desaparecer por retención o por la baja de la instancia; no hay exportación ni borrado."
    >
      <DataView
        label="Logs de requests"
        rows={logs}
        columns={columns}
        filters={filters}
        search={(l) => `${l.path} ${l.actor} ${l.ip} ${l.status}`}
        defaultSort={{ key: 'created_at', dir: 'desc' }}
        loading={res.loading}
        refreshing={res.refreshing}
        error={res.error}
        onRetry={res.reload}
      />
    </ListPage>
  );
}
