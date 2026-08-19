import { NAV } from '@app/nav';

/**
 * La sección de la navegación a la que pertenece una ruta. Sale del propio `NAV`
 * para que el rótulo del encabezado y el del menú lateral no puedan divergir.
 */
const SECTIONS = new Map<string, string>(
  NAV.flatMap((group) => group.entries.map((entry) => [entry.to.split('/')[1] ?? '', group.title])),
);

export function sectionFor(pathname: string): string {
  return SECTIONS.get(pathname.split('/')[1] ?? '') ?? 'Commander';
}

/**
 * Rótulo del encabezado: la sección encabeza siempre, y lo propio de la página
 * —«Instancia 42»— la sigue. Si la página solo repite la sección, no se duplica.
 */
export function kickerFor(pathname: string, own?: string): string {
  const section = sectionFor(pathname);
  if (!own) return section;
  const parts = own.split(' · ');
  const tail = parts.length > 1 ? parts.slice(1).join(' · ') : parts[0] === section ? '' : parts[0];
  return tail ? `${section} · ${tail}` : section;
}
