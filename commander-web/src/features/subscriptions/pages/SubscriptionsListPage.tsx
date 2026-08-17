import { useMemo } from 'react';
import { Button } from '@carbon/react';
import { Add } from '@carbon/icons-react';
import { useNavigate } from 'react-router-dom';
import { Link } from '@shared/components/AppLink';
import { SUB_STATUS, fmtDate, fmtMoney } from '@services/api/mock/commander-mock';
import type { Subscription } from '@services/api/types';
import { useApp } from '@app/provider';
import { useResource } from '@shared/hooks/useResource';
import { lut, pending } from '@shared/lib/data';
import { DataView } from '@shared/components/DataView';
import type { Column, FilterDef } from '@shared/components/DataView';
import { ListPage } from '@shared/components/ListPage';
import { StatusTag } from '@shared/components/StatusTag';
import { SUBSCRIPTION_MODE_CATALOG } from '../model/subscriptionColumns';
import { activeItemsBySubscription, isCapacityFull } from '../model/subscriptionCapacity';
import { useSubscriptionsService } from '../services/subscriptionsService';

export function SubscriptionsListPage() {
  const { can } = useApp();
  const subscriptionsService = useSubscriptionsService();
  const navigate = useNavigate();
  const res = useResource(() => subscriptionsService.listWithRelations(), []);
  const [subscriptions, accounts, applications, items] = pending(res.data, [[], [], [], []]);

  const accById = useMemo(() => lut(accounts), [accounts]);
  const appById = useMemo(() => lut(applications), [applications]);
  const activeItems = useMemo(() => activeItemsBySubscription(items), [items]);

  const columns: Column<Subscription>[] = [
    { key: 'name', header: 'Nombre', value: (s) => s.name, render: (s) => <Link to={`/subscriptions/${s.id}`}>{s.name}</Link> },
    {
      key: 'account',
      header: 'Cuenta',
      value: (s) => accById.get(s.account_id)?.name ?? '',
      render: (s) => <Link to={`/accounts/${s.account_id}`}>{accById.get(s.account_id)?.name ?? '—'}</Link>,
    },
    { key: 'application', header: 'Aplicación', value: (s) => appById.get(s.application_id)?.name ?? '' },
    {
      key: 'mode',
      header: 'Modalidad',
      value: (s) => s.mode,
      render: (s) => <StatusTag catalog={SUBSCRIPTION_MODE_CATALOG} value={s.mode} />,
    },
    { key: 'status', header: 'Estado', value: (s) => s.status, render: (s) => <StatusTag catalog={SUB_STATUS} value={s.status} /> },
    {
      key: 'monthly_amount',
      header: 'Importe mensual',
      align: 'right',
      value: (s) => s.monthly_amount,
      render: (s) => fmtMoney(s.monthly_amount, s.currency),
    },
    {
      key: 'capacity',
      header: 'Cabeceras',
      value: (s) => activeItems.get(s.id) ?? 0,
      render: (s) => `${activeItems.get(s.id) ?? 0} / ${s.max_instances}`,
    },
    {
      key: 'trial_ends_at',
      header: 'Trial',
      value: (s) => s.trial_ends_at,
      render: (s) => (s.trial_ends_at ? `hasta ${fmtDate(s.trial_ends_at)}` : '—'),
    },
    { key: 'starts_at', header: 'Inicio', value: (s) => s.starts_at, render: (s) => fmtDate(s.starts_at) },
    { key: 'ends_at', header: 'Fin', value: (s) => s.ends_at, render: (s) => fmtDate(s.ends_at) },
  ];

  const filters: FilterDef<Subscription>[] = [
    {
      id: 'account',
      label: 'Cuenta',
      options: accounts.map((a) => ({ value: String(a.id), label: a.name })),
      test: (s, v) => String(s.account_id) === v,
    },
    {
      id: 'application',
      label: 'Aplicación',
      options: applications.map((a) => ({ value: String(a.id), label: a.name })),
      test: (s, v) => String(s.application_id) === v,
    },
    {
      id: 'mode',
      label: 'Modalidad',
      options: Object.entries(SUBSCRIPTION_MODE_CATALOG).map(([value, meta]) => ({ value, label: meta.label })),
      test: (s, v) => s.mode === v,
    },
    {
      id: 'status',
      label: 'Estado',
      options: Object.entries(SUB_STATUS).map(([value, meta]) => ({ value, label: meta.label })),
      test: (s, v) => s.status === v,
    },
    {
      id: 'capacity',
      label: 'Capacidad',
      options: [
        { value: 'full', label: 'Agotada' },
        { value: 'free', label: 'Con hueco' },
      ],
      test: (s, v) => (isCapacityFull(s, activeItems.get(s.id) ?? 0) ? v === 'full' : v === 'free'),
    },
  ];

  return (
    <ListPage
      perm="index subscriptions"
      title="Contratos"
      lede="El contrato fija precio y capacidad. Sus cabeceras son los pares cliente-instancia que consumen esa capacidad."
      actions={
        can('create subscriptions') && (
          <Button renderIcon={Add} onClick={() => navigate('/subscriptions/new')}>
            Nuevo contrato
          </Button>
        )
      }
    >
      <DataView
        label="Contratos"
        rows={subscriptions}
        columns={columns}
        filters={filters}
        search={(s) => `${s.name} ${accById.get(s.account_id)?.name ?? ''}`}
        defaultSort={{ key: 'name', dir: 'asc' }}
        loading={res.loading}
        refreshing={res.refreshing}
        error={res.error}
        onRetry={res.reload}
        rowActions={(s) => [
          { label: 'Abrir ficha', onClick: () => navigate(`/subscriptions/${s.id}`) },
          { label: 'Cabeceras', onClick: () => navigate(`/subscriptions/${s.id}/items`) },
        ]}
      />
    </ListPage>
  );
}
