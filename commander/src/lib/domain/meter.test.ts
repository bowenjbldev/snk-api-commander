import { describe, expect, it } from 'vitest';
import { MetricKey, OnExceed, QuotaUnit, Tone } from '$lib/model/enums';
import type { CommanderQuota } from '$lib/model/types';
import { meter, worstMeter } from './meter';

const base = { key: MetricKey.RequestsDay, onExceed: OnExceed.Suspend };

describe('meter', () => {
	// Estar bien no es una señal: por debajo del umbral el relleno es neutro.
	it('no colorea por debajo del umbral', () => {
		expect(meter({ ...base, current: 62_000, limit: 100_000 }).tone).toBe(Tone.Neutral);
	});

	it('avisa a partir del 80 %', () => {
		expect(meter({ ...base, current: 80_000, limit: 100_000 }).tone).toBe(Tone.Warn);
		expect(meter({ ...base, current: 99_000, limit: 100_000 }).tone).toBe(Tone.Warn);
	});

	it('el exceso sale del carril en vez de aplastarse al 100 %', () => {
		const over = meter({ ...base, current: 103_000, limit: 100_000 });
		expect(over.tone).toBe(Tone.Error);
		expect(over.fill).toBe(1);
		expect(over.overflow).toBeCloseTo(0.03, 3);
		expect(over.label).toBe('103 %');
	});

	it('límite vacío es ilimitado, no cero', () => {
		const free = meter({ ...base, current: 9_999, limit: null });
		expect(free.unlimited).toBe(true);
		expect(free.ratio).toBeNull();
		expect(free.label).toBe('∞');
		expect(free.tone).toBe(Tone.Neutral);
	});

	it('la política viaja dentro del instrumento', () => {
		expect(meter({ ...base, current: 1, limit: 10 }).policyNote).toContain('corta el suministro');
		expect(meter({ ...base, onExceed: OnExceed.None, current: 1, limit: 10 }).policyNote).toContain(
			'solo lo registra'
		);
	});
});

function quota(over: Partial<CommanderQuota>): CommanderQuota {
	return {
		id: 1,
		package_id: 1,
		metric_key: MetricKey.RequestsDay,
		current_value: 0,
		limit_value: 100,
		unit: QuotaUnit.Count,
		on_exceed: OnExceed.Warn,
		exceeded_at: null,
		measured_at: null,
		...over
	};
}

describe('worstMeter', () => {
	it('elige la más apretada', () => {
		const worst = worstMeter([
			quota({ id: 1, current_value: 10 }),
			quota({ id: 2, metric_key: MetricKey.DiskDbMb, current_value: 95 }),
			quota({ id: 3, metric_key: MetricKey.UsersTotal, current_value: 40 })
		]);
		expect(worst?.key).toBe(MetricKey.DiskDbMb);
	});

	// Una ilimitada no compite: no tiene techo contra el que medirse.
	it('ignora las ilimitadas aunque su consumo sea enorme', () => {
		const worst = worstMeter([
			quota({ id: 1, current_value: 9_000_000, limit_value: null }),
			quota({ id: 2, metric_key: MetricKey.UsersTotal, current_value: 3 })
		]);
		expect(worst?.key).toBe(MetricKey.UsersTotal);
	});

	it('sin cuotas medibles no hay lectura que dar', () => {
		expect(worstMeter([])).toBeNull();
		expect(worstMeter([quota({ limit_value: null })])).toBeNull();
	});
});
