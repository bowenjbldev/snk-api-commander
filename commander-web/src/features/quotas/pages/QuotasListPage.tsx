import { useMemo } from 'react';
import { Link } from '@shared/components/AppLink';
import {
  METRICS,
  QUOTA_ACTION,
  fmtDateTime,
  fmtNum,
  fmtRel,
} from '@services/api/mock/commander-mock';
import type { Quota } from '@services/api/types';
import { useResource } from '@shared/hooks/useResource';
import { lut, pending } from '@shared/lib/data';
import { DataView } from '@shared/components/DataView';
import type { Column, FilterDef } from '@shared/components/DataView';
import { ListPage } from '@shared/components/ListPage';
import { StatusTag } from '@shared/components/StatusTag';
import { Meter } from '@shared/components/Meter';
import {
  quotaHealth,
  quotaOwnerHref,
  quotaOwnerName,
  quotaPercent,
} from '../model/quotaHealth';
import { useQuotasService } from '../services/quotasService';

export function QuotasListPage() {
  const quotasService = useQuotasService();
  const res = useResource(() => quotasService.listWithContext(), []);
  const [quotas, instances, clients, accounts, applications, subscriptions] = pending(res.data, [
    [], [], [], [], [], [],
  ]);

  const instById = useMemo(() => lut(instances), [instances]);
  const cliById = useMemo(() => lut(clients), [clients]);
  const appById = useMemo(() => lut(applications), [applications]);
  const subById = useMemo(() => lut(subscriptions), [subscriptions]);

  const owner = (q: Quota) => quotaOwnerName(q, instById, cliById, subById);
  const appName = (q: Quota) => appById.get(q.application_id)?.name ?? '';

  const columns: Column<Quota>[] = [
    {
      key: 'owner',
      header: 'Propietario',
      value: owner,
      render: (q) => <Link to={quotaOwnerHref(q)}>{owner(q)}</Link>,
      sub: (q) => `${q.scope === 'instance' ? 'Instancia' : 'Contrato'} · ${appName(q)}`,
    },
    {
      key: 'metric',
      header: 'Métrica',
      value: (q) => METRICS[q.metric_key].name,
      render: (q) => <Link to={`/quotas/${q.id}`}>{METRICS[q.metric_key].name}</Link>,
      sub: (q) => q.metric_key,
    },
    {
      key: 'value',
      header: 'Valor y límite',
      align: 'right',
      value: (q) => q.current_value,
      render: (q) => fmtNum(q.current_value),
      sub: (q) =>
        q.limit_value === null
          ? 'Sin límite'
          : `de ${fmtNum(q.limit_value)} ${METRICS[q.metric_key].unit}`,
    },
    {
      key: 'pct',
      header: 'Consumo',
      // Sin límite no hay porcentaje, y esas filas se van al final del orden.
      value: (q) => quotaPercent(q) ?? -1,
      render: (q) =>
        // `pct` divide un valor nulo y devuelve cero: sin medición no hay barra que pintar.
        q.current_value === null && q.limit_value !== null ? (
          <span className="cmd-meter__caption">Sin medición</span>
        ) : (
          <Meter value={q.current_value} limit={q.limit_value} />
        ),
    },
    {
      key: 'action',
      header: 'Acción',
      value: (q) => q.action,
      render: (q) => <StatusTag catalog={QUOTA_ACTION} value={q.action} />,
    },
    {
      key: 'warn',
      header: 'Aviso y periodos',
      align: 'right',
      value: (q) => q.warning_percent,
      render: (q) => (
        <span className="cmd-dim">
          {q.warning_percent === null ? '—' : `${q.warning_percent} %`}
        </span>
      ),
      sub: (q) =>
        q.consecutive_periods === null
          ? null
          : `${q.consecutive_count} / ${q.consecutive_periods} periodos`,
    },
    {
      key: 'measured',
      header: 'Última medición',
      value: (q) => q.measured_at,
      render: (q) => <span className="cmd-dim">{fmtDateTime(q.measured_at)}</span>,
      sub: (q) => (q.last_warning_at ? `aviso ${fmtRel(q.last_warning_at)}` : 'sin avisos'),
    },
  ];

  const filters: FilterDef<Quota>[] = [
    {
      id: 'scope',
      label: 'Ámbito',
      options: [
        { value: 'instance', label: 'Instancia' },
        { value: 'subscription', label: 'Contrato' },
      ],
      test: (q, v) => q.scope === v,
    },
    {
      id: 'account',
      // La cuenta es el pagador y hay muchas: ordenadas por nombre se encuentran.
      label: 'Cuenta',
      options: [...accounts]
        .sort((a, b) => a.name.localeCompare(b.name, 'es'))
        .map((a) => ({ value: String(a.id), label: a.name })),
      test: (q, v) => String(q.account_id) === v,
    },
    {
      id: 'app',
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
      label: 'Situación',
      all: 'Cualquiera',
      options: [
        { value: 'nolimit', label: 'Sin límite' },
        { value: 'warn', label: 'En aviso' },
        { value: 'over', label: 'Excedida' },
      ],
      test: (q, v) => quotaHealth(q) === v,
    },
  ];

  return (
    <ListPage
      perm="index quotas"
      title="Cuotas"
      lede="Las cuotas de instancia nacen del plan y solo se editan. Crear se ofrece únicamente dentro de las cuotas compartidas de un contrato."
    >
      <DataView
        label="Cuotas"
        rows={quotas}
        columns={columns}
        filters={filters}
        search={(q) => [METRICS[q.metric_key].name, q.metric_key, owner(q), appName(q)].join(' ')}
        searchHint="Métrica, propietario o aplicación"
        loading={res.loading}
        refreshing={res.refreshing}
        error={res.error}
        onRetry={res.reload}
      />
    </ListPage>
  );
}
