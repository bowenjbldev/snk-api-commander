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
import type { Column, FilterDef, RowAction } from '@shared/components/DataView';
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
    {
      key: 'name',
      header: 'Contrato',
      value: (s) => s.name.toLowerCase(),
      render: (s) => <Link to={`/subscriptions/${s.id}`}>{s.name}</Link>,
    },
    {
      key: 'account',
      header: 'Cuenta',
      value: (s) => accById.get(s.account_id)?.name ?? '',
      render: (s) => <Link to={`/accounts/${s.account_id}`}>{accById.get(s.account_id)?.name ?? '—'}</Link>,
    },
    {
      key: 'app',
      header: 'Aplicación',
      value: (s) => appById.get(s.application_id)?.name ?? '',
      render: (s) => <span className="cmd-dim">{appById.get(s.application_id)?.name ?? '—'}</span>,
    },
    {
      key: 'mode',
      header: 'Modalidad',
      value: (s) => s.mode,
      render: (s) => <StatusTag catalog={SUBSCRIPTION_MODE_CATALOG} value={s.mode} />,
    },
    { key: 'status', header: 'Estado', value: (s) => s.status, render: (s) => <StatusTag catalog={SUB_STATUS} value={s.status} /> },
    {
      key: 'amount',
      header: 'Importe mensual',
      align: 'right',
      value: (s) => s.monthly_amount,
      render: (s) => fmtMoney(s.monthly_amount, s.currency),
    },
    {
      key: 'cap',
      header: 'Cabeceras',
      align: 'right',
      // Ordena por ocupación, no por número de cabeceras: dos de tres aprieta más que tres de diez.
      value: (s) => (activeItems.get(s.id) ?? 0) / s.max_instances,
      render: (s) => {
        const active = activeItems.get(s.id) ?? 0;
        return (
          <span className={isCapacityFull(s, active) ? 'cmd-text-warn' : undefined}>
            {active} / {s.max_instances}
          </span>
        );
      },
    },
    {
      key: 'start',
      header: 'Vigencia',
      value: (s) => s.starts_at,
      render: (s) => <span className="cmd-dim">desde {fmtDate(s.starts_at)}</span>,
      sub: (s) =>
        [
          s.ends_at ? `hasta ${fmtDate(s.ends_at)}` : 'sin fin',
          s.trial_ends_at ? `trial hasta ${fmtDate(s.trial_ends_at)}` : null,
        ]
          .filter(Boolean)
          .join(' · '),
    },
  ];

  const filters: FilterDef<Subscription>[] = [
    {
      id: 'account',
      label: 'Cuenta',
      options: [...accounts]
        .sort((a, b) => a.name.localeCompare(b.name, 'es'))
        .map((a) => ({ value: String(a.id), label: a.name })),
      test: (s, v) => String(s.account_id) === v,
    },
    {
      id: 'app',
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
      id: 'cap',
      label: 'Capacidad',
      all: 'Indiferente',
      options: [
        { value: 'full', label: 'Agotada' },
        { value: 'free', label: 'Con hueco' },
      ],
      test: (s, v) => (isCapacityFull(s, activeItems.get(s.id) ?? 0) ? 'full' : 'free') === v,
    },
  ];

  const rowActions = (s: Subscription): RowAction[] => [
    { label: 'Abrir ficha', onClick: () => navigate(`/subscriptions/${s.id}`) },
    // Solo un contrato negociado admite cabeceras nuevas: el de catálogo lo crea el alta.
    ...(can('provision instances') && s.mode === 'negotiated'
      ? [{ label: 'Añadir cabecera', onClick: () => navigate(`/provisioning/new?subscription=${s.id}`) }]
      : []),
    ...(can('index invoices')
      ? [{ label: 'Facturas de la cuenta', onClick: () => navigate(`/invoices?account=${s.account_id}`) }]
      : []),
  ];

  return (
    <ListPage
      perm="index subscriptions"
      title="Contratos"
      lede="Un contrato de catálogo nace del alta normal; uno negociado se crea aquí y agrupa varias cabeceras bajo una capacidad."
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
        searchHint="Nombre del contrato o cuenta"
        loading={res.loading}
        refreshing={res.refreshing}
        error={res.error}
        onRetry={res.reload}
        rowActions={rowActions}
      />
    </ListPage>
  );
}
