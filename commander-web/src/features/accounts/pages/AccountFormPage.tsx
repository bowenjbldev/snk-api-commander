import { SkeletonText } from '@carbon/react';
import { useNavigate, useParams } from 'react-router-dom';
import { ENTITY_STATUS } from '@services/api/mock/commander-mock';
import { useApp } from '@app/provider';
import { useResource } from '@shared/hooks/useResource';
import { FormPage } from '@shared/components/FormPage';
import type { FormSection } from '@shared/components/FormPage';
import { ErrorState, Forbidden } from '@shared/components/States';
import { PAYMENT_TERMS, emptyAccountValues } from '../model/accountForm';
import { useAccountsService } from '../services/accountsService';

const sections: FormSection[] = [
  {
    legend: 'Identidad y estado',
    fields: [
      { key: 'name', label: 'Nombre' },
      {
        key: 'status',
        label: 'Estado',
        type: 'select',
        options: Object.entries(ENTITY_STATUS).map(([value, meta]) => ({ value, label: meta.label })),
      },
    ],
  },
  {
    legend: 'Contacto',
    fields: [
      { key: 'email', label: 'Correo', type: 'email' },
      { key: 'phone', label: 'Teléfono' },
    ],
  },
  {
    legend: 'Facturación y condiciones de pago',
    fields: [
      { key: 'billing_email', label: 'Correo de facturación', type: 'email' },
      { key: 'tax_id', label: 'NIF', helper: 'Letra y ocho dígitos.' },
      {
        key: 'payment_terms',
        label: 'Condiciones de pago',
        type: 'select',
        options: PAYMENT_TERMS.map((v) => ({ value: v, label: v })),
      },
    ],
  },
  {
    legend: 'Dirección',
    fields: [
      { key: 'address', label: 'Dirección' },
      { key: 'country', label: 'País' },
    ],
  },
  { legend: 'Notas internas', fields: [{ key: 'notes', label: 'Notas', type: 'textarea' }] },
];

export function AccountFormPage() {
  const { can } = useApp();
  const accountsService = useAccountsService();
  const { id } = useParams();
  const navigate = useNavigate();
  const editing = Boolean(id);
  const perm = editing ? 'update accounts' : 'create accounts';

  const res = useResource(() => (id ? accountsService.get(id) : Promise.resolve(null)), [id]);

  if (!can(perm)) return <Forbidden perm={perm} />;
  if (res.error) return <ErrorState error={res.error} onRetry={res.reload} />;
  if (editing && (res.loading || !res.data)) return <SkeletonText paragraph lineCount={8} />;

  return (
    <FormPage
      title={editing ? 'Editar cuenta' : 'Nueva cuenta'}
      lede="Formulario largo con impacto contractual: página dedicada, no diálogo. La cuenta es quien paga; no contiene datos de negocio de ningún tenant."
      crumbs={[{ label: 'Cuentas', to: '/accounts' }, { label: editing ? 'Editar' : 'Nueva' }]}
      cancelTo={editing ? `/accounts/${id}` : '/accounts'}
      submitLabel={editing ? 'Guardar cambios' : 'Crear cuenta'}
      initial={emptyAccountValues(res.data)}
      sections={sections}
      onSubmit={async (values) => {
        const saved = editing
          ? await accountsService.update(Number(id), values)
          : await accountsService.create(values);
        navigate(`/accounts/${saved.id}`);
      }}
    />
  );
}
