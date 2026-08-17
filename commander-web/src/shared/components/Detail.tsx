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
      <TabList aria-label="Secciones de la ficha" contained>
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
}

/** Lista de definición: etiqueta y dato, con los timestamps siempre absolutos. */
export function Defs({ items }: { items: DefItem[] }) {
  return (
    <dl className="cmd-defs">
      {items.map((item) => (
        <div key={item.label} style={{ display: 'contents' }}>
          <dt>{item.label}</dt>
          <dd>{item.value ?? '—'}</dd>
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
