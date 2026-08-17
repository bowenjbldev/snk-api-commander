import type { Settings } from '@services/api/types';

export type SettingsFormValues = {
  operator_name: string;
  operator_email: string;
  default_grace_hours: number | string;
  notify_suspensions: boolean;
  notify_overdue_invoices: boolean;
};

export function settingsValues(settings: Settings): SettingsFormValues {
  return {
    operator_name: settings.operator_name,
    operator_email: settings.operator_email,
    default_grace_hours: settings.default_grace_hours,
    notify_suspensions: settings.notify_suspensions,
    notify_overdue_invoices: settings.notify_overdue_invoices,
  };
}

export function toSettingsPayload(values: SettingsFormValues): Partial<Settings> {
  return {
    operator_name: values.operator_name.trim(),
    operator_email: values.operator_email.trim(),
    default_grace_hours: Number(values.default_grace_hours),
    notify_suspensions: Boolean(values.notify_suspensions),
    notify_overdue_invoices: Boolean(values.notify_overdue_invoices),
  };
}
