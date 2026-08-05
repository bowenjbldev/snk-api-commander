import { Route } from './routes';

export interface NavEntry {
	route: Route;
	label: string;
	/** El trazo del icono, en el mismo lenguaje que el resto del raíl. */
	icon: string;
}

/**
 * El raíl es el mapa, no el vehículo principal: para moverse de verdad está la paleta ⌘K. Por eso
 * va colapsado a iconos y no se expande.
 */
export const NAV: NavEntry[] = [
	{
		route: Route.Overview,
		label: 'Panorama',
		icon: 'M2.6 2.6h4.8v4.8h-4.8zM8.6 2.6h4.8v4.8h-4.8zM2.6 8.6h4.8v4.8h-4.8zM8.6 8.6h4.8v4.8h-4.8z'
	},
	{
		route: Route.Fleet,
		label: 'Flota',
		icon: 'M3 3.2v2.4M6 4.4h7.4M3 6.8v2.4M6 8h7.4M3 10.4v2.4M6 11.6h7.4'
	},
	{
		route: Route.Clients,
		label: 'Clientes',
		icon: 'M2.6 3.4h10.8v9.2h-10.8zM2.6 6.4h10.8M5.2 9.4h3.2'
	},
	{
		route: Route.Invoices,
		label: 'Facturas',
		icon: 'M3.6 2.2h8.8v11.6h-8.8zM5.8 5.2h4.4M5.8 7.8h4.4M5.8 10.4h2.6'
	},
	{
		route: Route.Usage,
		label: 'Uso agregado',
		icon: 'M2.8 13.2h10.4M4.6 13.2V8.4M8 13.2V4.6M11.4 13.2V10'
	},
	{
		route: Route.Logs,
		label: 'Registros',
		icon: 'M5.8 4h7.4M5.8 6.7h5.6M5.8 9.4h7.4M5.8 12.1h3.8'
	},
	{ route: Route.Settings, label: 'Preferencias', icon: 'M2.8 5.2h10.4M2.8 10.8h10.4' }
];

export const TITLES: Record<string, string> = {
	[Route.Overview]: 'Panorama',
	[Route.Fleet]: 'Flota',
	[Route.Clients]: 'Cartera de clientes',
	[Route.Invoices]: 'Libro de facturas',
	[Route.Usage]: 'Uso agregado',
	[Route.Logs]: 'Explorador de registros',
	[Route.Settings]: 'Preferencias'
};
