/**
 * Vocabulario de estado propio del catálogo. `ENTITY_STATUS` habla en masculino
 * porque sirve a cuentas, clientes y planes; aquí el sujeto es «la aplicación»
 * —y «la métrica»—, así que la etiqueta cambia de género aunque el valor sea el mismo.
 */
export const APPLICATION_STATUS_CATALOG = {
  active: { label: 'Activa', tone: 'green' },
  inactive: { label: 'Inactiva', tone: 'cool-gray' },
  suspended: { label: 'Suspendida', tone: 'red' },
} as const;

/** `code_status` viene del código sincronizado, no de una decisión comercial. */
export const CODE_STATUS_CATALOG = {
  available: { label: 'Disponible', tone: 'blue' },
  missing: { label: 'Ausente', tone: 'red' },
} as const;

/** Bajo el título de la ficha la pastilla va sola: «Disponible» a secas no dice de qué. */
export const CODE_STATUS_HEADLINE = {
  available: { label: 'Código disponible', tone: 'blue' },
  missing: { label: 'Código ausente', tone: 'red' },
} as const;
