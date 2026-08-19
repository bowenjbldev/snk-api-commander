import type { ModalData, ModalField } from '@shared/components/ActionModal';
import type { Instance, ProvisioningStatus } from '@services/api/types';

export type InstanceAction = 'grace' | 'suspend' | 'resume' | 'change-plan' | 'retry' | 'deprovision';

/** Un trabajo en vuelo es el único motivo para sondear la ficha. */
export const IN_FLIGHT: ProvisioningStatus[] = ['pending', 'provisioning', 'deprovisioning'];

export function isInFlight(instance: Instance): boolean {
  return IN_FLIGHT.includes(instance.provisioning_status);
}

/**
 * Un solo rótulo por acción: el menú de fila del listado, los botones de la ficha y las
 * acciones de cabecera nombran lo mismo, así que no pueden decirlo de dos maneras.
 */
export const ACTION_LABEL: Record<InstanceAction, string> = {
  grace: 'Abrir periodo de gracia',
  suspend: 'Suspender servicio',
  resume: 'Reanudar servicio',
  'change-plan': 'Cambiar plan',
  retry: 'Reintentar alta',
  deprovision: 'Dar de baja',
};

/** Reintentar es dar de alta otra vez, no operar el servicio: lo firma otro permiso. */
export const ACTION_PERM: Record<InstanceAction, string> = {
  grace: 'control instances',
  suspend: 'control instances',
  resume: 'control instances',
  'change-plan': 'control instances',
  retry: 'provision instances',
  deprovision: 'control instances',
};

/**
 * Qué se le puede hacer a una instancia según su estado. Vive fuera de React porque es
 * regla de negocio: el control de servicio exige `ready`, ninguna acción repite el estado
 * en el que ya está, cambiar de plan necesita uno del que partir, reintentar solo aplica a
 * un trabajo fallido o a una baja, y una baja hecha o en curso no se repite.
 */
export function availableActions(instance: Instance): InstanceAction[] {
  const ready = instance.provisioning_status === 'ready';
  const actions: InstanceAction[] = [];
  if (ready && instance.service_status !== 'grace') actions.push('grace');
  if (ready && instance.service_status !== 'suspended') actions.push('suspend');
  if (ready && instance.service_status !== 'active') actions.push('resume');
  if (ready && instance.plan_id) actions.push('change-plan');
  if (instance.provisioning_status === 'failed' || instance.provisioning_status === 'deprovisioned') {
    actions.push('retry');
  }
  if (!['deprovisioning', 'deprovisioned'].includes(instance.provisioning_status)) {
    actions.push('deprovision');
  }
  return actions;
}

/** Failed, suspendida o en gracia: lo que un operador debe mirar hoy. */
export function hasIncident(instance: Instance): boolean {
  return instance.provisioning_status === 'failed' || instance.service_status !== 'active';
}

/** El sondeo para a los diez minutos: el trabajo sigue, la consulta no. */
export const POLL_INTERVAL_MS = 5000;
export const POLL_MAX_TICKS = 120;

export interface DialogContext {
  clientName: string;
  clientSlug: string;
  /** Valor por defecto de las preferencias del operador, no una constante de la interfaz. */
  graceHours: number;
  currentPlan: string;
  /** Planes activos de la misma aplicación, ya sin el actual. */
  planOptions: { value: string; label: string }[];
}

export interface ActionDialog {
  kicker: string;
  title: string;
  body: string;
  submitLabel: string;
  danger?: boolean;
  fields: ModalField[];
  initial: ModalData;
  /** Lo que se anuncia cuando el servidor confirma. */
  done: string;
}

/**
 * El texto de cada acción vive aquí y no en la página porque el listado y la ficha abren
 * el mismo diálogo: duplicarlo sería duplicar el contrato de negocio, no solo la copia.
 */
export function actionDialog(
  action: InstanceAction,
  instance: Instance,
  ctx: DialogContext,
): ActionDialog {
  const withClient = `Instancia ${instance.id} · ${ctx.clientName}`;
  const alone = `Instancia ${instance.id}`;

  if (action === 'grace') {
    return {
      kicker: withClient,
      title: 'Abrir periodo de gracia',
      submitLabel: 'Abrir gracia',
      body: 'La instancia sigue sirviendo tráfico hasta grace_until. Se registra la razón operativa.',
      fields: [
        { key: 'hours', label: 'Horas de gracia', type: 'number', helper: 'Entre 1 y 720' },
        {
          key: 'reason',
          label: 'Razón',
          type: 'select',
          options: [
            { value: 'quota_exceeded', label: 'Cuota superada' },
            { value: 'invoice_overdue', label: 'Factura vencida' },
            { value: 'commercial_hold', label: 'Acuerdo comercial' },
            { value: 'other', label: 'Otra' },
          ],
        },
        { key: 'message', label: 'Mensaje visible en la ficha', type: 'textarea' },
      ],
      initial: { hours: ctx.graceHours, reason: 'quota_exceeded', message: '' },
      done: `Gracia abierta en la instancia ${instance.id}.`,
    };
  }

  if (action === 'suspend') {
    return {
      kicker: withClient,
      title: 'Suspender el servicio',
      submitLabel: 'Suspender',
      danger: true,
      body: 'La instancia responderá 402 a todo el tráfico. La base tenant y sus datos se conservan.',
      fields: [
        {
          key: 'reason',
          label: 'Razón',
          type: 'select',
          options: [
            { value: 'invoice_overdue', label: 'Factura vencida' },
            { value: 'quota_exceeded', label: 'Cuota superada' },
            { value: 'abuse', label: 'Uso abusivo' },
            { value: 'other', label: 'Otra' },
          ],
        },
        { key: 'message', label: 'Mensaje', type: 'textarea' },
      ],
      initial: { reason: 'invoice_overdue', message: '' },
      done: `Instancia ${instance.id} suspendida.`,
    };
  }

  if (action === 'resume') {
    return {
      kicker: alone,
      title: 'Reanudar el servicio',
      submitLabel: 'Reanudar',
      body: 'El servicio vuelve a estado activo y se cierra la gracia si estaba abierta.',
      fields: [{ key: 'message', label: 'Mensaje (opcional)', type: 'textarea' }],
      initial: { message: '' },
      done: `Instancia ${instance.id} reanudada.`,
    };
  }

  if (action === 'change-plan') {
    return {
      kicker: alone,
      title: 'Cambiar plan operativo',
      submitLabel: 'Cambiar plan',
      body: 'Solo planes activos de la misma aplicación. Materializa de nuevo los límites de la instancia; el precio pactado del contrato no se recalcula.',
      fields: [
        {
          key: 'plan_id',
          label: 'Plan destino',
          type: 'select',
          // El plan actual no es un campo aparte: es la referencia contra la que se elige.
          helper: `Plan actual: ${ctx.currentPlan}`,
          options: [{ value: '', label: 'Elige un plan' }, ...ctx.planOptions],
        },
      ],
      initial: { plan_id: '' },
      done: `Plan de la instancia ${instance.id} cambiado y cuotas materializadas.`,
    };
  }

  if (action === 'retry') {
    return {
      kicker: alone,
      title: 'Reintentar el alta',
      submitLabel: 'Reintentar',
      body: 'Retry reutiliza el mismo ID de instancia y exige credenciales nuevas del administrador tenant.',
      fields: [
        {
          key: 'password',
          label: 'Nueva contraseña del administrador',
          type: 'password',
          helper: 'Doce caracteres con letras y números.',
        },
      ],
      initial: { password: '' },
      done: `Reintento encolado para la instancia ${instance.id}.`,
    };
  }

  return {
    kicker: withClient,
    title: 'Dar de baja la instancia',
    submitLabel: 'Dar de baja',
    danger: true,
    body: `Se eliminan los recursos y la instancia queda como dada de baja. La ficha histórica se conserva en los listados. Escribe «${ctx.clientSlug}» para confirmar.`,
    fields: [
      { key: 'confirm', label: 'Slug del cliente', placeholder: ctx.clientSlug },
      {
        key: 'reason',
        label: 'Razón',
        type: 'select',
        options: [
          { value: 'operator_request', label: 'Petición del operador' },
          { value: 'contract_ended', label: 'Contrato terminado' },
          { value: 'abuse', label: 'Uso abusivo' },
        ],
      },
    ],
    initial: { confirm: '', reason: 'operator_request' },
    done: `Baja iniciada para la instancia ${instance.id}.`,
  };
}
