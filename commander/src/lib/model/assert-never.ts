/**
 * Cierra un `switch` sobre un enum. Si algún día se añade un valor y falta su rama, esto deja de
 * compilar en cada sitio que hay que tocar, en vez de caer silenciosamente en el `default`.
 */
export function assertNever(value: never, context = 'valor'): never {
	throw new Error(`${context} no contemplado: ${JSON.stringify(value)}`);
}
