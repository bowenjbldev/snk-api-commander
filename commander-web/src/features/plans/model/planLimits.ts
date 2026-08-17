import type { Application, Plan, PlanLimit, QuotaAction } from '@services/api/types';

/** Una fila del editor: el límite puede estar vacío mientras se escribe. */
export type PlanLimitDraft = PlanLimit;

/**
 * El editor muestra una fila por métrica ACTIVA de la aplicación, no por límite ya
 * guardado: si el package declara una métrica nueva, aparece vacía en vez de faltar.
 */
export function draftLimitsFor(application: Application | undefined, plan: Plan | undefined): PlanLimitDraft[] {
  return (application?.metrics ?? []).map((metric) => {
    const existing = plan?.limits.find((l) => l.metric_key === metric.key);
    return (
      existing ?? {
        metric_key: metric.key,
        limit_value: null,
        warning_percent: 80,
        action: 'warn' as QuotaAction,
        consecutive_periods: 1,
      }
    );
  });
}

/** Sin límite no hay nada que hacer cumplir: la acción cae a observación. */
export function hasNoLimit(row: PlanLimitDraft): boolean {
  return row.limit_value === null || String(row.limit_value) === '';
}

export function effectiveAction(row: PlanLimitDraft): QuotaAction {
  return hasNoLimit(row) ? 'none' : row.action;
}

/** Normaliza antes de enviar: un límite vacío viaja como `null`, no como cero. */
export function normalizeLimits(rows: PlanLimitDraft[]): PlanLimit[] {
  return rows.map((row) => {
    if (hasNoLimit(row)) {
      return { metric_key: row.metric_key, limit_value: null, warning_percent: null, action: 'none', consecutive_periods: null };
    }
    return {
      metric_key: row.metric_key,
      limit_value: Number(row.limit_value),
      warning_percent: row.warning_percent === null ? null : Number(row.warning_percent),
      action: row.action,
      consecutive_periods: row.consecutive_periods === null ? null : Number(row.consecutive_periods),
    };
  });
}

/** Resumen de una plantilla para la tabla: cuántas métricas limitan y cuántas solo miden. */
export function summarizeLimits(limits: PlanLimit[]): string {
  const withLimit = limits.filter((l) => l.limit_value !== null).length;
  return `${withLimit} con límite · ${limits.length - withLimit} solo medición`;
}
