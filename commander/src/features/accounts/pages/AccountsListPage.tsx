import { useMemo } from 'react';
import { Button } from '@carbon/react';
import { Add } from '@carbon/icons-react';
import { Link, useNavigate } from 'react-router-dom';
import { ENTITY_STATUS, fmtRel } from '@services/api/mock/commander-mock';
import type { Account } from '@services/api/types';
import { useApp } from '@app/provider';
import { useResource } from '@shared/hooks/useResource';
import { pending } from '@shared/lib/data';
import { DataView } from '@shared/components/DataView';
import type { Column, FilterDef } from '@shared/components/DataView';
import { ListPage } from '@shared/components/ListPage';
import { StatusTag } from '@shared/components/StatusTag';
import { countByAccount } from '../model/accountTotals';
import { useAccountsService } from '../services/accountsService';

export function AccountsListPage() {
  const { can } = useApp();
  const accountsService = useAccountsService();
  const navigate = useNavigate();
  const res = useResource(() => accountsService.listWithRelations(), []);
  const [accounts, clients, subscriptions] = pending(res.data, [[], [], [], []]);

  const counts = useMemo(() => countByAccount(clients, subscriptions), [clients, subscriptions]);

  const columns: Column<Account>[] = [
    {
      key: 'name',
      header: 'Nombre',
      value: (a) => a.name,
      render: (a) => <Link to={`/accounts/${a.id}`}>{a.name}</Link>,
    },
    { key: 'status', header: 'Estado', value: (a) => a.status, render: (a) => <StatusTag catalog={ENTITY_STATUS} value={a.status} /> },
    { key: 'email', header: 'Correo', value: (a) => a.email },
    { key: 'billing_email', header: 'Facturación', value: (a) => a.billing_email },
    { key: 'tax_id', header: 'NIF', value: (a) => a.tax_id, render: (a) => <span className="cmd-mono">{a.tax_id}</span> },
    { key: 'payment_terms', header: 'Condiciones', value: (a) => a.payment_terms },
    { key: 'clients', header: 'Clientes', align: 'right', value: (a) => counts.get(a.id)?.clients ?? 0 },
    { key: 'subs', header: 'Contratos', align: 'right', value: (a) => counts.get(a.id)?.subscriptions ?? 0 },
    { key: 'updated_at', header: 'Actualización', value: (a) => a.updated_at, render: (a) => fmtRel(a.updated_at) },
  ];

  const filters: FilterDef<Account>[] = [
    {
      id: 'status',
      label: 'Estado',
      options: Object.entries(ENTITY_STATUS).map(([value, meta]) => ({ value, label: meta.label })),
      test: (a, v) => a.status === v,
    },
    {
      id: 'country',
      label: 'País',
      options: [...new Set(accounts.map((a) => a.country))].map((c) => ({ value: c, label: c })),
      test: (a, v) => a.country === v,
    },
    {
      id: 'contracts',
      label: 'Contratos',
      options: [
        { value: 'with', label: 'Con contratos' },
        { value: 'without', label: 'Sin contratos' },
      ],
      test: (a, v) => ((counts.get(a.id)?.subscriptions ?? 0) > 0 ? v === 'with' : v === 'without'),
    },
  ];

  return (
    <ListPage
      perm="index accounts"
      title="Cuentas"
      lede="La cuenta es quien paga. Agrupa clientes, contratos y facturas; no contiene datos de negocio de ningún tenant."
      actions={
        can('create accounts') && (
          <Button renderIcon={Add} onClick={() => navigate('/accounts/new')}>
            Nueva cuenta
          </Button>
        )
      }
    >
      <DataView
        label="Cuentas"
        rows={accounts}
        columns={columns}
        filters={filters}
        search={(a) => `${a.name} ${a.email} ${a.billing_email} ${a.tax_id}`}
        defaultSort={{ key: 'name', dir: 'asc' }}
        loading={res.loading}
        refreshing={res.refreshing}
        error={res.error}
        onRetry={res.reload}
        rowActions={(a) => [
          { label: 'Abrir ficha', onClick: () => navigate(`/accounts/${a.id}`) },
          { label: 'Facturas de la cuenta', onClick: () => navigate(`/accounts/${a.id}/invoices`) },
        ]}
        emptyTitle="Todavía no hay cuentas"
        emptyBody="Una cuenta nace al dar de alta el primer cliente o creándola a mano antes de negociar un contrato."
      />
    </ListPage>
  );
}
