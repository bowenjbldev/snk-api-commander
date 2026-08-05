import { HttpMethod } from '$lib/model/enums';
import { clearStored, readStored, StorageKey, writeStored } from '$lib/state/storage';
import type { QueryParam } from './endpoints';

/**
 * La única puerta de red de la consola.
 *
 * Mismo origen: nginx enruta `^~ /api/` al Laravel de Commander y todo lo demás a esta SPA, así
 * que no hay CORS ni proxy que mantener. `VITE_API_BASE` solo hace falta si algún día el frontend
 * deja de compartir origen con la API.
 */
const BASE = (import.meta.env['VITE_API_BASE'] as string | undefined) ?? '/api/v1';

/** Un fallo con su código. El `status` es lo que decide qué hace quien llama, no el mensaje. */
export class ApiError extends Error {
	constructor(
		readonly status: number,
		message: string
	) {
		super(message);
		this.name = 'ApiError';
	}
}

export type Query = Partial<Record<QueryParam, string | number | boolean>>;

/**
 * Qué hacer cuando la API dice 401. Lo cablea la sesión al arrancar; la capa de red no sabe nada
 * de rutas ni de estado.
 */
let onUnauthorized: (() => void) | null = null;

export function setUnauthorizedHandler(handler: () => void): void {
	onUnauthorized = handler;
}

export const token = {
	read: (): string | null => readStored(StorageKey.Token),
	write: (value: string): void => {
		writeStored(StorageKey.Token, value);
	},
	clear: (): void => {
		clearStored(StorageKey.Token);
	}
};

function buildUrl(path: string, query?: Query): string {
	const url = `${BASE}/${path}`;
	if (!query) return url;
	const params = new URLSearchParams();
	// `Partial<Record<…>>` deja pasar la clave con valor `undefined`: se descarta aquí para no
	// mandar `?limit=undefined`.
	for (const [key, value] of Object.entries(query) as [string, Query[keyof Query]][]) {
		if (value !== undefined) params.set(key, String(value));
	}
	const qs = params.toString();
	return qs ? `${url}?${qs}` : url;
}

/**
 * El cuerpo puede no ser JSON: un 502 del proxy devuelve HTML, y reventar con un `SyntaxError`
 * esconde el fallo real. Aquí se degrada a texto y el error dice el código.
 */
async function parseBody(response: Response): Promise<unknown> {
	const text = await response.text();
	if (!text) return null;
	try {
		return JSON.parse(text) as unknown;
	} catch {
		return text;
	}
}

function messageFrom(body: unknown, status: number): string {
	if (typeof body === 'string' && body.trim()) return body.slice(0, 300);
	if (body && typeof body === 'object') {
		const record = body as Record<string, unknown>;
		for (const key of ['error', 'detail', 'message'] as const) {
			const value = record[key];
			if (typeof value === 'string') return value;
		}
		// 422 de Laravel: `{campo: ["mensaje", …]}` sin envoltorio. Se muestra el primero.
		for (const value of Object.values(record)) {
			if (Array.isArray(value) && typeof value[0] === 'string') return value[0];
		}
	}
	return `La API respondió ${status}`;
}

interface RequestOptions {
	query?: Query;
	body?: unknown;
	signal?: AbortSignal;
}

async function request<T>(
	method: HttpMethod,
	path: string,
	options: RequestOptions = {}
): Promise<T> {
	const headers: Record<string, string> = { Accept: 'application/json' };
	const bearer = token.read();
	if (bearer) headers['Authorization'] = `Bearer ${bearer}`;
	if (options.body !== undefined) headers['Content-Type'] = 'application/json';

	const init: RequestInit = { method, headers };
	if (options.body !== undefined) init.body = JSON.stringify(options.body);
	if (options.signal) init.signal = options.signal;

	const response = await fetch(buildUrl(path, options.query), init);
	const body = await parseBody(response);

	if (response.status === 401) {
		// Un token de tenant no existe en la base de Commander, así que un 401 aquí siempre
		// significa sesión muerta: no hay reintento que valga.
		token.clear();
		onUnauthorized?.();
		throw new ApiError(401, messageFrom(body, 401));
	}

	if (!response.ok) throw new ApiError(response.status, messageFrom(body, response.status));

	return body as T;
}

export const api = {
	get: <T>(path: string, query?: Query, signal?: AbortSignal) =>
		request<T>(HttpMethod.Get, path, { ...(query && { query }), ...(signal && { signal }) }),
	post: <T>(path: string, body?: unknown) =>
		request<T>(HttpMethod.Post, path, body === undefined ? {} : { body }),
	patch: <T>(path: string, body: unknown) => request<T>(HttpMethod.Patch, path, { body }),
	put: <T>(path: string, body: unknown) => request<T>(HttpMethod.Put, path, { body }),
	delete: <T>(path: string) => request<T>(HttpMethod.Delete, path)
};
