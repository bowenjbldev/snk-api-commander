import { useState } from 'react';
import { Button, ToastNotification } from '@carbon/react';
import { Add } from '@carbon/icons-react';
import { useNavigate } from 'react-router-dom';
import { fmtDate } from '@services/api/mock/commander-mock';
import type { Operator } from '@services/api/types';
import { useApp } from '@app/provider';
import { useResource } from '@shared/hooks/useResource';
import { DataView } from '@shared/components/DataView';
import type { Column, RowAction } from '@shared/components/DataView';
import { ListPage } from '@shared/components/ListPage';
import { StatusTag } from '@shared/components/StatusTag';
import { ActionModal } from '@shared/components/ActionModal';
import { OPERATOR_ROLE_CATALOG } from '../model/operatorRole';
import { useOperatorsService } from '../services/operatorsService';

// No hay ficha de operador: el nombre es texto, no enlace. Rol y teléfono no ordenan
// porque el rol es el mismo para todos y el teléfono no tiene orden útil.
const columns: Column<Operator>[] = [
  { key: 'name', header: 'Nombre completo', value: (o) => o.name.toLowerCase() },
  {
    key: 'email',
    header: 'Correo',
    value: (o) => o.email,
    render: (o) => <span className="cmd-dim">{o.email || '—'}</span>,
  },
  {
    key: 'short',
    header: 'Nombre corto',
    value: (o) => o.short_name,
    render: (o) => <span className="cmd-dim">{o.short_name || '—'}</span>,
  },
  {
    key: 'role',
    header: 'Rol',
    render: (o) => <StatusTag catalog={OPERATOR_ROLE_CATALOG} value={o.role} />,
  },
  {
    key: 'company',
    header: 'Empresa',
    value: (o) => o.company,
    render: (o) => <span className="cmd-dim">{o.company || '—'}</span>,
  },
  {
    key: 'phone',
    header: 'Teléfono',
    render: (o) => <span className="cmd-mono cmd-dim">{o.phone || '—'}</span>,
  },
  {
    key: 'created',
    header: 'Alta',
    value: (o) => o.created_at,
    render: (o) => <span className="cmd-dim">{fmtDate(o.created_at)}</span>,
  },
];

export function OperatorsListPage() {
  const { can, operator } = useApp();
  const operators = useOperatorsService();
  const navigate = useNavigate();
  const res = useResource(() => operators.list(), []);
  const [deleting, setDeleting] = useState<Operator | null>(null);
  const [notice, setNotice] = useState<{
    id: number;
    kind: 'success' | 'warning';
    title: string;
    body: string;
  } | null>(null);

  function rowActions(o: Operator): RowAction[] {
    const actions: RowAction[] = [];
    if (can('update users')) {
      actions.push({ label: 'Editar', onClick: () => navigate(`/operators/${o.id}/edit`) });
    }
    if (can('delete users')) {
      // Borrarse a uno mismo se ofrece deshabilitado en texto en vez de esconderse: el
      // operador tiene que ver por qué esa fila no se comporta como las demás.
      actions.push(
        o.id === operator?.id
          ? {
              label: 'No puedes eliminarte',
              onClick: () =>
                setNotice({
                  id: Date.now(),
                  kind: 'warning',
                  title: 'Operación bloqueada',
                  body: 'La sesión activa no puede eliminarse a sí misma. Esta protección debe existir también en backend.',
                }),
            }
          : { label: 'Borrar', danger: true, onClick: () => setDeleting(o) },
      );
    }
    return actions;
  }

  return (
    <ListPage
      perm="index users"
      title="Operadores"
      lede="Usuarios de Commander. Hoy solo se siembra el rol admin y no existe endpoint de catálogo de roles, así que el formulario usa ese valor fijo."
      actions={
        can('create users') && (
          <Button renderIcon={Add} onClick={() => navigate('/operators/new')}>
            Nuevo operador
          </Button>
        )
      }
    >
      {notice && (
        <ToastNotification
          key={notice.id}
          kind={notice.kind}
          lowContrast
          title={notice.title}
          subtitle={notice.body}
          onClose={() => setNotice(null)}
        />
      )}

      <DataView
        label="Operadores"
        rows={res.data}
        columns={columns}
        search={(o) => `${o.name} ${o.email} ${o.short_name}`}
        searchHint="Nombre o correo"
        loading={res.loading}
        refreshing={res.refreshing}
        error={res.error}
        onRetry={res.reload}
        rowActions={rowActions}
      />

      <ActionModal
        open={deleting !== null}
        kicker={deleting ? `Operador ${deleting.id}` : undefined}
        title="Borrar operador"
        body={
          deleting
            ? `Se elimina el acceso de ${deleting.name}. La sesión activa no puede eliminarse a sí misma, y esa protección debe existir también en backend.`
            : undefined
        }
        danger
        submitLabel="Borrar"
        fields={[
          {
            key: 'confirm',
            label: 'Escribe el correo para confirmar',
            placeholder: deleting?.email,
          },
        ]}
        initial={{ confirm: '' }}
        onClose={() => setDeleting(null)}
        onSubmit={async (data) => {
          // La confirmación se comprueba aquí para poder marcar el campo: el 422 del
          // servidor llegaría igual, pero después de haber intentado el borrado.
          if (data.confirm !== deleting!.email) {
            throw Object.assign(new Error('La confirmación no coincide.'), {
              status: 422,
              fields: { confirm: 'Escribe el correo exacto.' },
            });
          }
          await operators.remove(deleting!.id);
          res.reload();
          setNotice({
            id: Date.now(),
            kind: 'success',
            title: 'Operador borrado',
            body: 'El foco vuelve a la tabla.',
          });
        }}
      />
    </ListPage>
  );
}
