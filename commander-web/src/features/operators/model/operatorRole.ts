/**
 * Commander solo siembra el rol `admin` y no expone endpoint de roles.
 * Este catálogo existe para traducirlo, no para insinuar que hay más.
 */
export const OPERATOR_ROLE_CATALOG = {
  admin: { label: 'Administrador', tone: 'blue' },
} as const;

export const OPERATOR_FIXED_ROLE = 'admin';
