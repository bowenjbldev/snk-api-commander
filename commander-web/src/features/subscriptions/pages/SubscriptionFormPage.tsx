import { SkeletonText } from '@carbon/react';
import { useNavigate, useSearchParams } from 'react-router-dom';
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
      legend: 'Ámbito',
      help: 'El alta normal ya crea un contrato de catálogo: crear otro a mano puede duplicar el cobro.',
      fields: [
        {
          key: 'account_id',
          label: 'Cuenta',
          type: 'select',
          options: [{ value: '', label: 'Elige una cuenta' }].concat(
            [...accounts]
              .sort((a, b) => a.name.localeCompare(b.name, 'es'))
              .map((a) => ({ value: String(a.id), label: a.name })),
          ),
        },
        {
          key: 'application_id',
          label: 'Aplicación',
          type: 'select',
          options: [{ value: '', label: 'Elige una aplicación' }].concat(
            applications.map((a) => ({ value: String(a.id), label: a.name })),
          ),
        },
        { key: 'name', label: 'Nombre del contrato' },
        {
          key: 'mode',
          label: 'Modalidad',
          type: 'select',
          options: [
            { value: 'negotiated', label: SUBSCRIPTION_MODE_CATALOG.negotiated.label },
            { value: 'catalog', label: SUBSCRIPTION_MODE_CATALOG.catalog.label },
          ],
        },
        {
          key: 'status',
          label: 'Estado',
          type: 'select',
          options: Object.entries(SUB_STATUS).map(([value, meta]) => ({ value, label: meta.label })),
        },
      ],
    },
    {
      legend: 'Precio y capacidad',
      fields: [
        // Campos de texto, no `number`: un importe vacío tiene que poder viajar vacío
        // para que el 422 del servidor sea el que hable, y así lo pide el resto del SPA.
        {
          key: 'monthly_amount',
          label: 'Importe mensual',
          helper: 'En euros. El precio pactado no se recalcula al cambiar el plan de una cabecera.',
        },
        {
          key: 'max_instances',
          label: 'Capacidad (cabeceras)',
          helper: 'Nunca por debajo del número de ítems activos.',
        },
        { key: 'starts_at', label: 'Inicio', helper: 'AAAA-MM-DD.' },
      ],
    },
  ];
}

export function SubscriptionFormPage() {
  const { can } = useApp();
  const subscriptionsService = useSubscriptionsService();
  const navigate = useNavigate();
  const [params] = useSearchParams();
  const res = useResource(() => subscriptionsService.listCatalog(), []);
  const [accounts, applications] = pending(res.data, [[], []]);

  if (!can('create subscriptions')) return <Forbidden perm="create subscriptions" />;
  if (res.error) return <ErrorState error={res.error} onRetry={res.reload} />;
  if (res.loading) return <SkeletonText paragraph lineCount={6} />;

  return (
    <FormPage
      title="Nuevo contrato"
      lede="Para contratos negociados o de catálogo creados a mano. Cuenta, aplicación y modalidad son inmutables después de crear."
      crumbs={[{ label: 'Contratos', to: '/subscriptions' }]}
      cancelTo="/subscriptions"
      submitLabel="Crear contrato"
      initial={emptySubscriptionValues(params.get('account') ?? undefined)}
      sections={sectionsFor(accounts, applications)}
      onSubmit={async (values) => {
        const saved = await subscriptionsService.create(values);
        navigate(`/subscriptions/${saved.id}`);
      }}
    />
  );
}
