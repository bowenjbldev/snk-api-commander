/**
 * Todo dominio cerrado, en un sitio. Ninguna comparación del proyecto usa un literal.
 *
 * Los que llevan valor de cadena espejan exactamente lo que devuelve la API (columnas de
 * `commander_*`); los que no, son deducciones nuestras y no viajan por la red.
 */

/** `commander_packages.status`. El cuarto estado —preaviso caducado— no está aquí: se deduce. */
export enum PackageStatus {
	Active = 'active',
	Grace = 'grace',
	Suspended = 'suspended'
}

/**
 * Lo que una fila de `commander_packages` ES, mirando cliente, alta y reloj a la vez. Es la
 * pregunta que la Flota tiene que responder sin abrir nada.
 *
 * Undeployed y Unregistered vienen del contrato de catálogo y despliegue; el backend todavía no
 * tiene los campos que las producen, así que hoy nunca se deducen. Están aquí para que el día que
 * lleguen no haya que tocar ningún `switch`, solo `situationOf`.
 */
export enum Situation {
	/** Provisionada y sirviendo tráfico. */
	Live = 'live',
	/** Comprada; el trabajo de fondo aún no ha creado su base de datos. */
	Pending = 'pending',
	/** Pendiente que lleva demasiado tiempo pendiente. Dos minutos es normal, dos días una avería. */
	Stalled = 'stalled',
	/** Existe en disco y se puede vender. Nadie la usa. */
	Catalog = 'catalog',
	/** Estuvo viva y se destruyó. Conserva su histórico de facturación. */
	Gone = 'gone',
	/** Catálogo comercial cerrado antes que el código: hay fila, no hay directorio. */
	Undeployed = 'undeployed',
	/** El código aterrizó y nadie le puso rótulo ni tarifa. */
	Unregistered = 'unregistered'
}

/**
 * Cómo se dibuja la línea de suministro. Va por FORMA antes que por color, para que funcione con
 * visión de color reducida y en escala de grises.
 */
export enum SupplyShape {
	/** Línea llena: conectada. */
	Solid = 'solid',
	/** Segmento que mengua hasta el corte: preaviso corriendo. */
	Shrink = 'shrink',
	/** Línea hueca: cortada. */
	Hollow = 'hollow',
	/** Punteada: alta en curso o atascada. */
	Dotted = 'dotted',
	/** El segmento llegó a cero: el preaviso caducó y ya no está servido. */
	Expired = 'expired',
	/** Sin línea: no hay suministro que representar. */
	None = 'none'
}

/** Tono semántico. El primario NO está aquí a propósito: una medida nunca lleva color de control. */
export enum Tone {
	Ok = 'ok',
	Warn = 'warn',
	Error = 'error',
	Info = 'info',
	Neutral = 'neutral'
}

/** `commander_quotas.metric_key`. Las cuatro métricas, no hay más. */
export enum MetricKey {
	RequestsDay = 'requests_day',
	DiskDbMb = 'disk_db_mb',
	DiskStorageMb = 'disk_storage_mb',
	UsersTotal = 'users_total'
}

/** `commander_quotas.on_exceed`. Es el umbral de disparo del limitador. */
export enum OnExceed {
	None = 'none',
	Warn = 'warn',
	Suspend = 'suspend'
}

/** `commander_quotas.unit`. */
export enum QuotaUnit {
	Count = 'count',
	Megabytes = 'mb'
}

/** `commander_invoices.status` y `commander_subscriptions.billing_status`. */
export enum BillingStatus {
	Paid = 'paid',
	Pending = 'pending',
	Overdue = 'overdue'
}

/** `commander_invoices.enforcement`: qué se decidió hacer con el suministro ante el impago. */
export enum Enforcement {
	Grace = 'grace',
	Suspended = 'suspended'
}

/** `commander_clients.status`. */
export enum ClientStatus {
	Active = 'active',
	Inactive = 'inactive'
}

/** `commander_clients.payment_terms`. */
export enum PaymentTerms {
	Prepaid = 'prepaid',
	Net15 = 'net_15',
	Net30 = 'net_30',
	Net60 = 'net_60'
}

/** `commander_packages.listing_status`: si el producto se ofrece en futuras altas. */
export enum ListingStatus {
	Active = 'active',
	Inactive = 'inactive'
}

/** Motivo de un corte o de un preaviso, tal y como lo acepta la API. */
export enum SupplyReason {
	PaymentOverdue = 'payment_overdue',
	Abuse = 'abuse',
	Manual = 'manual'
}

/** `commander_request_logs.method`. */
export enum HttpMethod {
	Get = 'GET',
	Post = 'POST',
	Patch = 'PATCH',
	Put = 'PUT',
	Delete = 'DELETE'
}

/** Clase de código HTTP, como la filtra la API (`status_class`). */
export enum StatusClass {
	Success = '2',
	Redirect = '3',
	ClientError = '4',
	ServerError = '5'
}

/** `commander_activity_events.kind`. */
export enum ActivityKind {
	Suspend = 'suspend',
	Grace = 'grace',
	Resume = 'resume',
	Paid = 'paid',
	Quota = 'quota',
	ChangePlan = 'change_plan'
}

/** Granularidad de los agregados de uso. */
export enum UsagePeriod {
	Hour = 'hour',
	Day = 'day'
}

/** Ventana de tiempo que gobierna una pantalla entera. */
export enum TimeWindow {
	Hours48 = '48h',
	Week = 'week',
	Month = 'month',
	Quarter = 'quarter',
	Year = 'year'
}

/** Tema visual. Se persiste; el arranque lo aplica antes del primer pintado (`app.html`). */
export enum Theme {
	Light = 'light',
	Dark = 'dark'
}
