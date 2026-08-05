import { describe, expect, it } from 'vitest';
import {
	BillingStatus,
	ListingStatus,
	MetricKey,
	OnExceed,
	PackageStatus,
	QuotaUnit,
	Tone
} from '$lib/model/enums';
import type { CommanderInvoice, CommanderPackage } from '$lib/model/types';
import { attentionItems } from './attention';

const NOW = new Date('2026-08-05T12:00:00Z').getTime();
const HOUR = 3_600_000;
const iso = (offset: number): string => new Date(NOW + offset).toISOString();

function pkg(over: Partial<CommanderPackage> = {}): CommanderPackage {
	return {
		id: 1,
		slug: 'atlas-crm',
		name: 'AtlasCrm',
		label: 'Atlas CRM',
		description: null,
		listing_status: ListingStatus.Active,
		client_id: 10,
		subscription_id: null,
		status: PackageStatus.Active,
		reason: null,
		message: null,
		grace_until: null,
		changed_at: null,
		provisioned_at: iso(-400 * 24 * HOUR),
		requests_24h: 12_400,
		quota_health: 0.62,
		last_seen: iso(-2 * 60_000),
		created_at: iso(-400 * 24 * HOUR),
		on_disk: true,
		plan_count: 3,
		client: null,
		quotas: [],
		...over
	};
}

function invoice(over: Partial<CommanderInvoice> = {}): CommanderInvoice {
	return {
		id: 1,
		client_id: 10,
		package_id: 1,
		number: 'F-2026-0188',
		amount: 149,
		status: BillingStatus.Overdue,
		enforcement: null,
		issued_at: iso(-40 * 24 * HOUR),
		due_at: iso(-9 * 24 * HOUR),
		...over
	};
}

const quota = (current: number, limit: number | null, onExceed = OnExceed.Suspend) => ({
	id: 1,
	package_id: 1,
	metric_key: MetricKey.RequestsDay,
	current_value: current,
	limit_value: limit,
	unit: QuotaUnit.Count,
	on_exceed: onExceed,
	exceeded_at: null,
	measured_at: null
});

describe('attentionItems', () => {
	it('no dice nada cuando nada exige decisión', () => {
		expect(attentionItems({ packages: [pkg()], invoices: [], now: NOW })).toEqual([]);
	});

	it('un preaviso corriendo pide extenderlo y uno caducado pide decidir', () => {
		const running = attentionItems({
			packages: [
				pkg({
					status: PackageStatus.Grace,
					grace_until: iso(4 * HOUR),
					changed_at: iso(-44 * HOUR)
				})
			],
			invoices: [],
			now: NOW
		});
		expect(running[0]?.action).toBe('Extender preaviso');
		expect(running[0]?.tone).toBe(Tone.Warn);

		const expired = attentionItems({
			packages: [
				pkg({ status: PackageStatus.Grace, grace_until: iso(-HOUR), changed_at: iso(-49 * HOUR) })
			],
			invoices: [],
			now: NOW
		});
		expect(expired[0]?.title).toBe('Preaviso caducado');
		expect(expired[0]?.tone).toBe(Tone.Error);
	});

	it('un alta atascada aparece; una recién creada no', () => {
		const fresh = attentionItems({
			packages: [pkg({ provisioned_at: null, created_at: iso(-2 * 60_000) })],
			invoices: [],
			now: NOW
		});
		expect(fresh).toEqual([]);

		const stuck = attentionItems({
			packages: [pkg({ provisioned_at: null, created_at: iso(-3 * 24 * HOUR) })],
			invoices: [],
			now: NOW
		});
		expect(stuck[0]?.title).toContain('Alta sin completar');
	});

	it('avisa del contador por encima del umbral', () => {
		const items = attentionItems({
			packages: [pkg({ quotas: [quota(88_000, 100_000)] })],
			invoices: [],
			now: NOW
		});
		expect(items[0]?.action).toBe('Contadores');
		expect(items[0]?.title).toContain('88 %');
	});

	// Un corte manual, o por impago, no se cura bajando una cuota.
	it('no avisa del contador de una instancia ya cortada', () => {
		const items = attentionItems({
			packages: [pkg({ status: PackageStatus.Suspended, quotas: [quota(103_000, 100_000)] })],
			invoices: [],
			now: NOW
		});
		expect(items.every((item) => item.action !== 'Contadores')).toBe(true);
	});

	it('una factura vencida entra con sus días de retraso; una pagada no', () => {
		const items = attentionItems({ packages: [], invoices: [invoice()], now: NOW });
		expect(items[0]?.title).toContain('F-2026-0188');
		expect(items[0]?.body).toContain('9 días');

		expect(
			attentionItems({
				packages: [],
				invoices: [invoice({ status: BillingStatus.Paid })],
				now: NOW
			})
		).toEqual([]);
	});

	it('lo descartado no vuelve', () => {
		const source = { packages: [], invoices: [invoice()], now: NOW };
		const [first] = attentionItems(source);
		expect(first).toBeDefined();
		expect(attentionItems({ ...source, seen: new Set([first?.id ?? '']) })).toEqual([]);
	});

	it('lo urgente va primero', () => {
		const items = attentionItems({
			packages: [pkg({ quotas: [quota(88_000, 100_000)] })],
			invoices: [invoice()],
			now: NOW
		});
		expect(items.map((item) => item.tone)).toEqual([Tone.Error, Tone.Warn]);
	});
});
