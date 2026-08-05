/**
 * Vocabulario presentacional de las primitivas. Es distinto del de `model/enums`: aquí no hay
 * dominio, solo formas de un control. El `Tone` sí se reutiliza de allí porque un tono semántico
 * significa lo mismo en una etiqueta que en una lectura, y duplicarlo sería tener dos verdades.
 */

/**
 * Cuánta caja tiene el botón, no de qué color es. No hay variante «peligro»: un corte es un
 * botón corriente con `tone={Tone.Error}`, igual que el preaviso lo es con ámbar. Mezclar las dos
 * cosas obligaba a inventar una variante por cada color que el prototipo usa.
 */
export enum ButtonVariant {
	/** La única acción primaria de la pantalla. Lleva el color de «esto se toca». */
	Primary = 'primary',
	/** Acción secundaria: borde y fondo de tarjeta. */
	Default = 'default',
	/** Tinta y fondo del primario, sin su relleno. Para lo que reclama atención sin ser la acción
	 *  de la pantalla: las novedades que se pueden aplicar, el alta que falta, un filtro puesto. */
	Accent = 'accent'
}

export enum ControlSize {
	/** 32 — acción de cabecera de pantalla. */
	Regular = 'regular',
	/** 28 — la altura de control por defecto de la consola. */
	Compact = 'compact',
	/** 26 — dentro de una barra que ya tiene fondo propio: selección, segmentado del cajón. */
	Bar = 'bar',
	/** 24 — dentro de una fila o una barra de selección. */
	Dense = 'dense'
}

/**
 * Con qué muestra se explica una serie. No es decoración: la forma dice de qué tipo de trazo
 * habla la leyenda, y por eso una gráfica de áreas y la línea de suministro no comparten muestra.
 */
export enum LegendShape {
	/** Cuadradito de 8: el relleno de una serie de gráfica. */
	Swatch = 'swatch',
	/** Barra de 3 × 14: la línea de suministro de la fila, a su tamaño. */
	Bar = 'bar',
	/** Regla de trazos: una media o una proyección, nunca un dato medido. */
	Dash = 'dash'
}

/** Cómo está ordenada una columna ahora mismo. Los valores son los de `aria-sort`. */
export enum SortState {
	None = 'none',
	Ascending = 'ascending',
	Descending = 'descending'
}
