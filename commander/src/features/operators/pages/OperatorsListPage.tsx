import { Button } from '@carbon/react';
import { Add } from '@carbon/icons-react';
import { Link, useNavigate } from 'react-router-dom';
import { fmtDate } from '@services/api/mock/commander-mock';
import type { Operator } from '@services/api/types';
import { useApp } from '@app/provider';
import { useResource } from '@shared/hooks/useResource';
import { DataView } from '@shared/components/DataView';
import type { Column } from '@shared/components/DataView';
import { ListPage } from '@shared/components/ListPage';
import { StatusTag } from '@shared/components/StatusTag';
import { OPERATOR_ROLE_CATALOG } from '../model/operatorRole';
import { useOperatorsService } from '../services/operatorsService';

const columns: Column<Operator>[] = [
  {
    key: 'name',
    header: 'Nombre completo',
    value: (o) => o.name,
    render: (o) => <Link to={`/operators/${o.id}/edit`}>{o.name}</Link>,
  },
  { key: 'email', header: 'Correo', value: (o) => o.email },
  { key: 'short_name', header: 'Nombre corto', value: (o) => o.short_name },
  {
    key: 'role',
    header: 'Rol',
    value: (o) => o.role,
    render: (o) => <StatusTag catalog={OPERATOR_ROLE_CATALOG} value={o.role} />,
  },
  { key: 'company', header: 'Empresa', value: (o) => o.company },
  { key: 'phone', header: 'Teléfono', value: (o) => o.phone },
  { key: 'created_at', header: 'Alta', value: (o) => o.created_at, render: (o) => fmtDate(o.created_at) },
];

export function OperatorsListPage() {
  const { can } = useApp();
  const operators = useOperatorsService();
  const navigate = useNavigate();
  const res = useResource(() => operators.list(), []);

  return (
    <ListPage
      perm="index users"
      title="Operadores"
      lede="Usuarios de Commander. Solo existe el rol admin sembrado: mientras no haya endpoint de roles, el formulario no inventa un selector."
      actions={
        can('create users') && (
          <Button renderIcon={Add} onClick={() => navigate('/operators/new')}>
            Nuevo operador
          </Button>
        )
      }
    >
      <DataView
        label="Operadores"
        rows={res.data}
        columns={columns}
        search={(o) => `${o.name} ${o.email} ${o.company}`}
        defaultSort={{ key: 'name', dir: 'asc' }}
        loading={res.loading}
        refreshing={res.refreshing}
        error={res.error}
        onRetry={res.reload}
        rowActions={(o) => [{ label: 'Editar operador', onClick: () => navigate(`/operators/${o.id}/edit`) }]}
      />
    </ListPage>
  );
}
