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
  // Sin medición no hay porcentaje aunque haya límite: `pct` divide el nulo y devuelve
  // cero, que se leería como «va sobradísima» justo cuando no se sabe nada.
  const p = value === null ? null : pct(value, limit);
  const tone = p === null ? '' : p >= 100 ? '--crit' : p >= 80 ? '--warn' : '--ok';

  return (
    <div className="cmd-meter">
      <div className="cmd-meter__value">
        <span className={p === null ? undefined : `cmd-meter__pct cmd-meter__pct${tone}`}>
          {p === null ? (value === null ? 'Sin medición' : 'Sin límite') : `${p} %`}
        </span>
        <span className="cmd-meter__caption">{fmtNum(value)}</span>
      </div>
      {p !== null && (
        <div className="cmd-meter__track">
          <span
            className={`cmd-meter__fill cmd-meter__fill${tone}`}
            style={{ inlineSize: `${Math.min(100, p)}%` }}
          />
        </div>
      )}
      {caption && <span className="cmd-meter__caption">{caption}</span>}
    </div>
  );
}
