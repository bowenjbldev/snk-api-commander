import { useEffect, useRef } from 'react';
import type { ReactNode } from 'react';
import { Breadcrumb, BreadcrumbItem } from '@carbon/react';
import { Link } from '@shared/components/AppLink';

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
  crumbs,
  actions,
}: {
  title: string;
  /** Sección de la navegación a la que pertenece la página, o su identificador. */
  kicker?: string;
  lede?: ReactNode;
  crumbs?: Crumb[];
  actions?: ReactNode;
}) {
  const heading = useRef<HTMLHeadingElement>(null);
  useEffect(() => {
    heading.current?.focus();
    document.title = `${title} · Commander`;
  }, [title]);

  return (
    <header className="cmd-page__head">
      <div>
        {crumbs && crumbs.length > 0 && (
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
        )}
        {kicker && <span className="cmd-page__kicker">{kicker}</span>}
        <h1 className="cmd-page__title" tabIndex={-1} ref={heading}>
          {title}
        </h1>
        {/* Un `div`, no un `p`: varias fichas ponen Tags en el lede y Carbon los renderiza como `div`. */}
        {lede && <div className="cmd-page__lede">{lede}</div>}
      </div>
      {actions && <div className="cmd-page__actions">{actions}</div>}
    </header>
  );
}
