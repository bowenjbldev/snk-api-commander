import type {
	ActivityKind,
	BillingStatus,
	ClientStatus,
	Enforcement,
	HttpMethod,
	ListingStatus,
	MetricKey,
	OnExceed,
	PackageStatus,
	PaymentTerms,
	QuotaUnit
} from './enums';

/**
 * Las filas de `commander_*` tal y como llegan.
 *
 * La API no usa `JsonResource`: son modelos Eloquent crudos, así que estos nombres son los de las
 * columnas y las fechas son cadenas ISO. `api/decode.ts` es quien las convierte en esto, y el
 * único sitio donde una cadena desconocida se rechaza en vez de propagarse.
 */

export interface CommanderClient {
	id: number;
	account_id: number | null;
	slug: string;
	name: string;
	company: string | null;
	status: ClientStatus;
	email: string | null;
	billing_email: string | null;
	phone: string | null;
	tax_id: string | null;
	payment_terms: PaymentTerms | null;
	address_line1: string | null;
	address_line2: string | null;
	city: string | null;
	postal_code: string | null;
	country: string | null;
	notes: string | null;
}

export interface CommanderQuota {
	id: number;
	package_id: number;
	metric_key: MetricKey;
	current_value: number;
	/** Nulo = ilimitado. No es cero: es que no hay techo. */
	limit_value: number | null;
	unit: QuotaUnit;
	on_exceed: OnExceed;
	exceeded_at: string | null;
	measured_at: string | null;
}

export interface CommanderSubscription {
	id: number;
	client_id: number;
	package_id: number;
	plan_id: number | null;
	/** Instantánea: cambiar el catálogo no reescribe lo que ya se cobra. */
	plan: string;
	price_monthly: string | number;
	billing_status: BillingStatus;
	auto_suspend: boolean;
	started_at: string | null;
	/** Nulo = término vigente. */
	ended_at: string | null;
}

/**
 * Catálogo y ciclo de vida en la misma tabla. Distinguir sus situaciones en una lista, sin abrir
 * nada, es el trabajo principal de la consola — ver `model/situation.ts`.
 */
export interface CommanderPackage {
	id: number;
	slug: string | null;
	name: string;
	label: string | null;
	description: string | null;
	listing_status: ListingStatus;
	client_id: number | null;
	subscription_id: number | null;
	status: PackageStatus;
	reason: string | null;
	message: string | null;
	grace_until: string | null;
	changed_at: string | null;
	/** La frontera de vida: nulo mientras el trabajo de fondo no haya terminado. */
	provisioned_at: string | null;
	requests_24h: number;
	quota_health: number | null;
	last_seen: string | null;
	created_at: string;
	/** Lo calcula la API mirando el disco, no una columna. */
	on_disk: boolean;
	plan_count: number;
	client?: CommanderClient | null;
	subscription?: CommanderSubscription | null;
	quotas?: CommanderQuota[];
}

export interface CommanderPlan {
	id: number;
	slug: string;
	/** Nombre Studly del producto: una tarifa pertenece al producto, no es global. */
	package: string;
	name: string;
	status: string;
	description: string | null;
	price_monthly: string | number;
	q_requests_day: number | null;
	q_disk_db_mb: number | null;
	q_disk_storage_mb: number | null;
	q_users_total: number | null;
}

export interface CommanderInvoice {
	id: number;
	client_id: number;
	package_id: number | null;
	number: string;
	amount: string | number;
	status: BillingStatus;
	enforcement: Enforcement | null;
	issued_at: string | null;
	due_at: string | null;
}

export interface CommanderActivityEvent {
	id: number;
	package_id: number | null;
	invoice_id: number | null;
	kind: ActivityKind;
	reason: string | null;
	message: string | null;
	created_at: string;
}

export interface CommanderRequestLog {
	id: number;
	package_id: number;
	package: string | null;
	subdomain: string | null;
	method: HttpMethod;
	route: string;
	path: string;
	status: number;
	duration_ms: number;
	ip: string | null;
	created_at: string;
}

export interface CommanderUsageBucket {
	package_id: number;
	period_start: string;
	request_count: number;
	error_count: number;
	count_4xx: number;
	count_5xx: number;
}

export interface CommanderSettings {
	operator_name: string | null;
	operator_email: string | null;
	default_grace_hours: number;
	notify_on_suspend: boolean;
	notify_on_overdue: boolean;
}

export interface OperatorUser {
	id: number;
	name: string;
	email: string;
	full_name: string | null;
	all_permissions: string[];
	role_names: string[];
}

export interface PackageCommit {
	sha: string;
	committed_at: string;
	author_name: string;
	message: string;
	additions: number;
	deletions: number;
	files_changed: number;
}

export interface PackageInsights {
	repo: { provider: string; workspace: string; repo_slug: string; default_branch: string } | null;
	summary: {
		total_commits: number;
		total_additions: number;
		total_deletions: number;
		total_authors: number;
		active_days: number;
	};
	daily: { day: string; commit_count: number; additions: number; deletions: number }[];
	recent_commits: PackageCommit[];
}
