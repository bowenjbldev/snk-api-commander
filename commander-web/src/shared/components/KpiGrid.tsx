import type { CSSProperties, MouseEvent } from 'react';
import { ClickableTile, Tile } from '@carbon/react';
import { useNavigate } from 'react-router-dom';
import { balancedColumns } from '@shared/lib/grid';

export interface KpiItem {
  label: string;
  value: string;
  note: string;
  /** Con `to` la celda entera abre el listado ya filtrado que explica la cifra. */
  to?: string;
  linkLabel?: string;
  tone?: 'crit' | 'warn';
}

/**
 * Rejilla de indicadores. Las columnas salen del número real de celdas —que depende
 * de los permisos del rol— para no dejar huecos sueltos en la última fila; el CSS
 * las ignora en pantallas estrechas y reparte por breakpoint.
 */
export function KpiGrid({ items }: { items: KpiItem[] }) {
  const navigate = useNavigate();
  if (items.length === 0) return null;

  /**
   * `ClickableTile` no es polimórfico: solo acepta `href`, no el `Link` del router. Se
   * conserva el ancla real —abrir en pestaña nueva sigue funcionando— y se intercepta
   * únicamente el clic simple, que es el que debe navegar sin recargar la aplicación.
   */
  const navigateInApp = (to: string) => (event: MouseEvent<HTMLAnchorElement>) => {
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    navigate(to);
  };

  return (
    <div
      className="cmd-kpis"
      style={{ '--cmd-kpi-cols': balancedColumns(items.length) } as CSSProperties}
    >
      {items.map((item) =>
        item.to ? (
          <ClickableTile
            key={item.label}
            href={item.to}
            onClick={navigateInApp(item.to)}
          >
            <KpiBody item={item} />
          </ClickableTile>
        ) : (
          <Tile key={item.label}>
            <KpiBody item={item} />
          </Tile>
        ),
      )}
    </div>
  );
}

function KpiBody({ item }: { item: KpiItem }) {
  return (
    <>
      <p className="cmd-kpi__label">{item.label}</p>
      <p className={`cmd-kpi__value${item.tone ? ` cmd-kpi__value--${item.tone}` : ''}`}>
        {item.value}
      </p>
      <p className="cmd-kpi__note">{item.note}</p>
      {/* Ya no es un enlace propio: anidarlo dentro del ancla del tile sería inválido. */}
      {item.linkLabel && <p className="cmd-kpi__link">{item.linkLabel}</p>}
    </>
  );
}
