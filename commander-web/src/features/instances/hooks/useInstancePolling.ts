import { useEffect, useRef, useState } from 'react';
import { POLL_INTERVAL_MS, POLL_MAX_TICKS } from '../model/instanceActions';

/**
 * El alta y la baja responden 202: el estado real solo llega consultando la ficha.
 * Se sondea cada cinco segundos mientras haya trabajo en vuelo y se corta a los diez
 * minutos — el trabajo sigue en el servidor, lo que para es la consulta automática.
 */
export function useInstancePolling(active: boolean, reload: () => void, resetKey: string) {
  const [expired, setExpired] = useState(false);
  const reloadRef = useRef(reload);
  reloadRef.current = reload;

  useEffect(() => {
    if (!active) {
      setExpired(false);
      return;
    }
    let ticks = 0;
    const timer = setInterval(() => {
      ticks += 1;
      reloadRef.current();
      if (ticks >= POLL_MAX_TICKS) {
        setExpired(true);
        clearInterval(timer);
      }
    }, POLL_INTERVAL_MS);
    return () => clearInterval(timer);
  }, [active, resetKey]);

  return expired;
}
