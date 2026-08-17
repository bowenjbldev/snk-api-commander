import type { ReactNode } from 'react';
import { ActionableNotification, Callout } from '@carbon/react';
import type { ApiError } from '@services/api/mock/commander-mock';

/** Sin datos y sin resultados son estados distintos: el segundo se arregla quitando filtros. */
export function EmptyState({
  title,
  body,
  action,
}: {
  title: string;
  body: string;
  action?: ReactNode;
}) {
  return (
    <div className="cmd-panel cmd-stack">
      <p className="cmd-panel__title">{title}</p>
      <p className="cmd-page__lede">{body}</p>
      {action}
    </div>
  );
}

/**
 * Un error nunca deja la vista muda: dice qué pasó y ofrece reintentar.
 * El reintento va dentro de la notificación (`ActionableNotification`), no como
 * botón suelto al lado. Un 403 o un 404 no se arreglan repitiendo la misma
 * petición, así que ahí es un `Callout` sin acción.
 */
export function ErrorState({ error, onRetry }: { error: ApiError; onRetry: () => void }) {
  const title =
    error.status === 403
      ? 'Sin permiso'
      : error.status === 404
        ? 'No encontrado'
        : error.status === 409
          ? 'Conflicto operativo'
          : 'No se pudo cargar';

  if (error.status === 403 || error.status === 404) {
    return (
      <Callout
        kind={error.status === 404 ? 'info' : 'warning'}
        lowContrast
        title={title}
        subtitle={error.message}
      />
    );
  }

  return (
    <ActionableNotification
      kind="error"
      lowContrast
      hideCloseButton
      title={title}
      subtitle={error.message}
      actionButtonLabel="Reintentar"
      onActionButtonClick={onRetry}
    />
  );
}

/** Contexto permanente, no un aviso que se cierra: por eso es un Callout. */
export function Forbidden({ perm }: { perm: string }) {
  return (
    <Callout
      kind="warning"
      lowContrast
      title="Sin permiso"
      subtitle={`Tu rol no tiene el permiso «${perm}». El servidor sigue siendo la autoridad: la interfaz solo oculta lo que no puedes usar.`}
    />
  );
}
