import { useState } from 'react';
import { ToastNotification } from '@carbon/react';
import { useApp } from '@app/provider';
import { FormPage } from '@shared/components/FormPage';
import type { FormSection } from '@shared/components/FormPage';
import { Forbidden } from '@shared/components/States';
import { settingsValues } from '../model/settingsForm';
import { useSettingsService } from '../services/settingsService';

const sections: FormSection[] = [
  {
    legend: 'Operador',
    fields: [
      { key: 'operator_name', label: 'Nombre' },
      { key: 'operator_email', label: 'Correo', type: 'email' },
    ],
  },
  {
    legend: 'Operación',
    fields: [
      { key: 'default_grace_hours', label: 'Horas de gracia por defecto', helper: 'Entre 1 y 720' },
    ],
  },
  {
    legend: 'Notificaciones',
    help: 'Son configuración almacenada. No garantizan un canal de envío que todavía no existe.',
    fields: [
      { key: 'notify_suspensions', label: 'Notificar suspensiones', type: 'checkbox', span: 'full' },
      {
        key: 'notify_overdue_invoices',
        label: 'Notificar facturas vencidas',
        type: 'checkbox',
        span: 'full',
      },
    ],
  },
];

export function SettingsPage() {
  const { store, can } = useApp();
  const settingsService = useSettingsService();
  // Cuenta los guardados en vez de marcarlos: sirve de `key` para que el segundo
  // guardado vuelva a mostrar el aviso en lugar de dejar en pantalla el del primero.
  const [saves, setSaves] = useState(0);

  if (!can('show settings')) return <Forbidden perm="show settings" />;

  return (
    <FormPage
      title="Preferencias"
      lede="Una sola página edita el singleton. Se guarda parcialmente con PUT /settings."
      cancelTo="/"
      submitLabel="Guardar preferencias"
      initial={settingsValues(store.settings)}
      sections={sections}
      onSubmit={async (values) => {
        await settingsService.save(values);
        setSaves((n) => n + 1);
      }}
      // Sin anfitrión global de avisos flotantes, el aviso se queda dentro del
      // formulario, junto al botón que lo provoca.
      aside={
        saves > 0 && (
          <ToastNotification
            key={saves}
            kind="success"
            lowContrast
            title="Preferencias guardadas"
            subtitle="PUT /settings aceptó el guardado parcial."
            onClose={() => setSaves(0)}
          />
        )
      }
    />
  );
}
