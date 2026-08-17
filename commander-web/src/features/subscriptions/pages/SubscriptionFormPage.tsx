import { Callout, SkeletonText } from '@carbon/react';
import { useNavigate } from 'react-router-dom';
import { SUB_STATUS } from '@services/api/mock/commander-mock';
import { useApp } from '@app/provider';
import { useResource } from '@shared/hooks/useResource';
import { pending } from '@shared/lib/data';
import { FormPage } from '@shared/components/FormPage';
import type { FormSection } from '@shared/components/FormPage';
import { ErrorState, Forbidden } from '@shared/components/States';
import { SUBSCRIPTION_MODE_CATALOG } from '../model/subscriptionColumns';
import { emptySubscriptionValues } from '../model/subscriptionForm';
import { useSubscriptionsService } from '../services/subscriptionsService';

function sectionsFor(
  accounts: { id: number; name: string }[],
  applications: { id: number; name: string }[],
): FormSection[] {
  return [
    {
      legend: 'Partes y modalidad',
      fields: [
        {
          key: 'account_id',
          label: 'Cuenta',
          type: 'select',
          options: accounts.map((a) => ({ value: String(a.id), label: a.name })),
          helper: 'Inmutable después de crear.',
        },
        {
          key: 'application_id',
          label: 'Aplicación',
          type: 'select',
          options: applications.map((a) => ({ value: String(a.id), label: a.name })),
          helper: 'Inmutable después de crear.',
        },
        {
          key: 'mode',
          label: 'Modalidad',
          type: 'select',
          options: Object.entries(SUBSCRIPTION_MODE_CATALOG).map(([value, meta]) => ({ value, label: meta.label })),
          helper: 'Inmutable después de crear.',
        },
        { key: 'name', label: 'Nombre reconocible' },
      ],
    },
    {
      legend: 'Precio y capacidad',
      fields: [
        { key: 'monthly_amount', label: 'Importe mensual', type: 'number' },
        { key: 'currency', label: 'Moneda' },
        {
          key: 'max_instances',
          label: 'Capacidad (cabeceras)',
          type: 'number',
          helper: 'Nunca puede quedar por debajo de las cabeceras activas.',
        },
        {
          key: 'status',
          label: 'Estado',
          type: 'select',
          options: Object.entries(SUB_STATUS).map(([value, meta]) => ({ value, label: meta.label })),
        },
      ],
    },
    { legend: 'Vigencia', fields: [{ key: 'starts_at', label: 'Inicio (AAAA-MM-DD)' }] },
    { legend: 'Notas internas', fields: [{ key: 'notes', label: 'Notas', type: 'textarea' }] },
  ];
}

export function SubscriptionFormPage() {
  const { can } = useApp();
  const subscriptionsService = useSubscriptionsService();
  const navigate = useNavigate();
  const res = useResource(() => subscriptionsService.listCatalog(), []);
  const [accounts, applications] = pending(res.data, [[], []]);

  if (!can('create subscriptions')) return <Forbidden perm="create subscriptions" />;
  if (res.error) return <ErrorState error={res.error} onRetry={res.reload} />;
  if (res.loading) return <SkeletonText paragraph lineCount={6} />;

  return (
    <FormPage
      title="Nuevo contrato"
      lede="Para contratos negociados o de catálogo creados a mano. El alta normal ya crea un contrato de catálogo: crear otro aquí duplicaría la facturación."
      crumbs={[{ label: 'Contratos', to: '/subscriptions' }, { label: 'Nuevo' }]}
      cancelTo="/subscriptions"
      submitLabel="Crear contrato"
      initial={emptySubscriptionValues(accounts[0]?.id, applications[0]?.id)}
      sections={sectionsFor(accounts, applications)}
      aside={
        <Callout
          kind="info"
          lowContrast
          title="Cuidado con los duplicados"
          subtitle="El alta de una instancia ya crea su contrato de catálogo. Este formulario es para acuerdos negociados o correcciones manuales."
        />
      }
      onSubmit={async (values) => {
        const saved = await subscriptionsService.create(values);
        navigate(`/subscriptions/${saved.id}`);
      }}
    />
  );
}
