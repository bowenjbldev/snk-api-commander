import type { FormSection } from '@shared/components/FormPage';
import type { Operator } from '@services/api/types';

/**
 * Lo que el formulario tiene en pantalla: todo texto, porque eso es lo que devuelve
 * un input. La conversión al payload de la API vive abajo, no en el componente.
 */
export type OperatorFormValues = {
  name: string;
  email: string;
  short_name: string;
  company: string;
  phone: string;
  password: string;
};

export type OperatorPayload = Partial<Operator> & { id?: number; password?: string };

export function emptyOperatorValues(operator?: Operator | null): OperatorFormValues {
  return {
    name: operator?.name ?? '',
    email: operator?.email ?? '',
    short_name: operator?.short_name ?? '',
    company: operator?.company ?? '',
    phone: operator?.phone ?? '',
    password: '',
  };
}

/**
 * Una contraseña vacía al editar significa «no la cambies», no «déjala en blanco»:
 * por eso se omite del payload en vez de enviarse vacía.
 */
export function toOperatorPayload(values: OperatorFormValues, id?: number): OperatorPayload {
  const payload: OperatorPayload = {
    id,
    name: values.name.trim(),
    email: values.email.trim(),
    short_name: values.short_name.trim(),
    company: values.company.trim(),
    phone: values.phone.trim(),
  };
  if (values.password) payload.password = values.password;
  return payload;
}

export function operatorFormSections(editing: boolean): FormSection[] {
  return [
    {
      legend: 'Identidad',
      fields: [
        { key: 'name', label: 'Nombre completo' },
        { key: 'email', label: 'Correo', type: 'email' },
        { key: 'short_name', label: 'Nombre corto' },
      ],
    },
    {
      legend: 'Organización',
      fields: [
        { key: 'company', label: 'Empresa' },
        { key: 'phone', label: 'Teléfono' },
      ],
    },
    {
      legend: 'Acceso',
      fields: [
        {
          key: 'password',
          label: editing ? 'Nueva contraseña (opcional)' : 'Contraseña',
          type: 'password',
          helper: 'Al menos doce caracteres. No se muestra ni se recupera después.',
        },
      ],
    },
  ];
}
