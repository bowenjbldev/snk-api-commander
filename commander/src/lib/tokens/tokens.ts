/**
 * Los nombres de las variables CSS, como enum.
 *
 * Existe por una razón concreta: ECharts no hereda variables CSS, así que alguien tiene que leer
 * los tokens del DOM y pasárselos. Ese alguien usa este enum en vez de escribir `'var(--ok)'` a
 * mano, que es justo la cadena que se escribe mal una vez y nadie encuentra. `ds-check` prohíbe
 * el literal `var(--` en cualquier `.ts`.
 */
export enum CssVar {
	BgApp = '--bg-app',
	BgCard = '--bg-card',
	BgElev = '--bg-elev',
	BgFill = '--bg-fill',
	BgFill2 = '--bg-fill2',
	BgHover = '--bg-hover',
	BgSel = '--bg-sel',

	Border = '--bd',
	BorderSubtle = '--bd2',

	Text = '--tx',
	Text2 = '--tx2',
	Text3 = '--tx3',
	Text4 = '--tx4',

	Primary = '--pri',
	PrimaryHover = '--pri-h',
	PrimaryActive = '--pri-a',
	PrimaryBg = '--pri-bg',
	PrimaryBorder = '--pri-bd',
	OnPrimary = '--on-pri',

	Ok = '--ok',
	OkBg = '--ok-bg',
	OkBorder = '--ok-bd',

	Warn = '--wn',
	WarnBg = '--wn-bg',
	WarnBorder = '--wn-bd',

	Error = '--er',
	ErrorBg = '--er-bg',
	ErrorBorder = '--er-bd',

	Info = '--in',
	InfoBg = '--in-bg',
	InfoBorder = '--in-bd',

	Series1 = '--s1',
	Series2 = '--s2',
	Series3 = '--s3',
	Series4 = '--s4',
	Series5 = '--s5',

	FontUi = '--font-ui',
	FontMono = '--font-mono'
}

/** Lee un token del tema activo. Solo tiene sentido en el navegador. */
export function readToken(name: CssVar, root: Element = document.documentElement): string {
	return getComputedStyle(root).getPropertyValue(name).trim();
}

/** Lee varios de una pasada, reutilizando el mismo `getComputedStyle`. */
export function readTokens<K extends string>(
	names: Readonly<Record<K, CssVar>>,
	root: Element = document.documentElement
): Record<K, string> {
	const style = getComputedStyle(root);
	const out = {} as Record<K, string>;
	for (const key of Object.keys(names) as K[]) {
		out[key] = style.getPropertyValue(names[key]).trim();
	}
	return out;
}
