import { useMemo } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { METRICS, QUOTA_ACTION, fmtNum, fmtRel } from '@services/api/mock/commander-mock';
import type { Quota } from '@services/api/types';
import { useResource } from '@shared/hooks/useResource';
import { lut, pending } from '@shared/lib/data';
import { DataView } from '@shared/components/DataView';
import type { Column, FilterDef } from '@shared/components/DataView';
import { ListPage } from '@shared/components/ListPage';
import { StatusTag } from '@shared/components/StatusTag';
import { Meter } from '@shared/components/Meter';
import { quotaHealth, quotaPercent } from '../model/quotaHealth';
import { useQuotasService } from '../services/quotasService';

const SCOPE_CATALOG = {
  instance: { label: 'Instancia', tone: 'blue' },
  subscription: { label: 'Contrato', tone: 'purple' },
} as const;

export function QuotasListPage() {
  const quotasService = useQuotasService();
  const navigate = useNavigate();
  const res = useResource(() => quotasService.listWithContext(), []);
  const [quotas, instances, clients, accounts, applications, subscriptions] = pending(res.data, [
    [], [], [], [], [], [],
  ]);

  const instById = useMemo(() => lut(instances), [instances]);
  const cliById = useMemo(() => lut(clients), [clients]);
  const appById = useMemo(() => lut(applications), [applications]);
  const subById = useMemo(() => lut(subscriptions), [subscriptions]);

  const owner = (q: Quota) => {
    if (q.scope === 'subscription') return subById.get(q.subscription_id ?? -1)?.name ?? 'Contrato';
    const inst = instById.get(q.instance_id ?? -1);
    return inst ? (cliById.get(inst.client_id)?.name ?? `Instancia ${inst.id}`) : 'Instancia';
  };

  const columns: Column<Quota>[] = [
    { key: 'scope', header: 'Ámbito', value: (q) => q.scope, render: (q) => <StatusTag catalog={SCOPE_CATALOG} value={q.scope} /> },
    {
      key: 'owner',
      header: 'Propietario',
      value: owner,
      render: (q) =>
        q.scope === 'subscription' ? (
          <Link to={`/subscriptions/${q.subscription_id}`}>{owner(q)}</Link>
        ) : (
          <Link to={`/instances/${q.instance_id}/quotas`}>{owner(q)}</Link>
        ),
    },
    { key: 'application', header: 'Aplicación', value: (q) => appById.get(q.application_id)?.name ?? '' },
    {
      key: 'metric',
      header: 'Métrica',
      value: (q) => METRICS[q.metric_key]?.name ?? q.metric_key,
      render: (q) => <Link to={`/quotas/${q.id}`}>{METRICS[q.metric_key]?.name ?? q.metric_key}</Link>,
    },
    {
      key: 'current_value',
      header: 'Consumo',
      value: (q) => quotaPercent(q),
      render: (q) => <Meter value={q.current_value} limit={q.limit_value} />,
    },
    {
      key: 'limit_value',
      header: 'Límite',
      align: 'right',
      value: (q) => q.limit_value,
      render: (q) => (q.limit_value === null ? 'Sin límite' : fmtNum(q.limit_value)),
    },
    {
      key: 'warning_percent',
      header: 'Aviso',
      align: 'right',
      value: (q) => q.warning_percent,
      render: (q) => (q.warning_percent ? `${q.warning_percent} %` : '—'),
    },
    { key: 'action', header: 'Acción', value: (q) => q.action, render: (q) => <StatusTag catalog={QUOTA_ACTION} value={q.action} /> },
    {
      key: 'consecutive',
      header: 'Periodos',
      align: 'right',
      value: (q) => q.consecutive_count,
      render: (q) => `${q.consecutive_count} / ${q.consecutive_periods ?? '—'}`,
    },
    {
      key: 'last_warning_at',
      header: 'Último aviso',
      value: (q) => q.last_warning_at,
      render: (q) => (q.last_warning_at ? fmtRel(q.last_warning_at) : '—'),
    },
    { key: 'measured_at', header: 'Medición', value: (q) => q.measured_at, render: (q) => fmtRel(q.measured_at) },
  ];

  const filters: FilterDef<Quota>[] = [
    {
      id: 'scope',
      label: 'Ámbito',
      options: Object.entries(SCOPE_CATALOG).map(([value, meta]) => ({ value, label: meta.label })),
      test: (q, v) => q.scope === v,
    },
    {
      id: 'account',
      label: 'Cuenta',
      options: accounts.map((a) => ({ value: String(a.id), label: a.name })),
      test: (q, v) => String(q.account_id) === v,
    },
    {
      id: 'application',
      label: 'Aplicación',
      options: applications.map((a) => ({ value: String(a.id), label: a.name })),
      test: (q, v) => String(q.application_id) === v,
    },
    {
      id: 'metric',
      label: 'Métrica',
      options: Object.entries(METRICS).map(([value, meta]) => ({ value, label: meta.name })),
      test: (q, v) => q.metric_key === v,
    },
    {
      id: 'action',
      label: 'Acción',
      options: Object.entries(QUOTA_ACTION).map(([value, meta]) => ({ value, label: meta.label })),
      test: (q, v) => q.action === v,
    },
    {
      id: 'state',
      label: 'Estado',
      options: [
        { value: 'over', label: 'Excedida' },
        { value: 'warn', label: 'En aviso' },
        { value: 'nolimit', label: 'Sin límite' },
        { value: 'unmeasured', label: 'Sin medición' },
      ],
      test: (q, v) => quotaHealth(q) === v,
    },
  ];

  return (
    <ListPage
      perm="index quotas"
      title="Cuotas"
      lede="Las cuotas de instancia nacen del plan y solo se editan. Una cuota compartida se crea desde su contrato."
    >
      <DataView
        label="Cuotas"
        rows={quotas}
        columns={columns}
        filters={filters}
        search={(q) => `${owner(q)} ${METRICS[q.metric_key]?.name ?? q.metric_key}`}
        defaultSort={{ key: 'current_value', dir: 'desc' }}
        loading={res.loading}
        refreshing={res.refreshing}
        error={res.error}
        onRetry={res.reload}
        rowActions={(q) => [{ label: 'Abrir cuota', onClick: () => navigate(`/quotas/${q.id}`) }]}
      />
    </ListPage>
  );
}
