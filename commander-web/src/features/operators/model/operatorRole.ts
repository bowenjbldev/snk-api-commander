/**
 * Commander solo siembra el rol `admin` y no expone endpoint de roles. El rótulo es el
 * propio valor: es el que se escribe en la API y el que muestra el formulario.
 */
export const OPERATOR_ROLE_CATALOG = {
  admin: { label: 'admin', tone: 'blue' },
} as const;
