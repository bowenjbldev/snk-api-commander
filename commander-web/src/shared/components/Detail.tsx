import type { ReactNode } from 'react';
import { Tab, TabList, TabPanel, TabPanels, Tabs } from '@carbon/react';
import { useNavigate } from 'react-router-dom';

export interface TabDef {
  slug: string;
  label: string;
}

/**
 * Las pestañas son rutas: `/instances/42/usage` sobrevive a la recarga y se puede
 * compartir, cosa que un estado local pierde.
 *
 * El contenido va DENTRO, en su `TabPanel`: cada `Tab` apunta a un panel con
 * `aria-controls`, y sin panel esa referencia queda colgando para un lector de
 * pantalla. Solo el panel activo recibe hijos; los demás quedan vacíos.
 */
export function DetailTabs({
  base,
  tabs,
  current,
  children,
}: {
  base: string;
  tabs: TabDef[];
  current: string;
  children: ReactNode;
}) {
  const navigate = useNavigate();
  const index = Math.max(
    0,
    tabs.findIndex((t) => t.slug === current),
  );

  return (
    <Tabs
      selectedIndex={index}
      onChange={({ selectedIndex }) => {
        const tab = tabs[selectedIndex];
        navigate(tab.slug === tabs[0].slug ? base : `${base}/${tab.slug}`);
      }}
    >
      {/* Pestañas de línea: el subrayado marca la sección sin encajonar la ficha en
          una caja más, que es lo que hace la variante `contained`. */}
      <TabList aria-label="Secciones de la ficha">
        {tabs.map((t) => (
          <Tab key={t.slug}>{t.label}</Tab>
        ))}
      </TabList>
      <TabPanels>
        {tabs.map((t, i) => (
          <TabPanel key={t.slug}>{i === index ? children : null}</TabPanel>
        ))}
      </TabPanels>
    </Tabs>
  );
}

export interface DefItem {
  label: string;
  value: ReactNode;
  /** La letra pequeña del dato: de dónde sale, qué implica o desde cuándo. */
  hint?: ReactNode;
}

/**
 * Lista de definición en rejilla: rótulo encima del dato, y tantas columnas como
 * quepan. Un dl a dos columnas obliga a una etiqueta corta y deja media ficha vacía
 * cuando los valores son cortos, que es lo normal aquí.
 */
export function Defs({ items }: { items: DefItem[] }) {
  return (
    <dl className="cmd-defs">
      {items.map((item) => (
        <div key={item.label}>
          <dt>{item.label}</dt>
          <dd>{item.value ?? '—'}</dd>
          {item.hint && <dd className="cmd-defs__hint">{item.hint}</dd>}
        </div>
      ))}
    </dl>
  );
}

export function Panel({ title, children }: { title?: string; children: ReactNode }) {
  return (
    <section className="cmd-panel">
      {/* `h2` por lo mismo que en `ChartBlock`: el `h1` es el título de la página. */}
      {title && <h2 className="cmd-panel__title">{title}</h2>}
      {children}
    </section>
  );
}
