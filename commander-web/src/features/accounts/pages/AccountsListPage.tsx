import { useMemo } from 'react';
import { Button } from '@carbon/react';
import { useNavigate } from 'react-router-dom';
import { Link } from '@shared/components/AppLink';
import { ENTITY_STATUS, fmtDate } from '@services/api/mock/commander-mock';
import type { Account } from '@services/api/types';
import { useApp } from '@app/provider';
import { useResource } from '@shared/hooks/useResource';
import { pending } from '@shared/lib/data';
import { DataView } from '@shared/components/DataView';
import type { Column, FilterDef, RowAction } from '@shared/components/DataView';
import { ListPage } from '@shared/components/ListPage';
import { StatusTag } from '@shared/components/StatusTag';
import { ACCOUNT_STATUS_OPTIONS, COUNTRY_OPTIONS } from '../model/accountForm';
import { countByAccount } from '../model/accountTotals';
import { useAccountsService } from '../services/accountsService';

/** La dirección es texto libre: lo que va tras la última coma sitúa la cuenta. */
const town = (address: string) => address.split(',').slice(-1)[0].trim();

export function AccountsListPage() {
  const { can } = useApp();
  const accountsService = useAccountsService();
  const navigate = useNavigate();
  const res = useResource(() => accountsService.listWithRelations(), []);
  const [accounts, clients, subscriptions] = pending(res.data, [[], [], []]);

  const counts = useMemo(() => countByAccount(clients, subscriptions), [clients, subscriptions]);

  // Correo y facturación viajan en la misma columna, igual que NIF y condiciones: el
  // segundo dato baja a la línea de apoyo en vez de abrir una columna más.
  const columns: Column<Account>[] = [
    {
      key: 'name',
      header: 'Nombre',
      value: (a) => a.name.toLowerCase(),
      render: (a) => <Link to={`/accounts/${a.id}`}>{a.name}</Link>,
      sub: (a) => (a.address ? town(a.address) : null),
    },
    {
      key: 'status',
      header: 'Estado',
      value: (a) => a.status,
      render: (a) => <StatusTag catalog={ENTITY_STATUS} value={a.status} />,
    },
    {
      key: 'email',
      header: 'Correo y facturación',
      value: (a) => a.email,
      render: (a) => <span className="cmd-dim">{a.email}</span>,
      sub: (a) => (a.billing_email === a.email ? 'misma para facturación' : a.billing_email),
    },
    {
      key: 'tax',
      header: 'NIF y condiciones',
      value: (a) => a.tax_id,
      render: (a) => <span className="cmd-mono cmd-dim">{a.tax_id}</span>,
      sub: (a) => a.payment_terms,
    },
    { key: 'clients', header: 'Clientes', align: 'right', value: (a) => counts.get(a.id)?.clients ?? 0 },
    { key: 'subs', header: 'Contratos', align: 'right', value: (a) => counts.get(a.id)?.subscriptions ?? 0 },
    {
      key: 'updated',
      header: 'Actualización',
      value: (a) => a.updated_at,
      render: (a) => <span className="cmd-dim">{fmtDate(a.updated_at)}</span>,
    },
  ];

  const filters: FilterDef<Account>[] = [
    { id: 'status', label: 'Estado', options: ACCOUNT_STATUS_OPTIONS, test: (a, v) => a.status === v },
    { id: 'country', label: 'País', options: COUNTRY_OPTIONS, test: (a, v) => a.country === v },
    {
      id: 'subs',
      label: 'Contratos',
      all: 'Indiferente',
      options: [
        { value: 'yes', label: 'Con contratos' },
        { value: 'no', label: 'Sin contratos' },
      ],
      test: (a, v) => ((counts.get(a.id)?.subscriptions ?? 0) > 0 ? 'yes' : 'no') === v,
    },
  ];

  const rowActions = (a: Account): RowAction[] => {
    const actions: RowAction[] = [{ label: 'Abrir ficha', onClick: () => navigate(`/accounts/${a.id}`) }];
    if (can('update accounts')) {
      actions.push({ label: 'Editar', onClick: () => navigate(`/accounts/${a.id}/edit`) });
    }
    if (can('create clients')) {
      actions.push({ label: 'Añadir cliente', onClick: () => navigate(`/clients/new?account=${a.id}`) });
    }
    return actions;
  };

  return (
    <ListPage
      perm="index accounts"
      title="Cuentas"
      lede="La cuenta es el pagador: agrupa clientes, contratos y facturas. No contiene datos de negocio de ningún tenant."
      actions={
        can('create accounts') && <Button onClick={() => navigate('/accounts/new')}>Nueva cuenta</Button>
      }
    >
      <DataView
        label="Cuentas"
        rows={accounts}
        columns={columns}
        filters={filters}
        search={(a) => `${a.name} ${a.email} ${a.billing_email} ${a.tax_id}`}
        searchHint="Nombre, correo, correo de facturación o NIF"
        loading={res.loading}
        refreshing={res.refreshing}
        error={res.error}
        onRetry={res.reload}
        rowActions={rowActions}
      />
    </ListPage>
  );
}
