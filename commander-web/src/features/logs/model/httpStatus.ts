/**
 * Un 5xx es culpa nuestra y un 4xx del cliente: se pintan distinto, nunca igual.
 * Lo demás va en verde, porque en una tabla de alta densidad el ojo busca lo que
 * se sale, y para eso todo lo correcto tiene que compartir un mismo color.
 */
export function statusTone(status: number): 'error' | 'warn' | 'ok' {
  if (status >= 500) return 'error';
  if (status >= 400) return 'warn';
  return 'ok';
}
