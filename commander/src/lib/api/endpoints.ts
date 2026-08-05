/**
 * Las rutas de la API, relativas a la base (`/api/v1`). Ninguna se escribe a mano en la capa de
 * red: o es un valor de `Endpoint`, o sale de un constructor.
 */
export enum Endpoint {
	Login = 'auth/login',
	Me = 'auth/me',
	Logout = 'auth/logout',

	Users = 'users',
	Accounts = 'accounts',
	Clients = 'clients',
	Plans = 'plans',
	Subscriptions = 'subscriptions',
	Invoices = 'invoices',
	Quotas = 'quotas',
	Activity = 'activity',

	Packages = 'packages',
	Provisioning = 'provisioning',

	PlatformUsage = 'usage/platform',
	Logs = 'logs',
	Commits = 'commits',
	CodeFrequency = 'code-frequency',
	Settings = 'settings'
}

/** Los cuatro mandos de suministro. Todos son `POST packages/{id}/<acción>`. */
export enum PackageAction {
	Suspend = 'suspend',
	Grace = 'grace',
	Resume = 'resume',
	ChangePlan = 'change-plan'
}

/** Las tres lecturas colgadas de una instancia. Todas son `GET packages/{id}/<lectura>`. */
export enum PackageReport {
	Usage = 'usage',
	Logs = 'logs',
	Insights = 'insights'
}

/** Parámetros de consulta que la API acepta. No hay más: ni `page`, ni `search`, ni `sort`. */
export enum QueryParam {
	/** CSV de relaciones a incluir. El único filtro de los recursos CRUD. */
	With = 'with',
	Period = 'period',
	Hours = 'hours',
	Days = 'days',
	From = 'from',
	To = 'to',
	PerPage = 'per_page',
	Limit = 'limit',
	Since = 'since',
	StatusClass = 'status_class',
	Method = 'method',
	Package = 'package'
}

export function resourcePath(endpoint: Endpoint, id: number | string): string {
	return `${endpoint}/${id}`;
}

export function packageActionPath(id: number, action: PackageAction): string {
	return `${Endpoint.Packages}/${id}/${action}`;
}

export function packageReportPath(id: number, report: PackageReport): string {
	return `${Endpoint.Packages}/${id}/${report}`;
}
