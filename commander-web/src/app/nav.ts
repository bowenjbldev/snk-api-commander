import type { CarbonIconType } from '@carbon/icons-react';
import {
  Activity,
  Add,
  Application,
  Building,
  ChartLine,
  Dashboard,
  Datastore,
  Document,
  Layers,
  Meter,
  Receipt,
  Settings,
  Terminal,
  UserMultiple,
} from '@carbon/icons-react';

export interface NavEntry {
  label: string;
  to: string;
  /** Permiso `index <recurso>` que hace visible la entrada. Vacío = siempre visible. */
  perm?: string;
  /** Se dibuja siempre, pero es lo único que queda cuando la navegación se pliega. */
  icon: CarbonIconType;
}

export interface NavGroup {
  title: string;
  entries: NavEntry[];
}

/** Dos niveles como máximo: el tercero vive como pestañas dentro de las fichas. */
export const NAV: NavGroup[] = [
  { title: 'Inicio', entries: [{ label: 'Resumen', to: '/', icon: Dashboard }] },
  {
    title: 'Comercial',
    entries: [
      { label: 'Cuentas', to: '/accounts', perm: 'index accounts', icon: Building },
      { label: 'Contratos', to: '/subscriptions', perm: 'index subscriptions', icon: Document },
      { label: 'Facturas', to: '/invoices', perm: 'index invoices', icon: Receipt },
    ],
  },
  {
    title: 'Catálogo',
    entries: [
      { label: 'Aplicaciones', to: '/applications', perm: 'index applications', icon: Application },
      { label: 'Planes', to: '/plans', perm: 'index plans', icon: Layers },
    ],
  },
  {
    title: 'Operación',
    entries: [
      { label: 'Clientes', to: '/clients', perm: 'index clients', icon: UserMultiple },
      { label: 'Instancias', to: '/instances', perm: 'index instances', icon: Datastore },
      { label: 'Nueva alta', to: '/provisioning/new', perm: 'provision instances', icon: Add },
    ],
  },
  {
    title: 'Observabilidad',
    entries: [
      { label: 'Uso', to: '/usage', perm: 'index instances', icon: ChartLine },
      { label: 'Cuotas', to: '/quotas', perm: 'index quotas', icon: Meter },
      { label: 'Logs', to: '/logs', perm: 'index instances', icon: Terminal },
      { label: 'Actividad', to: '/activity', perm: 'index activity', icon: Activity },
    ],
  },
  {
    title: 'Administración',
    entries: [
      { label: 'Operadores', to: '/operators', perm: 'index users', icon: UserMultiple },
      { label: 'Preferencias', to: '/settings', perm: 'show settings', icon: Settings },
    ],
  },
];
