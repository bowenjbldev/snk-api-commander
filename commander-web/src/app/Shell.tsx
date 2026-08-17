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
  Tag,
} from '@carbon/react';
import { Close, Code, Notification, Renew, Rocket, Search, UserAvatar } from '@carbon/icons-react';
import { Link as RouterLink, NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom';
import { Link } from '@shared/components/AppLink';
import { fmtRel, ROLES } from '@services/api/mock/commander-mock';
import { NAV } from '@app/nav';
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
  const { can, refresh } = useApp();
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
        {/* `prefix` por defecto es «IBM»; sin vaciarlo el logotipo dice «IBM Commander». */}
        <HeaderName as={RouterLink} to="/" prefix="">
          Commander
        </HeaderName>

        {/* El modo de compilación, no un valor escrito a mano: en producción dirá otra cosa,
            y por eso el icono también cambia. De solo lectura porque no acciona nada: la
            operacional promete desplegar más de lo que enseña. Tamaño `md` porque Carbon
            descarta el icono decorativo en `sm` —a propósito: en 18px choca con el borde—. */}
        <Tag
          type="green"
          size="md"
          className="cmd-env"
          renderIcon={import.meta.env.DEV ? Code : Rocket}
        >
          {import.meta.env.MODE}
        </Tag>

        <HeaderGlobalBar>
          {/* El buscador se abre en la propia barra, no en un panel lateral: los
              resultados cuelgan justo debajo del campo donde se escribe. */}
          {panel === 'search' && <GlobalSearch onGo={() => setPanel(null)} />}
          <HeaderGlobalAction
            aria-label={panel === 'search' ? 'Cerrar la búsqueda' : 'Buscar'}
            isActive={panel === 'search'}
            onClick={() => setPanel(panel === 'search' ? null : 'search')}
          >
            {panel === 'search' ? <Close size={20} /> : <Search size={20} />}
          </HeaderGlobalAction>
          <HeaderGlobalAction aria-label="Refrescar los datos" onClick={refresh}>
            <Renew size={20} />
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

        {/* Un solo panel para los dos desplegables; el ancho lo fija cada uno, porque
            una lista de eventos y una ficha de sesión no piden lo mismo. */}
        <HeaderPanel
          expanded={panel === 'notif' || panel === 'user'}
          className={`cmd-hpanel cmd-hpanel--${panel ?? 'none'}`}
          aria-label="Panel de cabecera"
        >
          {panel === 'notif' && <NotificationsPanel onGo={() => setPanel(null)} />}
          {panel === 'user' && <UserPanel onGo={() => setPanel(null)} />}
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
                      renderIcon={entry.icon}
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
        <main id="main-content" className="cmd-shell__content">
          <Outlet />
        </main>
      </div>
    </>
  );
}

/** Búsqueda global sobre el store: mismas reglas de permiso que la navegación. */
function GlobalSearch({ onGo }: { onGo: () => void }) {
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

  const enough = q.trim().length >= 2;

  return (
    <div className="cmd-search">
      <SearchInput
        id="global-search"
        size="lg"
        autoFocus
        labelText="Buscar en el control plane"
        placeholder="Instancia, cuenta, cliente, aplicación o plan"
        value={q}
        onChange={(e) => setQ(e.target.value)}
      />

      {enough && results.length > 0 && (
        <ul className="cmd-search__results">
          {results.map((r) => (
            <li key={r.to}>
              <button
                type="button"
                className="cmd-search__hit"
                onClick={() => {
                  navigate(r.to);
                  onGo();
                }}
              >
                <span>{r.label}</span>
                <span className="cmd-search__hint">{r.hint}</span>
              </button>
            </li>
          ))}
        </ul>
      )}

      {enough && results.length === 0 && (
        <p className="cmd-search__results cmd-search__empty">Sin coincidencias para «{q.trim()}».</p>
      )}
    </div>
  );
}

/** El punto de color adelanta la gravedad del evento antes de leer su texto. */
const EVENT_TONE: Record<string, string> = {
  failed: 'var(--cds-support-error)',
  provision_failed: 'var(--cds-support-error)',
  suspended: 'var(--cds-support-error)',
  invoice_overdue: 'var(--cds-support-error)',
  grace: 'var(--cds-support-warning)',
  warning: 'var(--cds-support-warning)',
  quota_warning: 'var(--cds-support-warning)',
};

function NotificationsPanel({ onGo }: { onGo: () => void }) {
  const { store } = useApp();
  const navigate = useNavigate();
  const items = store.activity.slice(0, 6);

  return (
    <div className="cmd-hpanel__body">
      <div className="cmd-hpanel__head">
        <span className="cmd-hpanel__title">Actividad reciente</span>
        <Link to="/activity" onClick={onGo}>
          Ver toda
        </Link>
      </div>

      {items.length === 0 && <p className="cmd-hpanel__empty">Ningún evento registrado todavía.</p>}

      <ul>
        {items.map((a) => (
          <li key={a.id}>
            <button
              type="button"
              className="cmd-notif"
              onClick={() => {
                navigate(a.instance_id ? `/instances/${a.instance_id}` : `/activity/${a.id}`);
                onGo();
              }}
            >
              <span className="cmd-notif__label">
                <span
                  className="cmd-notif__dot"
                  style={{ background: EVENT_TONE[a.reason] ?? EVENT_TONE[a.type] ?? 'var(--cds-support-info)' }}
                />
                {a.label}
              </span>
              <span className="cmd-notif__message">{a.message}</span>
              <span className="cmd-notif__when">{fmtRel(a.created_at)}</span>
            </button>
          </li>
        ))}
      </ul>
    </div>
  );
}

function UserPanel({ onGo }: { onGo: () => void }) {
  const { operator, role, can, signOut } = useApp();
  const navigate = useNavigate();

  const initials = (operator?.short_name ?? operator?.name ?? '?')
    .trim()
    .split(/\s+/)
    .slice(0, 2)
    .map((word) => word[0])
    .join('')
    .toUpperCase();

  const links = [
    { label: 'Preferencias', to: '/settings', perm: 'show settings' },
    { label: 'Operadores', to: '/operators', perm: 'index users' },
  ].filter((l) => can(l.perm));

  return (
    <div className="cmd-hpanel__body">
      <div className="cmd-user">
        <span className="cmd-user__avatar" aria-hidden="true">
          {initials}
        </span>
        <span className="cmd-user__id">
          <span className="cmd-user__name">{operator?.short_name ?? operator?.name}</span>
          <span className="cmd-user__mail">{operator?.email}</span>
        </span>
      </div>

      <div className="cmd-user__perms">
        <span className="cmd-nav__group">Permisos</span>
        <span>{ROLES[role].label}</span>
        <span className="cmd-meter__caption">{ROLES[role].hint}</span>
      </div>

      <div className="cmd-user__links">
        {links.map((l) => (
          <Link key={l.to} to={l.to} className="cmd-user__link" onClick={onGo}>
            {l.label}
          </Link>
        ))}
        <button
          type="button"
          className="cmd-user__link"
          onClick={() => {
            signOut();
            navigate('/login');
          }}
        >
          Cerrar la sesión
        </button>
      </div>
    </div>
  );
}
