import { useMemo, type ReactNode } from 'react';
import { LineChart } from '@carbon/charts-react';
import { ChartTheme, ScaleTypes } from '@carbon/charts';
import '@carbon/charts/styles.css';
import { WarningFilled } from '@carbon/icons-react';
import { Link } from '@shared/components/AppLink';
import { fmtNum } from '@services/api/mock/commander-mock';
import { tokenScale } from '@shared/lib/theme';

/**
 * Carbon Charts no lee el tema del documento: si no se le dice, pinta con el claro y
 * el texto se vuelve ilegible sobre el fondo de la aplicación. Va en cada gráfica.
 */
const THEME = ChartTheme.G100;

/** Toda gráfica lleva marco, título y una línea que explica qué se está mirando. */
export function ChartBlock({
  title,
  help,
  summary,
  linkTo,
  linkLabel,
  children,
}: {
  title: string;
  help?: string;
  /** Mismo dato en texto: la gráfica nunca es la única vía de acceso. */
  summary?: ReactNode;
  /** Salida al listado completo cuando el bloque solo enseña los primeros. */
  linkTo?: string;
  linkLabel?: string;
  children: ReactNode;
}) {
  return (
    <section className="cmd-chart">
      <div className="cmd-chart__head">
        {/* `h2`: el único `h1` de la página lo pone `PageHeader`, y saltar a `h3` dejaba
            un hueco en la jerarquía que un lector de pantalla anuncia como nivel perdido. */}
        <h2 className="cmd-chart__title">{title}</h2>
        {linkTo && linkLabel && <Link to={linkTo}>{linkLabel}</Link>}
      </div>
      {help && <span className="cmd-chart__help">{help}</span>}
      {/* El resumen precede al dibujo: quien no puede leer la gráfica ya tiene el dato
          antes de llegar a ella. */}
      {summary && <p className="cmd-chart__summary">{summary}</p>}
      {/* La caja con fondo envuelve solo el contenido; el título y su explicación viven
          sobre el fondo de la página. */}
      <div className="cmd-chart__body">{children}</div>
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
  // Los tokens se leen una vez montado, cuando el tema ya está aplicado al documento.
  const scale = useMemo(
    () =>
      tokenScale({
        Requests: '--cds-support-info',
        '4xx': '--cds-support-warning',
        '5xx': '--cds-support-error',
      }),
    [],
  );


  return (
    <LineChart
      data={data}
      options={{
        theme: THEME,
        height,
        toolbar: { enabled: false },
        points: { enabled: false },
        curve: 'curveMonotoneX',
        axes: {
          bottom: { mapsTo: 'date', scaleType: ScaleTypes.TIME, title: 'Tramo' },
          left: { mapsTo: 'value', title: 'Requests', scaleType: ScaleTypes.LINEAR },
          // El eje derecho rotula las series de error, pero comparte dominio con el
          // izquierdo: Carbon Charts no da escalas independientes por eje en un
          // `LineChart`. `correspondingDatasets` solo reparte etiquetas, y forzar
          // `domain` se lo aplica a los dos y expulsa a Requests fuera del lienzo.
          // El dato que importa —la tasa de error— va escrito en el resumen.
          right: {
            mapsTo: 'value',
            title: 'Errores',
            scaleType: ScaleTypes.LINEAR,
            correspondingDatasets: ['4xx', '5xx'],
          },
        },
        color: { scale },
      }}
    />
  );
}

export interface ThresholdBarDatum {
  label: string;
  value: number;
  to?: string;
  /** Marca opcional de umbral, en porcentaje del eje. */
  warnAt?: number;
  tone?: 'ok' | 'warn' | 'crit';
  /** Lectura absoluta ya formateada, del estilo «63 / 40 asientos». */
  reading?: string;
  /** Marca la fila como excedida: añade el icono de aviso junto a la cifra. */
  over?: boolean;
}

/**
 * Cada barra contra su propio límite, con la marca del umbral de aviso. En HTML, no
 * `MeterChart`: cada fila enlaza a su ficha y el envoltorio de React de `@carbon/charts`
 * solo acepta `data` y `options`, sin manejador de clic, así que migrar cambiaría
 * navegación por tooltips. `MeterChart` encaja donde hay UNA medida con su umbral —la
 * ficha de una cuota—; aquí serían seis apilados y el bloque triplicaría su altura.
 */
export function ThresholdBars({
  items,
  unit,
  /**
   * Tope del eje. Sin él manda el mayor valor, que es lo correcto para conteos pero no
   * para porcentajes sobre un límite propio: con seis cuotas entre el 151 % y el 158 %
   * el eje valía 158 y las seis barras salían llenas e indistinguibles, y peor aún, la
   * marca de aviso —que es un porcentaje del límite— caía sobre una escala que ya no
   * iba de 0 a 100, así que señalaba el sitio equivocado.
   */
  axisMax,
  footnote,
}: {
  items: ThresholdBarDatum[];
  unit?: string;
  axisMax?: number;
  /** Explica la marca de aviso; se dibuja con su misma muestra para que se reconozca. */
  footnote?: string;
}) {
  const axis = axisMax ?? Math.max(1, ...items.map((i) => i.value));
  return (
    <div className="cmd-hbars">
      <ul>
        {items.map((item) => (
          <li key={item.label} className="cmd-hbars__row">
            <div className="cmd-hbars__head">
              {/* Solo la etiqueta enlaza: la cifra y el porcentaje son lectura, no destino. */}
              {item.to ? (
                <Link className="cmd-hbars__label" to={item.to}>
                  {item.label}
                </Link>
              ) : (
                <span className="cmd-hbars__label">{item.label}</span>
              )}
              {item.reading && <span className="cmd-hbars__reading">{item.reading}</span>}
              <span
                className={`cmd-hbars__value${item.tone ? ` cmd-hbars__value--${item.tone}` : ''}`}
              >
                {item.over && <WarningFilled size={16} />}
                {fmtNum(item.value)}
                {unit ? ` ${unit}` : ''}
              </span>
            </div>
            <span className="cmd-hbars__track">
              <span
                className={`cmd-hbars__fill${item.tone ? ` cmd-hbars__fill--${item.tone}` : ''}`}
                style={{ inlineSize: `${Math.min(100, (item.value / axis) * 100)}%` }}
              />
              {item.warnAt !== undefined && (
                <span
                  className="cmd-hbars__warn"
                  title={`Aviso al ${item.warnAt} % del límite`}
                  style={{ insetInlineStart: `${Math.min(100, item.warnAt)}%` }}
                />
              )}
            </span>
          </li>
        ))}
      </ul>

      {footnote && (
        <p className="cmd-hbars__footnote">
          <span className="cmd-hbars__warn-sample" aria-hidden="true" />
          {footnote}
        </p>
      )}
    </div>
  );
}

export interface LegendDatum {
  label: string;
  value: number;
  percent: number;
  /** Tono de tag de Carbon; el color sale de `--cds-tag-color-<tono>`. */
  tone: string;
  to?: string;
}

/**
 * Semicírculo con su desglose al lado. No es `GaugeChart` de Carbon a propósito: ese
 * ocupa el bloque entero y no admite una leyenda navegable, y aquí cada estado de
 * servicio tiene que llevar a su listado filtrado. Son doce líneas de SVG.
 */
export function Gauge({
  value,
  total,
  caption,
  legend = [],
  footer,
}: {
  value: number;
  total: number;
  caption: string;
  legend?: LegendDatum[];
  footer?: string;
}) {
  const share = total === 0 ? 0 : value / total;
  const arc = Math.PI * 92;

  return (
    <div className="cmd-gauge">
      <div className="cmd-gauge__dial">
        <svg viewBox="0 0 208 116" role="img" aria-label={`${Math.round(share * 100)} %. ${caption}`}>
          <path
            d="M 12 104 A 92 92 0 0 1 196 104"
            fill="none"
            stroke="var(--cds-layer-accent-01)"
            strokeWidth="16"
          />
          <path
            d="M 12 104 A 92 92 0 0 1 196 104"
            fill="none"
            stroke="var(--cds-tag-color-green)"
            strokeWidth="16"
            strokeDasharray={arc.toFixed(1)}
            strokeDashoffset={(arc * (1 - share)).toFixed(1)}
          />
        </svg>
        <div className="cmd-gauge__readout">
          <span className="cmd-gauge__pct">{Math.round(share * 100)} %</span>
          <span className="cmd-meter__caption">{caption}</span>
        </div>
      </div>

      <div className="cmd-gauge__legend">
        {legend.map((item) => {
          const row = (
            <>
              <span
                className="cmd-gauge__swatch"
                style={{ background: `var(--cds-tag-color-${item.tone})` }}
              />
              <span>{item.label}</span>
              <span className="cmd-gauge__legend-value">
                {fmtNum(item.value)} · {item.percent} %
              </span>
            </>
          );
          return item.to ? (
            <Link key={item.label} className="cmd-gauge__legend-row" to={item.to}>
              {row}
            </Link>
          ) : (
            <span key={item.label} className="cmd-gauge__legend-row">
              {row}
            </span>
          );
        })}
        {footer && <p className="cmd-meter__caption">{footer}</p>}
      </div>
    </div>
  );
}

export interface AxisBarDatum {
  label: string;
  value: number;
  to?: string;
  /** Tono de tag de Carbon; el color sale de `--cds-tag-color-<tono>`. */
  tone: string;
}

/**
 * Conteos con eje común: etiqueta, barra y valor en tres columnas, y los ticks debajo.
 * Distinto de `ThresholdBars`, donde cada barra se mide contra su propio límite.
 *
 * El eje sube a la decena siguiente para que los ticks caigan en números redondos, y una
 * categoría con valor no baja del 1 % de ancho: si existe una instancia, tiene que verse.
 */
export function AxisBars({ items, total }: { items: AxisBarDatum[]; total?: string }) {
  const max = Math.max(0, ...items.map((i) => i.value));
  const axis = Math.max(10, Math.ceil(max / 10) * 10);
  const sum = items.reduce((t, i) => t + i.value, 0) || 1;

  return (
    <div className="cmd-axisbars">
      <ul>
        {items.map((item) => {
          const row = (
            <>
              <span className="cmd-axisbars__label">{item.label}</span>
              <span className="cmd-axisbars__track">
                <span
                  className="cmd-axisbars__fill"
                  style={{
                    inlineSize: item.value ? `${Math.max(1, (item.value / axis) * 100)}%` : 0,
                    background: `var(--cds-tag-color-${item.tone})`,
                  }}
                />
              </span>
              <span className="cmd-axisbars__value">
                {fmtNum(item.value)} · {Math.round((item.value / sum) * 100)} %
              </span>
            </>
          );
          return (
            <li key={item.label}>
              {item.to ? (
                <Link
                  className="cmd-axisbars__row"
                  to={item.to}
                  title={`${item.label}: ${fmtNum(item.value)}`}
                >
                  {row}
                </Link>
              ) : (
                <span className="cmd-axisbars__row">{row}</span>
              )}
            </li>
          );
        })}
      </ul>

      <div className="cmd-axisbars__axis">
        <span />
        <div>
          {[0, axis / 2, axis].map((tick) => (
            <span key={tick}>{fmtNum(tick)}</span>
          ))}
        </div>
        <span />
      </div>

      {total && <p className="cmd-meter__caption">{total}</p>}
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
