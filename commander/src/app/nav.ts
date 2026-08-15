export interface NavEntry {
  label: string;
  to: string;
  /** Permiso `index <recurso>` que hace visible la entrada. Vacío = siempre visible. */
  perm?: string;
}

export interface NavGroup {
  title: string;
  entries: NavEntry[];
}

/** Dos niveles como máximo: el tercero vive como pestañas dentro de las fichas. */
export const NAV: NavGroup[] = [
  { title: 'Inicio', entries: [{ label: 'Resumen', to: '/' }] },
  {
    title: 'Comercial',
    entries: [
      { label: 'Cuentas', to: '/accounts', perm: 'index accounts' },
      { label: 'Contratos', to: '/subscriptions', perm: 'index subscriptions' },
      { label: 'Facturas', to: '/invoices', perm: 'index invoices' },
    ],
  },
  {
    title: 'Catálogo',
    entries: [
      { label: 'Aplicaciones', to: '/applications', perm: 'index applications' },
      { label: 'Planes', to: '/plans', perm: 'index plans' },
    ],
  },
  {
    title: 'Operación',
    entries: [
      { label: 'Clientes', to: '/clients', perm: 'index clients' },
      { label: 'Instancias', to: '/instances', perm: 'index instances' },
      { label: 'Nueva alta', to: '/provisioning/new', perm: 'provision instances' },
    ],
  },
  {
    title: 'Observabilidad',
    entries: [
      { label: 'Uso', to: '/usage', perm: 'index instances' },
      { label: 'Cuotas', to: '/quotas', perm: 'index quotas' },
      { label: 'Logs', to: '/logs', perm: 'index instances' },
      { label: 'Actividad', to: '/activity', perm: 'index activity' },
    ],
  },
  {
    title: 'Administración',
    entries: [
      { label: 'Operadores', to: '/operators', perm: 'index users' },
      { label: 'Preferencias', to: '/settings', perm: 'show settings' },
    ],
  },
];
