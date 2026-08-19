import { useEffect, useRef } from 'react';
import type { ReactNode } from 'react';
import { Breadcrumb, BreadcrumbItem } from '@carbon/react';
import { useLocation } from 'react-router-dom';
import { Link } from '@shared/components/AppLink';
import { kickerFor } from '@shared/lib/section';

export interface Crumb {
  label: string;
  to?: string;
}

/**
 * Cabecera única de página: un solo `h1`, migas y acciones contextuales.
 * Al montar mueve el foco al título para que navegar no deje al teclado atrás.
 */
export function PageHeader({
  title,
  kicker,
  lede,
  statuses,
  crumbs,
  actions,
}: {
  title: string;
  /**
   * Lo propio de la página —«Instancia 42»—. La sección de la navegación la pone
   * la ruta: decir dónde estás antes de decir qué estás mirando es del contrato,
   * no de cada página.
   */
  kicker?: string;
  lede?: ReactNode;
  /**
   * Las pastillas de estado de la ficha. Van al final de la descripción, no en una fila
   * propia: son dos palabras y una fila entera solo alarga la cabecera.
   */
  statuses?: ReactNode;
  crumbs?: Crumb[];
  actions?: ReactNode;
}) {
  const heading = useRef<HTMLHeadingElement>(null);
  const { pathname } = useLocation();
  useEffect(() => {
    heading.current?.focus();
    document.title = `${title} · Commander`;
  }, [title]);

  return (
    <header className="cmd-page__head">
      <div>
        {/* Migas o rótulo, nunca los dos: ambos dicen dónde estás, y juntos son la misma
            frase escrita dos veces. */}
        {crumbs?.length ? (
          <Breadcrumb noTrailingSlash>
            {crumbs.map((c) =>
              c.to ? (
                <BreadcrumbItem key={c.label}>
                  <Link to={c.to}>{c.label}</Link>
                </BreadcrumbItem>
              ) : (
                <BreadcrumbItem key={c.label} isCurrentPage>
                  {c.label}
                </BreadcrumbItem>
              ),
            )}
          </Breadcrumb>
        ) : (
          <span className="cmd-page__kicker">{kickerFor(pathname, kicker)}</span>
        )}
        {/* Las pastillas acompañan al título, no a la descripción: el estado es de la
            cosa, no de la frase que la explica. Y van fuera del `h1`, que solo admite
            contenido de texto. */}
        <div className="cmd-page__headline">
          <h1 className="cmd-page__title" tabIndex={-1} ref={heading}>
            {title}
          </h1>
          {statuses && <span className="cmd-page__tags">{statuses}</span>}
        </div>
        {lede && <p className="cmd-page__lede">{lede}</p>}
      </div>
      {actions && <div className="cmd-page__actions">{actions}</div>}
    </header>
  );
}
