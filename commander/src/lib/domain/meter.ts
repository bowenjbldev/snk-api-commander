import type { MetricKey } from '$lib/model/enums';
import { OnExceed, Tone } from '$lib/model/enums';
import type { CommanderQuota } from '$lib/model/types';

/** El punto en el que el instrumento empieza a avisar. Siempre visible en el carril. */
export const WARN_THRESHOLD = 0.8;

export interface MeterInput {
	key: MetricKey;
	current: number;
	/** Nulo = ilimitado: carril tenue, sin relleno y sin umbral. */
	limit: number | null;
	onExceed: OnExceed;
}

export interface Meter {
	key: MetricKey;
	/** Nulo cuando no hay límite: no existe proporción que enseñar. */
	ratio: number | null;
	/** Lo que se pinta del carril, de 0 a 1. El exceso sale fuera y se dibuja aparte. */
	fill: number;
	/** Cuánto se sale del carril, de 0 a 1. Cero si no excede. */
	overflow: number;
	tone: Tone;
	label: string;
	unlimited: boolean;
	onExceed: OnExceed;
	/** Qué significa la política, en nuestra palabra. */
	policyNote: string;
}

const POLICY_NOTE: Record<OnExceed, string> = {
	[OnExceed.None]: 'al exceder solo lo registra',
	[OnExceed.Warn]: 'al exceder deja un aviso',
	[OnExceed.Suspend]: 'al exceder corta el suministro'
};

/**
 * Una cuota **no es un porcentaje**: es una lectura con un límite y un punto en el que salta el
 * limitador. Ver que algo se acerca al techo importa más que ver que ya lo pasó.
 *
 * Por eso el relleno es neutro por debajo del umbral —estar bien no es una señal—, ámbar entre el
 * umbral y el límite, y rojo por encima con el exceso saliendo del carril.
 */
export function meter(input: MeterInput): Meter {
	const base = {
		key: input.key,
		onExceed: input.onExceed,
		policyNote: `${input.key} · ${POLICY_NOTE[input.onExceed]}`
	};

	if (input.limit === null) {
		return {
			...base,
			ratio: null,
			fill: 0,
			overflow: 0,
			tone: Tone.Neutral,
			label: '∞',
			unlimited: true
		};
	}

	const ratio = input.limit === 0 ? 0 : input.current / input.limit;
	const tone = ratio > 1 ? Tone.Error : ratio >= WARN_THRESHOLD ? Tone.Warn : Tone.Neutral;

	return {
		...base,
		ratio,
		fill: Math.min(1, ratio),
		overflow: Math.max(0, Math.min(1, ratio - 1)),
		tone,
		label: `${(ratio * 100).toFixed(0)} %`,
		unlimited: false
	};
}

export function meterFromQuota(quota: CommanderQuota): Meter {
	return meter({
		key: quota.metric_key,
		current: quota.current_value,
		limit: quota.limit_value,
		onExceed: quota.on_exceed
	});
}

/**
 * La cuota más apretada de una instancia: la que decide el color de su fila. Las ilimitadas no
 * compiten, porque no tienen techo contra el que medirse.
 */
export function worstMeter(quotas: readonly CommanderQuota[]): Meter | null {
	let worst: Meter | null = null;
	let worstRatio = -1;
	for (const quota of quotas) {
		const candidate = meterFromQuota(quota);
		if (candidate.ratio === null) continue;
		if (candidate.ratio > worstRatio) {
			worst = candidate;
			worstRatio = candidate.ratio;
		}
	}
	return worst;
}
