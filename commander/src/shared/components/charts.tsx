import type { ReactNode } from 'react';
import { LineChart } from '@carbon/charts-react';
import { ScaleTypes } from '@carbon/charts';
import '@carbon/charts/styles.css';
import { Link } from 'react-router-dom';
import { fmtNum } from '@services/api/mock/commander-mock';

/** Toda gráfica lleva marco, título y una línea que explica qué se está mirando. */
export function ChartBlock({
  title,
  help,
  summary,
  children,
}: {
  title: string;
  help?: string;
  /** Mismo dato en texto: la gráfica nunca es la única vía de acceso. */
  summary?: ReactNode;
  children: ReactNode;
}) {
  return (
    <section className="cmd-chart">
      <h3 className="cmd-chart__title">{title}</h3>
      {help && <span className="cmd-chart__help">{help}</span>}
      {children}
      {summary && <p className="cmd-meter__caption">{summary}</p>}
    </section>
  );
}

export interface SeriesPoint {
  group: string;
  date: string;
  value: number;
}

/**
 * Serie temporal de requests y errores. Los errores viven en el eje derecho:
 * comparten el tiempo pero no la escala, y mezclarlos aplanaría los 5xx.
 */
export function RequestsSeries({ data, height = '260px' }: { data: SeriesPoint[]; height?: string }) {
  return (
    <LineChart
      data={data}
      options={{
        theme: 'g100',
        height,
        toolbar: { enabled: false },
        points: { enabled: false },
        curve: 'curveMonotoneX',
        axes: {
          bottom: { mapsTo: 'date', scaleType: ScaleTypes.TIME },
          left: { mapsTo: 'value', title: 'Requests', scaleType: ScaleTypes.LINEAR },
          right: {
            mapsTo: 'value',
            title: 'Errores',
            scaleType: ScaleTypes.LINEAR,
            correspondingDatasets: ['4xx', '5xx'],
          },
        },
        color: { scale: { Requests: '#4589ff', '4xx': '#f1c21b', '5xx': '#fa4d56' } },
      }}
    />
  );
}

export interface BarDatum {
  label: string;
  value: number;
  to?: string;
  /** Marca opcional de umbral, en porcentaje del eje. */
  warnAt?: number;
  tone?: 'ok' | 'warn' | 'crit';
}

/** Barras horizontales en HTML: legibles con lector de pantalla y sin coste de librería. */
export function HBars({ items, unit }: { items: BarDatum[]; unit?: string }) {
  const axis = Math.max(1, ...items.map((i) => i.value));
  return (
    <ul className="cmd-hbars">
      {items.map((item) => {
        const width = `${(item.value / axis) * 100}%`;
        const label = (
          <>
            <span className="cmd-hbars__label">{item.label}</span>
            <span className="cmd-hbars__value">
              {fmtNum(item.value)}
              {unit ? ` ${unit}` : ''}
            </span>
          </>
        );
        return (
          <li key={item.label} className="cmd-hbars__row">
            {item.to ? (
              <Link className="cmd-hbars__head" to={item.to}>
                {label}
              </Link>
            ) : (
              <span className="cmd-hbars__head">{label}</span>
            )}
            <span className="cmd-hbars__track">
              <span
                className={`cmd-hbars__fill${item.tone ? ` cmd-hbars__fill--${item.tone}` : ''}`}
                style={{ inlineSize: width }}
              />
              {item.warnAt !== undefined && (
                <span className="cmd-hbars__warn" style={{ insetInlineStart: `${item.warnAt}%` }} />
              )}
            </span>
          </li>
        );
      })}
    </ul>
  );
}

/** Semicírculo: proporción de un total. El número siempre va escrito en el centro. */
export function Gauge({
  value,
  total,
  caption,
}: {
  value: number;
  total: number;
  caption: string;
}) {
  const radius = 92;
  const arc = Math.PI * radius;
  const share = total === 0 ? 0 : value / total;

  return (
    <div className="cmd-gauge">
      <svg viewBox="0 0 220 120" role="img" aria-label={`${value} de ${total}. ${caption}`}>
        <path
          d="M 18 110 A 92 92 0 0 1 202 110"
          fill="none"
          stroke="var(--cds-layer-accent-01)"
          strokeWidth="14"
        />
        <path
          d="M 18 110 A 92 92 0 0 1 202 110"
          fill="none"
          stroke="var(--cds-support-success)"
          strokeWidth="14"
          strokeDasharray={arc.toFixed(1)}
          strokeDashoffset={(arc * (1 - share)).toFixed(1)}
        />
        <text x="110" y="98" textAnchor="middle" className="cmd-gauge__value">
          {fmtNum(value)}
        </text>
      </svg>
      <p className="cmd-meter__caption">{caption}</p>
    </div>
  );
}

/** Sparkline de 30 barras: tendencia, no lectura exacta. El detalle va en la tabla. */
export function Sparkline({
  points,
  limit,
  warningPercent,
}: {
  points: { date: string; value: number }[];
  limit?: number | null;
  warningPercent?: number | null;
}) {
  const max = Math.max(1, ...points.map((p) => p.value), limit ?? 0);
  const warnAt = limit && warningPercent ? (limit * warningPercent) / 100 : null;

  return (
    <ul className="cmd-spark" aria-hidden="true">
      {points.map((p) => {
        const tone =
          limit && p.value > limit
            ? ' cmd-spark__bar--crit'
            : warnAt && p.value >= warnAt
              ? ' cmd-spark__bar--warn'
              : '';
        return (
          <li
            key={p.date}
            className={`cmd-spark__bar${tone}`}
            style={{ blockSize: `${Math.max(2, (p.value / max) * 100)}%` }}
          />
        );
      })}
    </ul>
  );
}
