import { SkeletonText } from '@carbon/react';
import { useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { ENTITY_STATUS } from '@services/api/mock/commander-mock';
import type { Account } from '@services/api/types';
import { useApp } from '@app/provider';
import { useResource } from '@shared/hooks/useResource';
import { pending } from '@shared/lib/data';
import { FormPage } from '@shared/components/FormPage';
import type { FormSection } from '@shared/components/FormPage';
import { ErrorState, Forbidden } from '@shared/components/States';
import { emptyClientValues } from '../model/clientForm';
import { useClientsService } from '../services/clientsService';

const STATUS_OPTIONS = ['active', 'inactive'].map((value) => ({
  value,
  label: ENTITY_STATUS[value].label,
}));

function clientSections(accounts: Account[], editing: boolean): FormSection[] {
  return [
    {
      legend: 'Cuenta y ámbito',
      fields: [
        {
          key: 'account_id',
          label: 'Cuenta pagadora',
          type: 'select',
          options: [
            { value: '', label: 'Elige una cuenta' },
            ...[...accounts]
              .sort((a, b) => a.name.localeCompare(b.name, 'es'))
              .map((a) => ({ value: String(a.id), label: a.name })),
          ],
          readOnly: editing,
        },
      ],
    },
    {
      legend: 'Identidad',
      fields: [
        { key: 'name', label: 'Nombre del cliente' },
        {
          key: 'slug',
          label: 'Slug / subdominio',
          readOnly: editing,
          helper: editing ? 'Inmutable tras el alta' : 'Minúsculas, números y guiones. Debe ser único.',
        },
        { key: 'email', label: 'Correo de contacto', type: 'email' },
        { key: 'status', label: 'Estado', type: 'select', options: STATUS_OPTIONS },
      ],
    },
    { legend: 'Notas', fields: [{ key: 'notes', label: 'Nota', type: 'textarea', span: 'full' }] },
  ];
}

export function ClientFormPage() {
  const { can } = useApp();
  const clientsService = useClientsService();
  const { id } = useParams();
  const [query] = useSearchParams();
  const navigate = useNavigate();
  const editing = Boolean(id);
  const perm = editing ? 'update clients' : 'create clients';

  const res = useResource(
    () => Promise.all([id ? clientsService.get(id) : Promise.resolve(null), clientsService.listAccounts()]),
    [id],
  );
  const [client, accounts] = pending(res.data, [null, []]);

  if (!can(perm)) return <Forbidden perm={perm} />;
  if (res.error) return <ErrorState error={res.error} onRetry={res.reload} />;
  if (res.loading || (editing && !client)) return <SkeletonText paragraph lineCount={6} />;

  return (
    <FormPage
      title={editing ? 'Editar cliente' : 'Nuevo cliente'}
      lede={
        editing
          ? 'Cuenta y slug quedan inmutables después del alta.'
          : 'La cuenta pagadora y el slug quedan fijos tras crear el cliente.'
      }
      crumbs={[{ label: 'Clientes', to: '/clients' }]}
      cancelTo={editing ? `/clients/${id}` : '/clients'}
      submitLabel={editing ? 'Guardar cambios' : 'Crear cliente'}
      // El alta desde la ficha de una cuenta llega con ella elegida en la URL.
      initial={emptyClientValues(client, query.get('account'))}
      sections={clientSections(accounts, editing)}
      onSubmit={async (values) => {
        const saved = editing
          ? await clientsService.update(Number(id), values)
          : await clientsService.create(values);
        navigate(`/clients/${saved.id}`);
      }}
    />
  );
}
