/**
 * Utilidades de colección sin dominio: no saben qué es una instancia ni una cuota.
 * La lógica de negocio vive en el `model/` de cada feature.
 */

/** Índice `{id: fila}` para resolver FKs sin recorrer arrays en cada celda. */
export function lut<T extends { id: number }>(rows: T[] | null | undefined): Map<number, T> {
  return new Map((rows ?? []).map((row) => [row.id, row]));
}

/**
 * Marcador mientras la petición vuela. La tupla vacía no puede tipar sola una entidad
 * todavía inexistente, así que el tipo lo pone el resultado y este helper solo evita
 * que cada página repita el mismo casting.
 */
export function pending<T>(data: T | null, blank: unknown[]): T {
  return (data ?? blank) as T;
}

/** Una división por cero es 0 %, nunca infinito ni dato ausente. */
export function rate(part: number, whole: number): number {
  return whole === 0 ? 0 : (part / whole) * 100;
}

export function groupCount<T>(rows: T[], key: (row: T) => string): Record<string, number> {
  const out: Record<string, number> = {};
  for (const row of rows) {
    const k = key(row);
    out[k] = (out[k] ?? 0) + 1;
  }
  return out;
}
