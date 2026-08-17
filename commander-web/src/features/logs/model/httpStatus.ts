/** Clase de una respuesta HTTP: 2xx, 3xx, 4xx o 5xx. */
export function statusClass(status: number): '2xx' | '3xx' | '4xx' | '5xx' {
  const first = Math.floor(status / 100);
  return (first >= 2 && first <= 5 ? `${first}xx` : '2xx') as '2xx' | '3xx' | '4xx' | '5xx';
}

/** Un 5xx es culpa nuestra y un 4xx del cliente: se pintan distinto, nunca igual. */
export function statusTone(status: number): 'error' | 'warn' | undefined {
  if (status >= 500) return 'error';
  if (status >= 400) return 'warn';
  return undefined;
}

export const STATUS_CLASS_OPTIONS = [
  { value: '2xx', label: '2xx correcto' },
  { value: '3xx', label: '3xx redirección' },
  { value: '4xx', label: '4xx cliente' },
  { value: '5xx', label: '5xx servidor' },
];

export const HTTP_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
