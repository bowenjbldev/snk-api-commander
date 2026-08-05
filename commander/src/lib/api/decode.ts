import { ApiError } from './client';

/**
 * El borde. La única frontera del proyecto donde existe `string` suelto.
 *
 * La API no devuelve `JsonResource`: son modelos Eloquent crudos, sin envoltorio ni contrato
 * garantizado. Si un día una columna trae un valor que el enum no contempla, esto falla aquí, con
 * el campo y el valor en el mensaje, en vez de propagar una cadena inventada hasta un `switch`
 * que no la espera y pintar la fila en blanco.
 */
export function decodeEnum<E extends Record<string, string>>(
	values: E,
	raw: unknown,
	field: string
): E[keyof E] {
	const allowed = Object.values(values);
	if (typeof raw === 'string' && allowed.includes(raw)) return raw as E[keyof E];
	throw new ApiError(
		422,
		`La API devolvió ${JSON.stringify(raw)} en «${field}»; se esperaba uno de: ${allowed.join(', ')}`
	);
}

/** Igual, pero el campo admite nulo — que es distinto de admitir cualquier cosa. */
export function decodeEnumOrNull<E extends Record<string, string>>(
	values: E,
	raw: unknown,
	field: string
): E[keyof E] | null {
	return raw === null || raw === undefined ? null : decodeEnum(values, raw, field);
}

/** Las listas de los recursos CRUD llegan como array plano, sin envoltorio ni paginación. */
export function decodeList<T>(raw: unknown, field: string): T[] {
	if (Array.isArray(raw)) return raw as T[];
	throw new ApiError(422, `Se esperaba una lista en «${field}» y llegó ${typeof raw}`);
}

/** Las lecturas de plataforma sí vienen envueltas en `{ data: [...] }`. */
export function decodeData<T>(raw: unknown, field: string): T[] {
	if (raw && typeof raw === 'object' && 'data' in raw) {
		return decodeList<T>(raw.data, field);
	}
	throw new ApiError(422, `Se esperaba { data } en «${field}»`);
}

/**
 * MySQL devuelve los `SUM` como cadena y Laravel los pasa tal cual, así que `0 + "460" + "590"`
 * concatena en vez de sumar y una lectura de seiscientas peticiones sale como 4,6·10¹⁹. Se coacciona
 * aquí, en el borde, y no en cada sitio que las usa.
 */
export function decodeNumber(raw: unknown, field: string): number {
	const value = typeof raw === 'string' ? Number(raw) : raw;
	if (typeof value === 'number' && Number.isFinite(value)) return value;
	throw new ApiError(422, `Se esperaba un número en «${field}» y llegó ${JSON.stringify(raw)}`);
}
