import { useCallback, useMemo } from 'react';
import { useSearchParams } from 'react-router-dom';

/**
 * Estado de vista en la URL: búsqueda, filtros, orden, página y pestaña sobreviven
 * a la recarga y se pueden compartir por enlace. Un valor igual al defecto no
 * ensucia la query.
 */
export function useUrlState<T extends Record<string, string>>(
  defaults: T,
): [T, (patch: Partial<T>) => void] {
  const [params, setParams] = useSearchParams();

  const state = useMemo(() => {
    const out = { ...defaults };
    for (const key of Object.keys(defaults) as (keyof T)[]) {
      const raw = params.get(key as string);
      if (raw !== null) out[key] = raw as T[keyof T];
    }
    return out;
  }, [params, ...Object.values(defaults)]);

  const update = useCallback(
    (patch: Partial<T>) => {
      const next = new URLSearchParams(params);
      for (const [key, value] of Object.entries(patch)) {
        if (value === undefined || value === null || value === defaults[key]) next.delete(key);
        else next.set(key, String(value));
      }
      // Cambiar búsqueda u orden invalida la página actual.
      if (!('page' in patch) && Object.keys(patch).some((k) => k !== 'page')) next.delete('page');
      setParams(next, { replace: true });
    },
    [params, setParams, defaults],
  );

  return [state, update];
}
