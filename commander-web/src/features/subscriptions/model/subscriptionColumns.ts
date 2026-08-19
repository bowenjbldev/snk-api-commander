/**
 * La modalidad no es un estado del servidor sino cómo nació el contrato, así que no
 * viene del mock: el catálogo vive aquí, con el mismo par etiqueta-tono que el resto.
 * Lo comparte la ficha de la cuenta, que enseña sus contratos con esta misma pastilla.
 */
export const SUBSCRIPTION_MODE_CATALOG = {
  catalog: { label: 'Catálogo', tone: 'cool-gray' },
  negotiated: { label: 'Negociado', tone: 'purple' },
} as const;
