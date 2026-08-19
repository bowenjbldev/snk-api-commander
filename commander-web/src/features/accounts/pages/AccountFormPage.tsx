import { SkeletonText } from '@carbon/react';
import { useNavigate, useParams } from 'react-router-dom';
import { useApp } from '@app/provider';
import { useResource } from '@shared/hooks/useResource';
import { FormPage } from '@shared/components/FormPage';
import type { FormSection } from '@shared/components/FormPage';
import { ErrorState, Forbidden } from '@shared/components/States';
import {
  ACCOUNT_STATUS_OPTIONS,
  COUNTRY_OPTIONS,
  PAYMENT_TERMS,
  emptyAccountValues,
} from '../model/accountForm';
import { useAccountsService } from '../services/accountsService';

const sections: FormSection[] = [
  {
    legend: 'Identidad y estado',
    fields: [
      { key: 'name', label: 'Nombre' },
      { key: 'status', label: 'Estado', type: 'select', options: ACCOUNT_STATUS_OPTIONS },
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
      { key: 'tax_id', label: 'NIF', helper: 'Letra y ocho dígitos' },
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
      { key: 'country', label: 'País', type: 'select', options: COUNTRY_OPTIONS },
    ],
  },
  {
    legend: 'Notas internas',
    help: 'Solo las ve el operador. No se envían al tenant.',
    fields: [{ key: 'notes', label: 'Nota', type: 'textarea', span: 'full' }],
  },
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
      lede="Formulario largo con impacto contractual: página dedicada, no diálogo."
      crumbs={[{ label: 'Cuentas', to: '/accounts' }]}
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
