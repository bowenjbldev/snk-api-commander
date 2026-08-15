import { useCallback, useEffect, useMemo, useState } from 'react';
import {
  Header,
  HeaderGlobalAction,
  HeaderGlobalBar,
  HeaderMenuButton,
  HeaderName,
  HeaderPanel,
  SideNav,
  SideNavItems,
  SideNavLink,
  SkipToContent,
  Search as SearchInput,
} from '@carbon/react';
import { Notification, Search, UserAvatar } from '@carbon/icons-react';
import { Link, NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom';
import { fmtRel, ROLES } from '@services/api/mock/commander-mock';
import { NAV } from '@app/nav';
import { SimulationBar } from '@app/SimulationBar';
import { useApp } from '@app/provider';

type Panel = 'search' | 'notif' | 'user' | null;

/**
 * Estado de la navegación lateral. Carbon ofrece `HeaderContainer` para esto, pero
 * renderiza el render prop COMO TIPO de elemento (`jsx(Children, …)`): con una flecha
 * inline, cada render del Shell cambia el tipo y React desmonta y remonta todo el
 * árbol —incluida la página enrutada, que vuelve a pedir sus datos—. Cuatro líneas
 * propias evitan ese remontaje.
 */
function useSideNav() {
  const [expanded, setExpanded] = useState(false);
  useEffect(() => {
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setExpanded(false);
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, []);
  return { expanded, toggle: useCallback(() => setExpanded((prev) => !prev), []) };
}

/** 66rem es el mismo umbral con el que Carbon decide si la navegación cabe fijada. */
function useWideViewport() {
  const [wide, setWide] = useState(() => window.matchMedia('(min-width: 66rem)').matches);
  useEffect(() => {
    const query = window.matchMedia('(min-width: 66rem)');
    const sync = () => setWide(query.matches);
    query.addEventListener('change', sync);
    return () => query.removeEventListener('change', sync);
  }, []);
  return wide;
}

export function Shell() {
  const { can } = useApp();
  const [panel, setPanel] = useState<Panel>(null);
  const location = useLocation();
  const wide = useWideViewport();
  const sideNav = useSideNav();

  const groups = NAV.map((g) => ({
    ...g,
    entries: g.entries.filter((e) => !e.perm || can(e.perm)),
  })).filter((g) => g.entries.length > 0);

  return (
    <>
      <Header aria-label="Commander Control plane">
        <SkipToContent />
        <HeaderMenuButton
          aria-label={sideNav.expanded ? 'Cerrar navegación' : 'Abrir navegación'}
          onClick={sideNav.toggle}
          isActive={sideNav.expanded}
          aria-expanded={sideNav.expanded}
        />
        <HeaderName as={Link} to="/" prefix="Commander">
          Control plane
        </HeaderName>

        <HeaderGlobalBar>
          <HeaderGlobalAction
            aria-label="Buscar"
            isActive={panel === 'search'}
            onClick={() => setPanel(panel === 'search' ? null : 'search')}
          >
            <Search size={20} />
          </HeaderGlobalAction>
          <HeaderGlobalAction
            aria-label="Actividad reciente"
            isActive={panel === 'notif'}
            onClick={() => setPanel(panel === 'notif' ? null : 'notif')}
          >
            <Notification size={20} />
          </HeaderGlobalAction>
          <HeaderGlobalAction
            aria-label="Sesión"
            isActive={panel === 'user'}
            onClick={() => setPanel(panel === 'user' ? null : 'user')}
          >
            <UserAvatar size={20} />
          </HeaderGlobalAction>
        </HeaderGlobalBar>

        <HeaderPanel expanded={panel !== null} aria-label="Panel de cabecera">
          {panel === 'search' && <SearchPanel onGo={() => setPanel(null)} />}
          {panel === 'notif' && <NotificationsPanel onGo={() => setPanel(null)} />}
          {panel === 'user' && <UserPanel />}
        </HeaderPanel>

        <SideNav
          isFixedNav
          expanded={wide || sideNav.expanded}
          isChildOfHeader
          aria-label="Navegación principal"
        >
          <SideNavItems>
            {groups.map((group) => (
              <li key={group.title}>
                <p className="cmd-nav__group">{group.title}</p>
                <ul>
                  {group.entries.map((entry) => (
                    <SideNavLink
                      key={entry.to}
                      as={NavLink}
                      to={entry.to}
                      isActive={
                        entry.to === '/'
                          ? location.pathname === '/'
                          : location.pathname.startsWith(entry.to)
                      }
                    >
                      {entry.label}
                    </SideNavLink>
                  ))}
                </ul>
              </li>
            ))}
          </SideNavItems>
        </SideNav>
      </Header>

      <div className="cmd-shell__main">
        <SimulationBar />
        <main id="main-content" className="cmd-shell__content">
          <Outlet />
        </main>
      </div>
    </>
  );
}

/** Búsqueda global sobre el store: mismas reglas de permiso que la navegación. */
function SearchPanel({ onGo }: { onGo: () => void }) {
  const { store, can } = useApp();
  const [q, setQ] = useState('');
  const navigate = useNavigate();

  const results = useMemo(() => {
    const term = q.trim().toLowerCase();
    if (term.length < 2) return [];
    const out: { label: string; hint: string; to: string }[] = [];
    const push = (perm: string, rows: { label: string; hint: string; to: string }[]) => {
      if (can(perm)) out.push(...rows);
    };
    push(
      'index instances',
      store.instances
        .filter((i) => String(i.id).includes(term))
        .map((i) => ({ label: `Instancia ${i.id}`, hint: i.database_name, to: `/instances/${i.id}` })),
    );
    push(
      'index accounts',
      store.accounts
        .filter((a) => a.name.toLowerCase().includes(term))
        .map((a) => ({ label: a.name, hint: 'Cuenta', to: `/accounts/${a.id}` })),
    );
    push(
      'index clients',
      store.clients
        .filter((c) => c.name.toLowerCase().includes(term) || c.slug.includes(term))
        .map((c) => ({ label: c.name, hint: `Cliente · ${c.slug}`, to: `/clients/${c.id}` })),
    );
    push(
      'index applications',
      store.applications
        .filter((a) => a.name.toLowerCase().includes(term))
        .map((a) => ({ label: a.name, hint: 'Aplicación', to: `/applications/${a.id}` })),
    );
    push(
      'index plans',
      store.plans
        .filter((p) => p.name.toLowerCase().includes(term))
        .map((p) => ({ label: p.name, hint: 'Plan', to: `/plans/${p.id}/edit` })),
    );
    push(
      'index subscriptions',
      store.subscriptions
        .filter((s) => s.name.toLowerCase().includes(term))
        .map((s) => ({ label: s.name, hint: 'Contrato', to: `/subscriptions/${s.id}` })),
    );
    return out.slice(0, 8);
  }, [q, store, can]);

  return (
    <div className="cmd-panel cmd-stack">
      <SearchInput
        id="global-search"
        size="lg"
        labelText="Buscar en el control plane"
        placeholder="Instancia, cuenta, cliente, plan…"
        value={q}
        onChange={(e) => setQ(e.target.value)}
      />
      {q.trim().length >= 2 && results.length === 0 && (
        <p className="cmd-meter__caption">Sin coincidencias.</p>
      )}
      <ul>
        {results.map((r) => (
          <li key={r.to}>
            <button
              type="button"
              className="cmd-panel__link"
              onClick={() => {
                navigate(r.to);
                onGo();
              }}
            >
              <span>{r.label}</span>
              <span className="cmd-meter__caption">{r.hint}</span>
            </button>
          </li>
        ))}
      </ul>
    </div>
  );
}

function NotificationsPanel({ onGo }: { onGo: () => void }) {
  const { store } = useApp();
  const navigate = useNavigate();
  const items = store.activity.slice(0, 6);

  return (
    <div className="cmd-panel cmd-stack">
      <p className="cmd-panel__title">Actividad reciente</p>
      <ul>
        {items.map((a) => (
          <li key={a.id}>
            <button
              type="button"
              className="cmd-panel__link"
              onClick={() => {
                navigate(`/activity/${a.id}`);
                onGo();
              }}
            >
              <span>{a.label}</span>
              <span className="cmd-meter__caption">{fmtRel(a.created_at)}</span>
            </button>
          </li>
        ))}
      </ul>
    </div>
  );
}

function UserPanel() {
  const { operator, sim, signOut } = useApp();
  const navigate = useNavigate();

  return (
    <div className="cmd-panel cmd-stack">
      <p className="cmd-panel__title">{operator?.name}</p>
      <dl className="cmd-defs">
        <dt>Correo</dt>
        <dd>{operator?.email}</dd>
        <dt>Rol efectivo</dt>
        <dd>{ROLES[sim.role].label}</dd>
      </dl>
      <p className="cmd-meter__caption">{ROLES[sim.role].hint}</p>
      <button
        type="button"
        className="cmd-panel__link"
        onClick={() => {
          signOut();
          navigate('/login');
        }}
      >
        Salir
      </button>
    </div>
  );
}
