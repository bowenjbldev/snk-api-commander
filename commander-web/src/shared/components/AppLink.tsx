import type { ReactNode } from 'react';
import { Link as CarbonLink } from '@carbon/react';
import { Link as RouterLink } from 'react-router-dom';

/**
 * Enlace de contenido: el aspecto de Carbon —sin subrayado, subrayado al apuntar— con la
 * navegación del router. El `Link` pelado de react-router pinta un `<a>` con el subrayado
 * por defecto del navegador, que no es el del sistema de diseño.
 *
 * `Link` de Carbon sí es polimórfico, al contrario que `ClickableTile`.
 */
export function Link({
  to,
  children,
  className,
  title,
  onClick,
}: {
  to: string;
  children: ReactNode;
  className?: string;
  title?: string;
  /** Para cerrar lo que envuelva al enlace —un panel, un menú— al navegar. */
  onClick?: () => void;
}) {
  return (
    <CarbonLink as={RouterLink} to={to} className={className} title={title} onClick={onClick}>
      {children}
    </CarbonLink>
  );
}
