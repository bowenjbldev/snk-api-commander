import { list } from '$lib/api/resources';
import type {
	CommanderActivityEvent,
	CommanderClient,
	CommanderInvoice,
	CommanderPackage,
	CommanderPlan,
	CommanderQuota,
	CommanderSubscription
} from '$lib/model/types';

interface Dataset {
	packages: CommanderPackage[];
	clients: CommanderClient[];
	subscriptions: CommanderSubscription[];
	invoices: CommanderInvoice[];
	quotas: CommanderQuota[];
	plans: CommanderPlan[];
	activity: CommanderActivityEvent[];
}

const EMPTY: Dataset = {
	packages: [],
	clients: [],
	subscriptions: [],
	invoices: [],
	quotas: [],
	plans: [],
	activity: []
};

let dataset = $state<Dataset>(EMPTY);
let loading = $state(false);
let error = $state<string | null>(null);
let loadedAt = $state<number | null>(null);
/** Ignora respuestas viejas que lleguen fuera de orden tras una recarga. */
let sequence = 0;

export const data = {
	get packages(): CommanderPackage[] {
		return dataset.packages;
	},
	get clients(): CommanderClient[] {
		return dataset.clients;
	},
	get subscriptions(): CommanderSubscription[] {
		return dataset.subscriptions;
	},
	get invoices(): CommanderInvoice[] {
		return dataset.invoices;
	},
	get quotas(): CommanderQuota[] {
		return dataset.quotas;
	},
	get plans(): CommanderPlan[] {
		return dataset.plans;
	},
	get activity(): CommanderActivityEvent[] {
		return dataset.activity;
	},
	get loading(): boolean {
		return loading;
	},
	get error(): string | null {
		return error;
	},
	/** Edad del dato, para el pie del armazón. La consola no promete tiempo real. */
	get loadedAt(): number | null {
		return loadedAt;
	},

	/**
	 * La carga inicial, en paralelo. El armazón entra primero y cada pantalla resuelve lo suyo con
	 * su propio esqueleto: no se bloquea hasta tener los siete recursos.
	 */
	async load(): Promise<void> {
		const ticket = ++sequence;
		loading = true;
		error = null;
		try {
			const [packages, clients, subscriptions, invoices, quotas, plans, activity] =
				await Promise.all([
					list.packages(),
					list.clients(),
					list.subscriptions(),
					list.invoices(),
					list.quotas(),
					list.plans(),
					list.activity()
				]);
			if (ticket !== sequence) return;
			dataset = { packages, clients, subscriptions, invoices, quotas, plans, activity };
			loadedAt = Date.now();
		} catch (cause) {
			if (ticket !== sequence) return;
			error = cause instanceof Error ? cause.message : 'No se pudo cargar la consola';
		} finally {
			if (ticket === sequence) loading = false;
		}
	},

	/**
	 * Mutación optimista: se aplica en local, y cuando la API responde se sustituye por la entidad
	 * autoritativa. Si falla, quien llama avisa y recarga — gana el servidor.
	 */
	patchPackage(id: number, fields: Partial<CommanderPackage>): void {
		dataset = {
			...dataset,
			packages: dataset.packages.map((pkg) => (pkg.id === id ? { ...pkg, ...fields } : pkg))
		};
	},

	replacePackage(fresh: CommanderPackage): void {
		dataset = {
			...dataset,
			packages: dataset.packages.map((pkg) => (pkg.id === fresh.id ? fresh : pkg))
		};
	},

	reset(): void {
		sequence++;
		dataset = EMPTY;
		loadedAt = null;
		error = null;
	}
};
