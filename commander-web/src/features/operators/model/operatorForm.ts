import type { Operator } from '@services/api/types';

/** Empresa del alta: todos los operadores sembrados pertenecen a la misma. */
const DEFAULT_COMPANY = 'Commander SL';

/** Único rol sembrado. No hay endpoint de catálogo del que sacar otros. */
const FIXED_ROLE = 'admin';

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
  role: string;
  password: string;
};

export type OperatorPayload = Partial<Operator> & { password?: string };

export function emptyOperatorValues(operator?: Operator | null): OperatorFormValues {
  return {
    name: operator?.name ?? '',
    email: operator?.email ?? '',
    short_name: operator?.short_name ?? '',
    company: operator?.company ?? DEFAULT_COMPANY,
    phone: operator?.phone ?? '',
    role: operator?.role ?? FIXED_ROLE,
    password: '',
  };
}

/**
 * El rol no viaja: no es editable y el alta lo fija el servidor. Una contraseña vacía
 * al editar significa «no la cambies», no «déjala en blanco», por eso se omite del
 * payload en vez de enviarse vacía.
 *
 * El alta no lleva ni la clave `id`: el servidor mezcla el payload SOBRE la fila que
 * acaba de numerar, así que un `id: undefined` explícito le borraría el identificador.
 */
export function toOperatorPayload(values: OperatorFormValues, id?: number): OperatorPayload {
  const payload: OperatorPayload = {
    name: values.name.trim(),
    email: values.email.trim(),
    short_name: values.short_name.trim(),
    company: values.company.trim(),
    phone: values.phone.trim(),
  };
  if (id) payload.id = id;
  if (values.password) payload.password = values.password;
  return payload;
}
