import { SkeletonText } from '@carbon/react';
import { useNavigate, useParams } from 'react-router-dom';
import { ENTITY_STATUS } from '@services/api/mock/commander-mock';
import { useApp } from '@app/provider';
import { useResource } from '@shared/hooks/useResource';
import { pending } from '@shared/lib/data';
import { FormPage } from '@shared/components/FormPage';
import type { FormSection } from '@shared/components/FormPage';
import { ErrorState, Forbidden } from '@shared/components/States';
import { emptyClientValues } from '../model/clientForm';
import { useClientsService } from '../services/clientsService';

function clientSections(accounts: { id: number; name: string }[], editing: boolean): FormSection[] {
  return [
    {
      legend: 'Identidad',
      fields: [
        {
          key: 'account_id',
          label: 'Cuenta pagadora',
          type: 'select',
          options: accounts.map((a) => ({ value: String(a.id), label: a.name })),
          readOnly: editing,
          helper: editing ? 'Inmutable tras el alta.' : undefined,
        },
        {
          key: 'slug',
          label: 'Slug (subdominio)',
          readOnly: editing,
          helper: editing ? 'Inmutable tras el alta.' : 'Minúsculas, números y guiones (3–40).',
        },
        { key: 'name', label: 'Nombre' },
        {
          key: 'status',
          label: 'Estado',
          type: 'select',
          options: Object.entries(ENTITY_STATUS).map(([value, meta]) => ({ value, label: meta.label })),
        },
      ],
    },
    { legend: 'Contacto', fields: [{ key: 'email', label: 'Correo de contacto', type: 'email' }] },
    { legend: 'Notas internas', fields: [{ key: 'notes', label: 'Notas', type: 'textarea' }] },
  ];
}

export function ClientFormPage() {
  const { can } = useApp();
  const clientsService = useClientsService();
  const { id } = useParams();
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
      lede="El slug es el subdominio técnico y queda inmutable tras el alta, igual que la cuenta pagadora: cambiarlos rompería URLs y facturación."
      crumbs={[{ label: 'Clientes', to: '/clients' }, { label: editing ? 'Editar' : 'Nuevo' }]}
      cancelTo={editing ? `/clients/${id}` : '/clients'}
      submitLabel={editing ? 'Guardar cambios' : 'Crear cliente'}
      initial={emptyClientValues(client, accounts[0]?.id)}
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
