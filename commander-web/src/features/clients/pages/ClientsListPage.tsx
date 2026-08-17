import { useMemo } from 'react';
import { Button } from '@carbon/react';
import { Add } from '@carbon/icons-react';
import { useNavigate } from 'react-router-dom';
import { Link } from '@shared/components/AppLink';
import { ENTITY_STATUS, fmtRel } from '@services/api/mock/commander-mock';
import type { Client, Instance } from '@services/api/types';
import { useApp } from '@app/provider';
import { useResource } from '@shared/hooks/useResource';
import { lut, pending } from '@shared/lib/data';
import { DataView } from '@shared/components/DataView';
import type { Column, FilterDef } from '@shared/components/DataView';
import { ListPage } from '@shared/components/ListPage';
import { StatusTag } from '@shared/components/StatusTag';
import { useClientsService } from '../services/clientsService';

export function ClientsListPage() {
  const { can } = useApp();
  const clientsService = useClientsService();
  const navigate = useNavigate();
  const res = useResource(() => clientsService.listWithRelations(), []);
  const [clients, accounts, instances, applications] = pending(res.data, [[], [], [], []]);
  const accById = useMemo(() => lut(accounts), [accounts]);

  const byClient = useMemo(() => {
    const map = new Map<number, Instance[]>();
    for (const i of instances) map.set(i.client_id, [...(map.get(i.client_id) ?? []), i]);
    return map;
  }, [instances]);

  const columns: Column<Client>[] = [
    { key: 'name', header: 'Nombre', value: (c) => c.name, render: (c) => <Link to={`/clients/${c.id}`}>{c.name}</Link> },
    { key: 'slug', header: 'Subdominio', value: (c) => c.slug, render: (c) => <span className="cmd-mono">{c.slug}</span> },
    {
      key: 'account',
      header: 'Cuenta',
      value: (c) => accById.get(c.account_id)?.name ?? '',
      render: (c) => <Link to={`/accounts/${c.account_id}`}>{accById.get(c.account_id)?.name ?? '—'}</Link>,
    },
    { key: 'email', header: 'Correo', value: (c) => c.email },
    { key: 'status', header: 'Estado', value: (c) => c.status, render: (c) => <StatusTag catalog={ENTITY_STATUS} value={c.status} /> },
    { key: 'instances', header: 'Instancias', align: 'right', value: (c) => byClient.get(c.id)?.length ?? 0 },
    { key: 'updated_at', header: 'Actualización', value: (c) => c.updated_at, render: (c) => fmtRel(c.updated_at) },
  ];

  const filters: FilterDef<Client>[] = [
    {
      id: 'account',
      label: 'Cuenta',
      options: accounts.map((a) => ({ value: String(a.id), label: a.name })),
      test: (c, v) => String(c.account_id) === v,
    },
    {
      id: 'status',
      label: 'Estado',
      options: Object.entries(ENTITY_STATUS).map(([value, meta]) => ({ value, label: meta.label })),
      test: (c, v) => c.status === v,
    },
    {
      id: 'application',
      label: 'Aplicación',
      options: applications.map((a) => ({ value: String(a.id), label: a.name })),
      test: (c, v) => (byClient.get(c.id) ?? []).some((i) => String(i.application_id) === v),
    },
  ];

  return (
    <ListPage
      perm="index clients"
      title="Clientes"
      lede="El cliente es el tenant: su slug es el subdominio técnico y cada par cliente-aplicación es una instancia con base propia."
      actions={
        can('create clients') && (
          <Button renderIcon={Add} onClick={() => navigate('/clients/new')}>
            Nuevo cliente
          </Button>
        )
      }
    >
      <DataView
        label="Clientes"
        rows={clients}
        columns={columns}
        filters={filters}
        search={(c) => `${c.name} ${c.slug} ${c.email}`}
        defaultSort={{ key: 'name', dir: 'asc' }}
        loading={res.loading}
        refreshing={res.refreshing}
        error={res.error}
        onRetry={res.reload}
        rowActions={(c) => [
          { label: 'Abrir ficha', onClick: () => navigate(`/clients/${c.id}`) },
          { label: 'Instancias del cliente', onClick: () => navigate(`/clients/${c.id}/instances`) },
        ]}
        emptyTitle="Todavía no hay clientes"
        emptyBody="Un cliente aparece con el alta de su primera instancia o creándolo antes de aprovisionar."
      />
    </ListPage>
  );
}
