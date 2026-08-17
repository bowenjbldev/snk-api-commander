import { createElement } from 'react';
import { Link } from 'react-router-dom';
import { SUB_STATUS, fmtMoney } from '@services/api/mock/commander-mock';
import type { Subscription } from '@services/api/types';
import type { Column } from '@shared/components/DataView';
import { StatusTag } from '@shared/components/StatusTag';

export const SUBSCRIPTION_MODE_CATALOG = {
  catalog: { label: 'Catálogo', tone: 'gray' },
  negotiated: { label: 'Negociado', tone: 'purple' },
} as const;

/**
 * Columnas compartidas: la ficha de una cuenta muestra sus contratos con la misma
 * forma que el listado global. Viven aquí, en el dominio dueño de la entidad, para
 * que cuentas no dependa de la página de contratos.
 */
export function subscriptionColumns(appById: Map<number, { name: string }>): Column<Subscription>[] {
  return [
    {
      key: 'name',
      header: 'Nombre',
      value: (s) => s.name,
      render: (s) => createElement(Link, { to: `/subscriptions/${s.id}` }, s.name),
    },
    { key: 'application', header: 'Aplicación', value: (s) => appById.get(s.application_id)?.name ?? '' },
    {
      key: 'mode',
      header: 'Modalidad',
      value: (s) => s.mode,
      render: (s) => createElement(StatusTag, { catalog: SUBSCRIPTION_MODE_CATALOG, value: s.mode }),
    },
    {
      key: 'status',
      header: 'Estado',
      value: (s) => s.status,
      render: (s) => createElement(StatusTag, { catalog: SUB_STATUS, value: s.status }),
    },
    {
      key: 'monthly_amount',
      header: 'Importe',
      align: 'right',
      value: (s) => s.monthly_amount,
      render: (s) => fmtMoney(s.monthly_amount, s.currency),
    },
    { key: 'max_instances', header: 'Capacidad', align: 'right', value: (s) => s.max_instances },
  ];
}
