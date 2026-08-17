import { Tag } from '@carbon/react';
import type { Catalog } from '@services/api/mock/commander-mock';

/** Los tonos del mock son exactamente los tipos de Tag de Carbon. */
type TagType =
  | 'red'
  | 'magenta'
  | 'purple'
  | 'blue'
  | 'cyan'
  | 'teal'
  | 'green'
  | 'gray'
  | 'cool-gray'
  | 'warm-gray'
  | 'high-contrast'
  | 'outline';

/**
 * Estado = texto + color. Nunca solo color: la etiqueta traducida es el dato,
 * el tono es refuerzo. Un valor desconocido se muestra crudo en lugar de ocultarse.
 */
export function StatusTag({
  catalog,
  value,
  size = 'sm',
}: {
  catalog: Catalog<{ tone: string }>;
  value: string | null | undefined;
  size?: 'sm' | 'md' | 'lg';
}) {
  if (!value) return <span className="cmd-meter__caption">—</span>;
  const entry = catalog[value];
  return (
    <Tag type={(entry?.tone as TagType) ?? 'gray'} size={size}>
      {entry?.label ?? value}
    </Tag>
  );
}
