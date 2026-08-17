/**
 * Resuelve tokens de Carbon a su color real para quien no puede usar `var(--cds-…)`.
 *
 * Carbon Charts pinta con D3 y necesita parsear el color —calcula el contraste de los
 * tooltips a partir de él—, así que no le sirve la referencia sin resolver. Leerlo del
 * DOM deja el tema como fuente única: el día que haya conmutador claro/oscuro no hay
 * ningún hex escrito a mano que corregir.
 *
 * Un token que no resuelva se omite y manda la paleta de Carbon, que siempre es mejor
 * que pintar con una cadena vacía.
 */
export function tokenScale(groups: Record<string, string>): Record<string, string> {
  const root = getComputedStyle(document.documentElement);
  const scale: Record<string, string> = {};

  for (const [group, token] of Object.entries(groups)) {
    const value = root.getPropertyValue(token).trim();
    if (value) scale[group] = value;
  }

  return scale;
}
