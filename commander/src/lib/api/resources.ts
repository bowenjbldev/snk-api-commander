import type { BillingStatus, HttpMethod, StatusClass, SupplyReason } from '$lib/model/enums';
import { PackageStatus, UsagePeriod } from '$lib/model/enums';
import type {
	CommanderActivityEvent,
	CommanderClient,
	CommanderInvoice,
	CommanderPackage,
	CommanderPlan,
	CommanderQuota,
	CommanderSettings,
	CommanderSubscription,
	CommanderRequestLog,
	CommanderUsageBucket,
	OperatorUser,
	PackageInsights
} from '$lib/model/types';
import { api } from './client';
import { decodeData, decodeEnum, decodeList, decodeNumber } from './decode';
import {
	Endpoint,
	PackageAction,
	packageActionPath,
	PackageReport,
	packageReportPath,
	QueryParam,
	resourcePath
} from './endpoints';

/** Las nueve peticiones de la carga inicial. Ninguna acepta filtro ni paginación. */
export const list = {
	clients: async () => decodeList<CommanderClient>(await api.get(Endpoint.Clients), 'clients'),
	packages: async () => {
		const raw = decodeList<CommanderPackage>(await api.get(Endpoint.Packages), 'packages');
		// El estado alimenta la línea de suministro: si llega algo que el enum no contempla, se
		// para aquí y no en el `switch` que dibuja.
		for (const pkg of raw) decodeEnum(PackageStatus, pkg.status, `packages[${pkg.id}].status`);
		return raw;
	},
	subscriptions: async () =>
		decodeList<CommanderSubscription>(await api.get(Endpoint.Subscriptions), 'subscriptions'),
	invoices: async () => decodeList<CommanderInvoice>(await api.get(Endpoint.Invoices), 'invoices'),
	quotas: async () => decodeList<CommanderQuota>(await api.get(Endpoint.Quotas), 'quotas'),
	plans: async () => decodeList<CommanderPlan>(await api.get(Endpoint.Plans), 'plans'),
	activity: async () =>
		decodeList<CommanderActivityEvent>(await api.get(Endpoint.Activity), 'activity')
};

export const auth = {
	login: (email: string, password: string) =>
		api.post<{ token: string; user: OperatorUser }>(Endpoint.Login, { email, password }),
	me: () => api.get<{ user: OperatorUser }>(Endpoint.Me),
	logout: () => api.post<unknown>(Endpoint.Logout)
};

export const invoices = {
	setStatus: (id: number, status: BillingStatus) =>
		api.patch<CommanderInvoice>(resourcePath(Endpoint.Invoices, id), { status }),
	remove: (id: number) => api.delete<{ id: number }>(resourcePath(Endpoint.Invoices, id))
};

export const logs = {
	/**
	 * El caudal de peticiones. La API topa en 500 filas y no acepta `page`: no hay cien mil filas
	 * que recorrer, así que tampoco hace falta desplazamiento virtual.
	 */
	read: async (filters: { limit?: number; method?: HttpMethod; statusClass?: StatusClass } = {}) =>
		decodeData<CommanderRequestLog>(
			await api.get(Endpoint.Logs, {
				[QueryParam.Limit]: filters.limit ?? 200,
				...(filters.method ? { [QueryParam.Method]: filters.method } : {}),
				...(filters.statusClass ? { [QueryParam.StatusClass]: filters.statusClass } : {})
			}),
			'logs'
		)
};

export const instance = {
	/** Rollups de una instancia. Es el único endpoint paginado del paquete. */
	usage: async (id: number, period: UsagePeriod = UsagePeriod.Hour) => {
		const raw = await api.get<{ data?: unknown }>(packageReportPath(id, PackageReport.Usage), {
			[QueryParam.Period]: period,
			[QueryParam.PerPage]: 1000
		});
		return decodeList<CommanderUsageBucket>(raw.data ?? [], 'packages/{id}/usage').map((row) => ({
			...row,
			request_count: decodeNumber(row.request_count, 'usage.request_count'),
			error_count: decodeNumber(row.error_count, 'usage.error_count'),
			count_4xx: decodeNumber(row.count_4xx, 'usage.count_4xx'),
			count_5xx: decodeNumber(row.count_5xx, 'usage.count_5xx')
		}));
	},

	logs: async (id: number, limit = 100) =>
		decodeData<CommanderRequestLog>(
			await api.get(packageReportPath(id, PackageReport.Logs), { [QueryParam.Limit]: limit }),
			'packages/{id}/logs'
		),

	/** Actividad de código: repo, resumen, serie diaria y commits recientes. */
	insights: async (id: number, days = 182) =>
		api.get<{ data: PackageInsights }>(packageReportPath(id, PackageReport.Insights), {
			[QueryParam.Days]: days
		})
};

export const settings = {
	read: () => api.get<CommanderSettings>(Endpoint.Settings),
	write: (patch: Partial<CommanderSettings>) => api.put<CommanderSettings>(Endpoint.Settings, patch)
};

/** Los mandos de suministro. Siempre por identificador numérico, jamás por nombre. */
export const control = {
	cutOff: (id: number, reason: SupplyReason, message?: string) =>
		api.post<CommanderPackage>(packageActionPath(id, PackageAction.Suspend), { reason, message }),

	startNotice: (id: number, reason: SupplyReason, graceHours?: number) =>
		api.post<CommanderPackage>(packageActionPath(id, PackageAction.Grace), {
			reason,
			grace_hours: graceHours
		}),

	restore: (id: number) => api.post<CommanderPackage>(packageActionPath(id, PackageAction.Resume)),

	changePlan: (id: number, planId: number) =>
		api.post<CommanderPackage>(packageActionPath(id, PackageAction.ChangePlan), {
			plan_id: planId
		}),

	/** Destruye base de datos, ficheros, registros y cuotas. Conserva el histórico de facturación. */
	destroy: (id: number) => api.delete<{ message: string }>(resourcePath(Endpoint.Packages, id))
};

export const usage = {
	/** La serie de tráfico de toda la plataforma, agrupada por instancia y bucket. */
	platform: async (hours = 48, period: UsagePeriod = UsagePeriod.Hour) => {
		const rows = decodeData<CommanderUsageBucket>(
			await api.get(Endpoint.PlatformUsage, {
				[QueryParam.Period]: period,
				[QueryParam.Hours]: hours
			}),
			'usage/platform'
		);
		// Los agregados llegan como cadena; sumarlos sin coaccionar los concatena.
		return rows.map((row) => ({
			...row,
			package_id: decodeNumber(row.package_id, 'usage.package_id'),
			request_count: decodeNumber(row.request_count, 'usage.request_count'),
			error_count: decodeNumber(row.error_count, 'usage.error_count'),
			count_4xx: decodeNumber(row.count_4xx, 'usage.count_4xx'),
			count_5xx: decodeNumber(row.count_5xx, 'usage.count_5xx')
		}));
	}
};
