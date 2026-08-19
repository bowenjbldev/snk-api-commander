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

/**
 * Columnas de una sección de formulario: tres campos por fila como máximo y repartidos
 * parejos, de modo que cuatro campos van 2+2 y cinco van 3+2. Un campo solo ocupa media
 * fila para no estirar un input a lo ancho de la página.
 */
export function formColumns(count: number): number {
  if (count === 0) return 1;
  if (count === 1) return 2;
  if (count % 3 === 0) return 3;
  return count % 2 === 0 ? 2 : 3;
}
