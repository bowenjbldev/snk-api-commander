import { CssVar, readTokens } from '$lib/tokens/tokens';

export interface ChartPalette {
	text: string;
	text2: string;
	text3: string;
	border: string;
	borderSubtle: string;
	card: string;
	fontUi: string;
	fontMono: string;
	ok: string;
	warn: string;
	error: string;
	info: string;
	series: string[];
}

const NAMES = {
	text: CssVar.Text,
	text2: CssVar.Text2,
	text3: CssVar.Text3,
	border: CssVar.Border,
	borderSubtle: CssVar.BorderSubtle,
	card: CssVar.BgCard,
	fontUi: CssVar.FontUi,
	fontMono: CssVar.FontMono,
	ok: CssVar.Ok,
	warn: CssVar.Warn,
	error: CssVar.Error,
	info: CssVar.Info,
	s1: CssVar.Series1,
	s2: CssVar.Series2,
	s3: CssVar.Series3,
	s4: CssVar.Series4,
	s5: CssVar.Series5
} as const;

/**
 * El puente entre los tokens y ECharts.
 *
 * ECharts no hereda variables CSS, así que alguien tiene que leerlas del DOM y pasárselas. Ese
 * alguien es esto, y se llama **una vez por cambio de tema**: las funciones de `options/` reciben
 * la paleta como argumento y por eso ninguna contiene un color. Sin esta pieza harían falta dos
 * juegos de colores mantenidos a mano, que es justo donde se rompen los temas.
 */
export function readPalette(): ChartPalette {
	const t = readTokens(NAMES);
	return {
		text: t.text,
		text2: t.text2,
		text3: t.text3,
		border: t.border,
		borderSubtle: t.borderSubtle,
		card: t.card,
		fontUi: t.fontUi,
		fontMono: t.fontMono,
		ok: t.ok,
		warn: t.warn,
		error: t.error,
		info: t.info,
		series: [t.s1, t.s2, t.s3, t.s4, t.s5]
	};
}

/**
 * Cuando una serie representa un estado —errores, cortes— usa el color semántico, no la paleta de
 * series. Esa regla vive aquí, no en cada gráfica.
 */
export function seriesColor(palette: ChartPalette, index: number): string {
	return palette.series[index % palette.series.length] ?? palette.text3;
}

const SERIES_VARS = [
	CssVar.Series1,
	CssVar.Series2,
	CssVar.Series3,
	CssVar.Series4,
	CssVar.Series5
];

/**
 * El color de una serie como referencia CSS, para lo que se pinta con CSS y no con ECharts: la
 * leyenda, la muestra de una fila. No lee el DOM, así que sirve también antes de montar.
 */
export function seriesCssVar(index: number): string {
	return `var(${SERIES_VARS[index % SERIES_VARS.length] ?? CssVar.Text3})`;
}
