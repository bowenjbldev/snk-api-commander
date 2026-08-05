/**
 * Formateo de lectura. Todo lo que sale de aquí va en la familia mono, con cifras tabulares.
 *
 * Locale fijo `es-ES` y moneda fija EUR: el idioma de la consola es una decisión de producto, no
 * una preferencia del navegador. Un importe que cambia de formato según quién abra la consola es
 * un dato distinto cada vez.
 */

const LOCALE = 'es-ES';

const integer = new Intl.NumberFormat(LOCALE);
const euro = new Intl.NumberFormat(LOCALE, {
	style: 'currency',
	currency: 'EUR',
	maximumFractionDigits: 0
});

const SECOND = 1000;
const MINUTE = 60 * SECOND;
const HOUR = 60 * MINUTE;
export const DAY = 24 * HOUR;

function pad2(value: number): string {
	return String(value).padStart(2, '0');
}

/** Coma decimal, como manda el locale, sin arrastrar un `Intl` para un solo decimal. */
function comma(value: number): string {
	return value.toFixed(1).replace('.', ',');
}

/** `1.240.332` — cifra exacta. */
export function n(value: number): string {
	return integer.format(Math.round(value));
}

/** `1,7M` · `138,4k` · `412` — cifra compacta para lecturas y ejes. */
export function k(value: number): string {
	if (value >= 1e6) return `${comma(value / 1e6)}M`;
	if (value >= 1000) return `${comma(value / 1000)}k`;
	return String(Math.round(value));
}

/** `3.420 €` — sin céntimos: es una cifra de cabecera, no un asiento contable. */
export function eur(value: number): string {
	return euro.format(value);
}

/** `82 %` · `4,3 %` — un decimal solo por debajo del 10 %, donde el entero pierde la señal. */
export function pct(ratio: number): string {
	return `${(ratio * 100).toFixed(ratio < 0.1 ? 1 : 0).replace('.', ',')} %`;
}

/** `45 s` · `12 min` · `7 h` · `3 d` — antigüedad. Se compone con la palabra: «hace ⟨4 min⟩». */
export function rel(ms: number): string {
	const seconds = Math.max(0, Math.round(ms / SECOND));
	if (seconds < 60) return `${seconds} s`;
	const minutes = Math.round(seconds / 60);
	if (minutes < 60) return `${minutes} min`;
	const hours = Math.floor(minutes / 60);
	if (hours < 48) return `${hours} h`;
	return `${Math.floor(hours / 24)} d`;
}

/**
 * `4 h 11 m` · `12 m 03 s` · `−2 h 40 m` — cuenta atrás de un preaviso.
 *
 * El signo negativo importa: un preaviso vencido no es «0 m», es tiempo que lleva caducado, y esa
 * instancia ya no está servida aunque la columna siga diciendo `grace`.
 */
export function cd(target: number, now: number): string {
	const delta = target - now;
	const overdue = delta < 0;
	const abs = Math.abs(delta);
	const hours = Math.floor(abs / HOUR);
	const minutes = Math.floor((abs % HOUR) / MINUTE);
	const seconds = Math.floor((abs % MINUTE) / SECOND);
	const text = hours > 0 ? `${hours} h ${pad2(minutes)} m` : `${minutes} m ${pad2(seconds)} s`;
	return overdue ? `−${text}` : text;
}

/** `11/06 14:02:10` — instante de un registro, en hora local. */
export function stamp(ts: number | string): string {
	const d = new Date(ts);
	return `${pad2(d.getDate())}/${pad2(d.getMonth() + 1)} ${pad2(d.getHours())}:${pad2(
		d.getMinutes()
	)}:${pad2(d.getSeconds())}`;
}

/** `11/06/2026` — fecha suelta: emisión, vencimiento, alta. */
export function date(ts: number | string): string {
	const d = new Date(ts);
	return `${pad2(d.getDate())}/${pad2(d.getMonth() + 1)}/${d.getFullYear()}`;
}

/** Megabytes → `2,1 GB` · `8.920 MB`, para las métricas de disco. */
export function mb(value: number): string {
	return value >= 1024 ? `${comma(value / 1024)} GB` : `${n(value)} MB`;
}
