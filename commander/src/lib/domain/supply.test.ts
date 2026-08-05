import { describe, expect, it } from 'vitest';
import { PackageStatus, Situation, SupplyShape, Tone } from '$lib/model/enums';
import type { SupplyInput } from '$lib/model/situation';
import { byUrgency, supply } from './supply';

const NOW = 1_700_000_000_000;
const HOUR = 3_600_000;

function input(over: Partial<SupplyInput> = {}): SupplyInput {
	return {
		situation: Situation.Live,
		status: PackageStatus.Active,
		graceUntil: null,
		graceStartedAt: null,
		...over
	};
}

describe('supply', () => {
	it('una instancia activa es una línea llena', () => {
		const result = supply(input(), NOW);
		expect(result.shape).toBe(SupplyShape.Solid);
		expect(result.tone).toBe(Tone.Ok);
		expect(result.ratio).toBe(1);
	});

	it('una cortada es una línea hueca', () => {
		expect(supply(input({ status: PackageStatus.Suspended }), NOW).shape).toBe(SupplyShape.Hollow);
	});

	it('el preaviso mengua en proporción a lo que queda', () => {
		const graceStartedAt = NOW - 24 * HOUR;
		const graceUntil = NOW + 24 * HOUR;
		const result = supply(input({ status: PackageStatus.Grace, graceUntil, graceStartedAt }), NOW);
		expect(result.shape).toBe(SupplyShape.Shrink);
		expect(result.tone).toBe(Tone.Warn);
		expect(result.ratio).toBeCloseTo(0.5, 2);
	});

	it('nunca desaparece del todo mientras quede plazo', () => {
		const result = supply(
			input({
				status: PackageStatus.Grace,
				graceUntil: NOW + 1000,
				graceStartedAt: NOW - 72 * HOUR
			}),
			NOW
		);
		expect(result.ratio).toBeGreaterThan(0);
	});

	// El cuarto estado, el que no está en los datos: ningún proceso reescribe la columna.
	it('deduce del reloj el preaviso caducado, aunque el estado siga diciendo grace', () => {
		const result = supply(
			input({
				status: PackageStatus.Grace,
				graceUntil: NOW - HOUR,
				graceStartedAt: NOW - 3 * HOUR
			}),
			NOW
		);
		expect(result.shape).toBe(SupplyShape.Expired);
		expect(result.tone).toBe(Tone.Error);
		expect(result.label).toBe('Preaviso caducado');
	});

	it('un preaviso sin fecha límite se lee como conectada, que es lo que el cliente percibe', () => {
		const result = supply(input({ status: PackageStatus.Grace, graceUntil: null }), NOW);
		expect(result.shape).toBe(SupplyShape.Solid);
	});

	it('un alta reciente puntea en azul y una atascada en rojo', () => {
		expect(supply(input({ situation: Situation.Pending }), NOW)).toMatchObject({
			shape: SupplyShape.Dotted,
			tone: Tone.Info
		});
		expect(supply(input({ situation: Situation.Stalled }), NOW)).toMatchObject({
			shape: SupplyShape.Dotted,
			tone: Tone.Error
		});
	});

	it('lo que no tiene suministro no lleva línea', () => {
		for (const situation of [
			Situation.Catalog,
			Situation.Gone,
			Situation.Undeployed,
			Situation.Unregistered
		]) {
			expect(supply(input({ situation }), NOW).shape).toBe(SupplyShape.None);
		}
	});
});

describe('byUrgency', () => {
	it('pone delante lo que exige decisión y deja lo sano al final', () => {
		const cases = [
			supply(input(), NOW),
			supply(input({ situation: Situation.Catalog }), NOW),
			supply(input({ status: PackageStatus.Suspended }), NOW),
			supply(
				input({ status: PackageStatus.Grace, graceUntil: NOW - 1, graceStartedAt: NOW - HOUR }),
				NOW
			),
			supply(
				input({
					status: PackageStatus.Grace,
					graceUntil: NOW + HOUR,
					graceStartedAt: NOW - HOUR
				}),
				NOW
			)
		];
		expect(cases.toSorted(byUrgency).map((s) => s.label)).toEqual([
			'Preaviso caducado',
			'Cortada',
			'En preaviso',
			'Conectada',
			'Solo catálogo'
		]);
	});
});
