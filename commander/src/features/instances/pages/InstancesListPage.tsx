import { useMemo } from 'react';
import { Button } from '@carbon/react';
import { Add } from '@carbon/icons-react';
import { Link, useNavigate } from 'react-router-dom';
import { METRICS, PROVISIONING, SERVICE, fmtNum, fmtRel } from '@services/api/mock/commander-mock';
import type { Instance } from '@services/api/types';
import { useApp } from '@app/provider';
import { useResource } from '@shared/hooks/useResource';
import { lut, pending } from '@shared/lib/data';
import { DataView } from '@shared/components/DataView';
import type { Column, FilterDef } from '@shared/components/DataView';
import { ListPage } from '@shared/components/ListPage';
import { StatusTag } from '@shared/components/StatusTag';
import { Meter } from '@shared/components/Meter';
import { quotaPercent, worstQuotaByInstance } from '@features/quotas/model/quotaHealth';
import { hasIncident } from '../model/instanceActions';
import { useInstancesService } from '../services/instancesService';

export function InstancesListPage() {
  const { can } = useApp();
  const instancesService = useInstancesService();
  const navigate = useNavigate();
  const res = useResource(() => instancesService.listWithContext(), []);
  const [instances, clients, accounts, applications, plans, quotas] = pending(res.data, [[], [], [], [], [], []]);

  const cliById = useMemo(() => lut(clients), [clients]);
  const accById = useMemo(() => lut(accounts), [accounts]);
  const appById = useMemo(() => lut(applications), [applications]);
  const planById = useMemo(() => lut(plans), [plans]);
  const worstQuota = useMemo(() => worstQuotaByInstance(quotas), [quotas]);

  const columns: Column<Instance>[] = [
    {
      key: 'id',
      header: 'ID',
      value: (i) => i.id,
      render: (i) => (
        <Link className="cmd-mono" to={`/instances/${i.id}`}>
          {i.id}
        </Link>
      ),
    },
    {
      key: 'client',
      header: 'Cliente',
      value: (i) => cliById.get(i.client_id)?.name ?? '',
      render: (i) => <Link to={`/clients/${i.client_id}`}>{cliById.get(i.client_id)?.name ?? '—'}</Link>,
    },
    { key: 'account', header: 'Cuenta', value: (i) => accById.get(i.account_id)?.name ?? '' },
    { key: 'application', header: 'Aplicación', value: (i) => appById.get(i.application_id)?.name ?? '' },
    { key: 'plan', header: 'Plan', value: (i) => (i.plan_id ? (planById.get(i.plan_id)?.name ?? '') : '') },
    {
      key: 'provisioning_status',
      header: 'Provisioning',
      value: (i) => i.provisioning_status,
      render: (i) => <StatusTag catalog={PROVISIONING} value={i.provisioning_status} />,
    },
    {
      key: 'service_status',
      header: 'Servicio',
      value: (i) => i.service_status,
      render: (i) => <StatusTag catalog={SERVICE} value={i.service_status} />,
    },
    {
      key: 'quota',
      header: 'Consumo máximo',
      value: (i) => {
        const q = worstQuota.get(i.id);
        return q ? (quotaPercent(q) ?? -1) : -1;
      },
      render: (i) => {
        const q = worstQuota.get(i.id);
        if (!q) return <span className="cmd-meter__caption">Sin cuotas</span>;
        return <Meter value={q.current_value} limit={q.limit_value} caption={METRICS[q.metric_key]?.name ?? q.metric_key} />;
      },
    },
    { key: 'requests_24h', header: 'Requests 24 h', align: 'right', value: (i) => i.requests_24h, render: (i) => fmtNum(i.requests_24h) },
    {
      key: 'last_activity_at',
      header: 'Última actividad',
      value: (i) => i.last_activity_at,
      render: (i) => (i.last_activity_at ? fmtRel(i.last_activity_at) : 'Sin actividad'),
    },
  ];

  const filters: FilterDef<Instance>[] = [
    {
      id: 'account',
      label: 'Cuenta',
      options: accounts.map((a) => ({ value: String(a.id), label: a.name })),
      test: (i, v) => String(i.account_id) === v,
    },
    {
      id: 'application',
      label: 'Aplicación',
      options: applications.map((a) => ({ value: String(a.id), label: a.name })),
      test: (i, v) => String(i.application_id) === v,
    },
    {
      id: 'plan',
      label: 'Plan',
      options: plans.map((p) => ({ value: String(p.id), label: p.name })),
      test: (i, v) => String(i.plan_id) === v,
    },
    {
      id: 'provisioning',
      label: 'Provisioning',
      options: Object.entries(PROVISIONING).map(([value, meta]) => ({ value, label: meta.label })),
      test: (i, v) => i.provisioning_status === v,
    },
    {
      id: 'service',
      label: 'Servicio',
      options: Object.entries(SERVICE).map(([value, meta]) => ({ value, label: meta.label })),
      test: (i, v) => i.service_status === v,
    },
    {
      id: 'health',
      label: 'Salud',
      options: [
        { value: 'incident', label: 'Con incidencia' },
        { value: 'quota-over', label: 'Cuota excedida' },
        { value: 'quota-warn', label: 'Cuota en aviso' },
      ],
      test: (i, v) => {
        if (v === 'incident') return hasIncident(i);
        const q = worstQuota.get(i.id);
        const p = q ? quotaPercent(q) : null;
        if (p === null) return false;
        return v === 'quota-over' ? p >= 100 : p >= 80 && p < 100;
      },
    },
  ];

  return (
    <ListPage
      perm="index instances"
      title="Instancias"
      lede="Una instancia es el par cliente-aplicación con base de datos propia. Solo una instancia ready enruta tráfico."
      actions={
        can('provision instances') && (
          <Button renderIcon={Add} onClick={() => navigate('/provisioning/new')}>
            Nueva alta
          </Button>
        )
      }
    >
      <DataView
        label="Instancias"
        rows={instances}
        columns={columns}
        filters={filters}
        search={(i) =>
          `${i.id} ${cliById.get(i.client_id)?.name ?? ''} ${appById.get(i.application_id)?.name ?? ''} ${i.database_name}`
        }
        defaultSort={{ key: 'id', dir: 'asc' }}
        loading={res.loading}
        refreshing={res.refreshing}
        error={res.error}
        onRetry={res.reload}
        rowActions={(i) => [
          { label: 'Abrir ficha', onClick: () => navigate(`/instances/${i.id}`) },
          { label: 'Operación', onClick: () => navigate(`/instances/${i.id}/operation`) },
          { label: 'Logs', onClick: () => navigate(`/instances/${i.id}/logs`) },
        ]}
      />
    </ListPage>
  );
}
