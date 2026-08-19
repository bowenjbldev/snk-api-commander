import { useMemo } from 'react';
import { Button } from '@carbon/react';
import { Add } from '@carbon/icons-react';
import { useNavigate } from 'react-router-dom';
import { Link } from '@shared/components/AppLink';
import { ENTITY_STATUS, fmtDate, fmtNum } from '@services/api/mock/commander-mock';
import type { Client } from '@services/api/types';
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

  // Las aplicaciones de un cliente son las de sus instancias: sirven a la vez para la
  // columna de conteo y para el filtro, así que se agrupan una sola vez.
  const appsByClient = useMemo(() => {
    const map = new Map<number, number[]>();
    for (const i of instances) map.set(i.client_id, [...(map.get(i.client_id) ?? []), i.application_id]);
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
    { key: 'email', header: 'Correo', value: (c) => c.email, render: (c) => <span className="cmd-dim">{c.email}</span> },
    { key: 'status', header: 'Estado', value: (c) => c.status, render: (c) => <StatusTag catalog={ENTITY_STATUS} value={c.status} /> },
    {
      key: 'inst',
      header: 'Instancias',
      align: 'right',
      value: (c) => appsByClient.get(c.id)?.length ?? 0,
      render: (c) => fmtNum(appsByClient.get(c.id)?.length ?? 0),
    },
    {
      key: 'updated',
      header: 'Actualización',
      value: (c) => c.updated_at,
      render: (c) => <span className="cmd-dim">{fmtDate(c.updated_at)}</span>,
    },
  ];

  const filters: FilterDef<Client>[] = [
    {
      id: 'account',
      label: 'Cuenta',
      options: [...accounts]
        .sort((a, b) => a.name.localeCompare(b.name, 'es'))
        .map((a) => ({ value: String(a.id), label: a.name })),
      test: (c, v) => String(c.account_id) === v,
    },
    {
      id: 'status',
      // Un cliente se da de baja desactivándolo: «suspendido» es un estado de servicio
      // de la instancia, no del tenant, y por eso no se ofrece aquí.
      label: 'Estado',
      options: ['active', 'inactive'].map((value) => ({ value, label: ENTITY_STATUS[value].label })),
      test: (c, v) => c.status === v,
    },
    {
      id: 'app',
      label: 'Aplicación',
      options: applications.map((a) => ({ value: String(a.id), label: a.name })),
      test: (c, v) => (appsByClient.get(c.id) ?? []).some((appId) => String(appId) === v),
    },
  ];

  return (
    <ListPage
      perm="index clients"
      title="Clientes"
      lede="El cliente es el tenant. Commander no administra sus usuarios, roles ni contenido: eso vive en la base aislada de cada instancia."
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
        searchHint="Nombre, slug o correo"
        loading={res.loading}
        refreshing={res.refreshing}
        error={res.error}
        onRetry={res.reload}
        rowActions={(c) => [
          { label: 'Abrir ficha', onClick: () => navigate(`/clients/${c.id}`) },
          ...(can('update clients')
            ? [{ label: 'Editar', onClick: () => navigate(`/clients/${c.id}/edit`) }]
            : []),
          ...(can('provision instances')
            ? [{ label: 'Añadir aplicación', onClick: () => navigate(`/provisioning/new?client=${c.id}`) }]
            : []),
        ]}
      />
    </ListPage>
  );
}
