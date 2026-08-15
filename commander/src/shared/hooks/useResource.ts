import { useCallback, useEffect, useRef, useState } from 'react';
import type { ApiError } from '@services/api/mock/commander-mock';

export interface Resource<T> {
  data: T | null;
  /** Primera carga: la vista muestra esqueleto. */
  loading: boolean;
  /** Recarga sobre datos ya visibles: indicador discreto, sin desmontar la tabla. */
  refreshing: boolean;
  error: ApiError | null;
  reload: () => void;
  set: (next: T) => void;
}

/**
 * Carga asíncrona con los cuatro estados del contrato de página: esqueleto, dato,
 * refresco discreto y error con reintento. `deps` reejecuta la petición.
 */
export function useResource<T>(load: () => Promise<T>, deps: unknown[] = []): Resource<T> {
  const [data, setData] = useState<T | null>(null);
  const [error, setError] = useState<ApiError | null>(null);
  const [pending, setPending] = useState(true);
  const hasData = useRef(false);
  const runId = useRef(0);
  const loadRef = useRef(load);
  loadRef.current = load;

  const run = useCallback(() => {
    const id = ++runId.current;
    setPending(true);
    setError(null);
    loadRef
      .current()
      .then((next) => {
        if (id !== runId.current) return;
        hasData.current = true;
        setData(next);
        setPending(false);
      })
      .catch((e: ApiError) => {
        if (id !== runId.current) return;
        setError(e);
        setPending(false);
      });
  }, []);

  useEffect(run, deps);

  const set = useCallback((next: T) => {
    hasData.current = true;
    setData(next);
  }, []);

  return {
    data,
    loading: pending && !hasData.current,
    refreshing: pending && hasData.current,
    error,
    reload: run,
    set,
  };
}
