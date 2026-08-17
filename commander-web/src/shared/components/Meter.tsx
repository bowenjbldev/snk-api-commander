import { fmtNum, pct } from '@services/api/mock/commander-mock';

/**
 * Consumo frente a límite. Sin límite no hay porcentaje: se dice «Sin límite»,
 * no cero. Un valor nulo es «Sin medición». El porcentaje va escrito, no solo pintado.
 */
export function Meter({
  value,
  limit,
  caption,
}: {
  value: number | null;
  limit: number | null;
  caption?: string;
}) {
  const p = pct(value, limit);
  const tone = p === null ? '' : p >= 100 ? ' cmd-meter__fill--crit' : p >= 80 ? ' cmd-meter__fill--warn' : '';

  return (
    <div className="cmd-meter">
      <div className="cmd-meter__value">
        <span>{p === null ? (value === null ? 'Sin medición' : 'Sin límite') : `${p} %`}</span>
        <span className="cmd-meter__caption">{fmtNum(value)}</span>
      </div>
      {p !== null && (
        <div className="cmd-meter__track">
          <span className={`cmd-meter__fill${tone}`} style={{ inlineSize: `${Math.min(100, p)}%` }} />
        </div>
      )}
      {caption && <span className="cmd-meter__caption">{caption}</span>}
    </div>
  );
}
