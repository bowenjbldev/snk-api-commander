/**
 * Columnas de una rejilla de celdas iguales, repartidas para dejar el menor número
 * de huecos posible: ocho indicadores son 4×2 y cinco son 3+2, nunca 5+3 ni 4+1.
 *
 * Hace falta porque el número de celdas depende de los permisos del rol, así que
 * `auto-fit` reparte según el ancho disponible y abre huecos en la última fila.
 * A igualdad de huecos gana el reparto más ancho, que ocupa menos filas.
 */
export function balancedColumns(count: number, max = 4): number {
  if (count <= max) return Math.max(1, count);

  let best = max;
  let bestGaps = Infinity;
  for (let cols = 2; cols <= max; cols += 1) {
    const gaps = (cols - (count % cols)) % cols;
    if (gaps <= bestGaps) {
      bestGaps = gaps;
      best = cols;
    }
  }
  return best;
}
