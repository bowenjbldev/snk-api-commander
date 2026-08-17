import { useState } from 'react';
import { InlineNotification } from '@carbon/react';
import { useApp } from '@app/provider';
import { FormPage } from '@shared/components/FormPage';
import type { FormSection } from '@shared/components/FormPage';
import { Forbidden } from '@shared/components/States';
import { settingsValues } from '../model/settingsForm';
import { useSettingsService } from '../services/settingsService';

const sections: FormSection[] = [
  {
    legend: 'Operador de referencia',
    fields: [
      { key: 'operator_name', label: 'Nombre' },
      { key: 'operator_email', label: 'Correo', type: 'email' },
    ],
  },
  {
    legend: 'Operación',
    fields: [
      {
        key: 'default_grace_hours',
        label: 'Horas de gracia por defecto',
        type: 'number',
        helper: 'Entre 1 y 720 horas.',
      },
    ],
  },
  {
    legend: 'Avisos',
    fields: [
      { key: 'notify_suspensions', label: 'Notificar suspensiones', type: 'checkbox' },
      { key: 'notify_overdue_invoices', label: 'Notificar facturas vencidas', type: 'checkbox' },
    ],
  },
];

export function SettingsPage() {
  const { store, can } = useApp();
  const settingsService = useSettingsService();
  const [saved, setSaved] = useState(false);

  if (!can('show settings')) return <Forbidden perm="show settings" />;

  return (
    <>
      {saved && (
        <InlineNotification
          kind="success"
          lowContrast
          title="Preferencias guardadas"
          subtitle="Los avisos son configuración almacenada; no garantizan un canal de envío que todavía no existe."
          onClose={() => setSaved(false)}
        />
      )}
      <FormPage
        title="Preferencias"
        lede="Singleton del control plane. Se guarda parcialmente con PUT /settings."
        crumbs={[{ label: 'Administración' }, { label: 'Preferencias' }]}
        cancelTo="/"
        submitLabel="Guardar preferencias"
        initial={settingsValues(store.settings)}
        sections={sections}
        onSubmit={async (values) => {
          await settingsService.save(values);
          setSaved(true);
        }}
      />
    </>
  );
}
