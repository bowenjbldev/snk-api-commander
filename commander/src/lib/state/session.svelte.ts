import { auth } from '$lib/api/resources';
import { setUnauthorizedHandler, token } from '$lib/api/client';
import type { OperatorUser } from '$lib/model/types';

export enum SessionStatus {
	/** Todavía no sabemos si el token sirve. El armazón no decide nada aquí. */
	Loading = 'loading',
	Anonymous = 'anon',
	Authenticated = 'authed'
}

let status = $state<SessionStatus>(SessionStatus.Loading);
let user = $state<OperatorUser | null>(null);

function clear(): void {
	token.clear();
	user = null;
	status = SessionStatus.Anonymous;
}

setUnauthorizedHandler(clear);

export const session = {
	get status(): SessionStatus {
		return status;
	},

	get user(): OperatorUser | null {
		return user;
	},

	/**
	 * La consola oculta lo que no procede, pero **no es una frontera de seguridad**: el permiso
	 * real lo comprueba la API en cada controlador. «Sin permiso» es un estado de componente más,
	 * junto a cargando, vacío y error.
	 */
	can(permission: string): boolean {
		return user?.all_permissions.includes(permission) ?? false;
	},

	/** Al arrancar: si hay token, se valida; cualquier 401 lo limpia por el handler de arriba. */
	async restore(): Promise<void> {
		if (!token.read()) {
			status = SessionStatus.Anonymous;
			return;
		}
		try {
			const response = await auth.me();
			user = response.user;
			status = SessionStatus.Authenticated;
		} catch {
			clear();
		}
	},

	async login(email: string, password: string): Promise<void> {
		const response = await auth.login(email, password);
		token.write(response.token);
		user = response.user;
		status = SessionStatus.Authenticated;
	},

	async logout(): Promise<void> {
		try {
			await auth.logout();
		} finally {
			// Se cierra en local pase lo que pase: si la API no contesta, el operador igualmente
			// quiere salir de aquí.
			clear();
		}
	}
};
