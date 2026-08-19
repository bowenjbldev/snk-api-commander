import { useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import {
  Button,
  Checkbox,
  DataTableSkeleton,
  DismissibleTag,
  OverflowMenu,
  OverflowMenuItem,
  Pagination,
  Popover,
  PopoverContent,
  Select,
  SelectItem,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableHeader,
  TableRow,
  TableToolbar,
  TableToolbarContent,
  TableToolbarSearch,
} from '@carbon/react';
import { Filter, Settings } from '@carbon/icons-react';
import type { ApiError } from '@services/api/mock/commander-mock';
import { useUrlState } from '@shared/hooks/useUrlState';
import { EmptyState, ErrorState } from './States';

export interface Column<T> {
  key: string;
  header: string;
  render?: (row: T) => ReactNode;
  /** Valor de orden. Si falta, la columna no es ordenable. */
  value?: (row: T) => string | number | null;
  /** Línea de apoyo bajo el dato: el contexto que evita una columna más. */
  sub?: (row: T) => ReactNode;
  align?: 'right';
}

export interface FilterDef<T> {
  id: string;
  label: string;
  options: { value: string; label: string }[];
  test: (row: T, value: string) => boolean;
  /** Etiqueta de la opción vacía. Por defecto «Todos». */
  all?: string;
}

export interface RowAction {
  label: string;
  onClick: () => void;
  danger?: boolean;
}

interface Props<T> {
  rows: T[] | null;
  columns: Column<T>[];
  /** Texto plano sobre el que busca la caja de la toolbar. */
  search?: (row: T) => string;
  /** Qué se puede escribir en esa caja, con las palabras del dominio. */
  searchHint?: string;
  filters?: FilterDef<T>[];
  rowActions?: (row: T) => RowAction[];
  loading?: boolean;
  refreshing?: boolean;
  error?: ApiError | null;
  onRetry?: () => void;
  emptyTitle?: string;
  emptyBody?: string;
  /** Etiqueta accesible de la tabla; también el título del contenedor. */
  label: string;
  defaultSort?: { key: string; dir: 'asc' | 'desc' };
}

const SIZES = [10, 25, 50, 100];

/** Con más de seis columnas visibles la identidad de la fila se queda anclada. */
const STICKY_FROM = 6;

export function DataView<T extends { id: number | string }>({
  rows,
  columns,
  search,
  searchHint = 'Buscar…',
  filters = [],
  rowActions,
  loading,
  refreshing,
  error,
  onRetry,
  emptyTitle = 'Todavía no hay datos',
  emptyBody = 'Cuando existan registros aparecerán aquí.',
  label,
  defaultSort,
}: Props<T>) {
  const defaults = useMemo(
    () => ({
      q: '',
      sort: defaultSort?.key ?? '',
      dir: defaultSort?.dir ?? 'asc',
      page: '1',
      size: '25',
      hide: '',
      ...Object.fromEntries(filters.map((f) => [f.id, ''])),
    }),
    [defaultSort?.key, defaultSort?.dir, filters.map((f) => f.id).join(',')],
  );

  const [state, setState] = useUrlState(defaults as Record<string, string>);
  // Solo un desplegable de la toolbar abierto a la vez, como en el prototipo.
  const [menu, setMenu] = useState<'filters' | 'cols' | null>(null);

  const activeFilters = filters.filter((f) => state[f.id]);
  const dirty = activeFilters.length > 0 || state.q.trim().length > 0;

  const filtered = useMemo(() => {
    if (!rows) return [];
    const q = state.q.trim().toLowerCase();
    return rows.filter((row) => {
      if (q && search && !search(row).toLowerCase().includes(q)) return false;
      return activeFilters.every((f) => f.test(row, state[f.id]));
    });
  }, [rows, state.q, activeFilters.map((f) => `${f.id}=${state[f.id]}`).join('&'), search]);

  const sorted = useMemo(() => {
    const col = columns.find((c) => c.key === state.sort);
    if (!col?.value) return filtered;
    const dir = state.dir === 'desc' ? -1 : 1;
    return [...filtered].sort((a, b) => {
      const va = col.value!(a);
      const vb = col.value!(b);
      // Los nulos van siempre al final, ordene como ordene la columna.
      if (va === null || va === undefined) return vb === null || vb === undefined ? 0 : 1;
      if (vb === null || vb === undefined) return -1;
      if (typeof va === 'number' && typeof vb === 'number') return (va - vb) * dir;
      return String(va).localeCompare(String(vb), 'es') * dir;
    });
  }, [filtered, state.sort, state.dir, columns]);

  // La elección de columnas viaja en la URL como el resto del estado de la tabla.
  const hidden = state.hide.split('.').filter(Boolean);
  const shown = columns.filter((c) => !hidden.includes(c.key));
  const visible = shown.length > 0 ? shown : columns.slice(0, 1);
  const sticky = visible.length + (rowActions ? 1 : 0) > STICKY_FROM ? visible[0].key : null;

  const size = Number(state.size) || 25;
  const page = Math.max(1, Number(state.page) || 1);
  const pageRows = sorted.slice((page - 1) * size, page * size);

  const total = rows?.length ?? 0;
  const clearAll = () =>
    setState({ q: '', hide: state.hide, ...Object.fromEntries(filters.map((f) => [f.id, ''])) });

  if (error) return <ErrorState error={error} onRetry={onRetry ?? (() => {})} />;
  if (loading) {
    return (
      <DataTableSkeleton columnCount={columns.length} rowCount={8} showHeader={false} showToolbar />
    );
  }

  const chips = [
    ...(state.q.trim()
      ? [{ id: '__q', text: `Buscar: ${state.q}`, clear: () => setState({ q: '' }) }]
      : []),
    ...activeFilters.map((f) => ({
      id: f.id,
      text: `${f.label}: ${f.options.find((o) => o.value === state[f.id])?.label ?? state[f.id]}`,
      clear: () => setState({ [f.id]: '' }),
    })),
  ];

  // Toolbar, chips y paginación son hermanos de la tabla, no contenido suyo: solo la
  // tabla se desplaza en horizontal cuando no cabe, y sus controles se quedan quietos.
  return (
    <div className="cmd-table">
      <TableToolbar>
        <TableToolbarContent>
          {refreshing && <span className="cmd-toolbar__status">Refrescando…</span>}
          {search && (
            <TableToolbarSearch
              defaultExpanded={Boolean(state.q)}
              labelText="Buscar en la tabla"
              placeholder={searchHint}
              defaultValue={state.q}
              onChange={(_event, value) => setState({ q: value ?? '' })}
            />
          )}

          {filters.length > 0 && (
            <ToolbarMenu
              icon={<Filter size={16} />}
              label="Filtrar"
              title="Filtros"
              open={menu === 'filters'}
              onToggle={() => setMenu((m) => (m === 'filters' ? null : 'filters'))}
              onClose={() => setMenu(null)}
              marked={dirty}
              className="cmd-popover--filters"
            >
              {filters.map((f) => (
                <Select
                  key={f.id}
                  id={`filter-${f.id}`}
                  size="sm"
                  labelText={f.label}
                  value={state[f.id]}
                  onChange={(e) => setState({ [f.id]: e.target.value })}
                >
                  <SelectItem value="" text={f.all ?? 'Todos'} />
                  {f.options.map((o) => (
                    <SelectItem key={o.value} value={o.value} text={o.label} />
                  ))}
                </Select>
              ))}
              <Button kind="ghost" size="sm" disabled={!dirty} onClick={clearAll}>
                Limpiar filtros
              </Button>
            </ToolbarMenu>
          )}

          <ToolbarMenu
            icon={<Settings size={16} />}
            label="Personalizar columnas"
            title="Columnas visibles"
            open={menu === 'cols'}
            onToggle={() => setMenu((m) => (m === 'cols' ? null : 'cols'))}
            onClose={() => setMenu(null)}
          >
            {columns.map((col) => {
              const on = !hidden.includes(col.key);
              // Una tabla sin columnas no es una tabla: la última visible queda anclada.
              const locked = on && hidden.length === columns.length - 1;
              return (
                <Checkbox
                  key={col.key}
                  id={`col-${label}-${col.key}`}
                  labelText={col.header}
                  checked={on}
                  disabled={locked}
                  title={locked ? 'Al menos una columna debe quedar visible' : undefined}
                  onChange={() =>
                    setState({
                      hide: (on ? [...hidden, col.key] : hidden.filter((k) => k !== col.key)).join(
                        '.',
                      ),
                    })
                  }
                />
              );
            })}
            <Button
              kind="ghost"
              size="sm"
              disabled={hidden.length === 0}
              onClick={() => setState({ hide: '' })}
            >
              Mostrar todas
            </Button>
          </ToolbarMenu>
        </TableToolbarContent>
      </TableToolbar>

      {chips.length > 0 && (
        <div className="cmd-chips">
          {chips.map((chip) => (
            <DismissibleTag
              key={chip.id}
              type="gray"
              size="sm"
              // El texto va en `text`, no como hijo: DismissibleTag reserva el hueco
              // del hijo para el botón de cerrar.
              text={chip.text}
              title="Quitar filtro"
              onClose={(event: React.MouseEvent<HTMLButtonElement>) => {
                event.preventDefault();
                chip.clear();
              }}
            />
          ))}
          <Button kind="ghost" size="sm" onClick={clearAll}>
            Limpiar filtros
          </Button>
        </div>
      )}

      {sorted.length === 0 ? (
        <EmptyState
          title={dirty ? 'Ningún resultado con estos filtros' : emptyTitle}
          body={
            dirty
              ? 'La búsqueda y los filtros son locales mientras la API siga devolviendo arrays completos. Prueba a limpiarlos.'
              : emptyBody
          }
          action={
            dirty ? (
              <Button kind="tertiary" onClick={clearAll}>
                Limpiar filtros
              </Button>
            ) : undefined
          }
        />
      ) : (
        <>
          <TableContainer>
          <Table size="sm" useZebraStyles={false} aria-label={label}>
            <TableHead>
              <TableRow>
                {visible.map((col) => {
                  const sortable = Boolean(col.value);
                  const isSorted = state.sort === col.key;
                  return (
                    <TableHeader
                      key={col.key}
                      className={col.key === sticky ? 'cmd-stick' : undefined}
                      isSortable={sortable}
                      isSortHeader={isSorted}
                      sortDirection={isSorted ? (state.dir === 'desc' ? 'DESC' : 'ASC') : 'NONE'}
                      style={col.align === 'right' ? { textAlign: 'right' } : undefined}
                      onClick={
                        sortable
                          ? () =>
                              setState({
                                sort: col.key,
                                dir: isSorted && state.dir === 'asc' ? 'desc' : 'asc',
                              })
                          : undefined
                      }
                    >
                      {col.header}
                    </TableHeader>
                  );
                })}
                {rowActions && <TableHeader aria-label="Acciones de fila" />}
              </TableRow>
            </TableHead>
            <TableBody>
              {pageRows.map((row) => (
                <TableRow key={row.id}>
                  {visible.map((col) => (
                    <TableCell
                      key={col.key}
                      className={col.key === sticky ? 'cmd-stick' : undefined}
                      style={col.align === 'right' ? { textAlign: 'right' } : undefined}
                    >
                      {col.render ? col.render(row) : (col.value?.(row) ?? '—')}
                      <CellSub value={col.sub?.(row)} />
                    </TableCell>
                  ))}
                  {rowActions && (
                    <TableCell className="cds--table-column-menu">
                      <RowMenu actions={rowActions(row)} />
                    </TableCell>
                  )}
                </TableRow>
              ))}
            </TableBody>
          </Table>
          </TableContainer>
          <Pagination
            page={page}
            pageSize={size}
            pageSizes={SIZES}
            totalItems={sorted.length}
            backwardText="Página anterior"
            forwardText="Página siguiente"
            itemsPerPageText="Filas por página:"
            itemRangeText={(min, max, items) =>
              `${min}–${max} de ${items} resultados${dirty ? ` · filtrados de ${total}` : ''}`
            }
            pageRangeText={(_current, totalPages) =>
              `de ${totalPages} ${totalPages === 1 ? 'página' : 'páginas'}`
            }
            onChange={({ page: p, pageSize: s }) => setState({ page: String(p), size: String(s) })}
          />
        </>
      )}
    </div>
  );
}

/**
 * Botón de la toolbar con su panel. Carbon solo trae menús de acciones —una lista de
 * botones—, y aquí dentro van controles: los filtros son `select` y las columnas
 * casillas. El `Popover` da el flotante del sistema sin inventar el posicionamiento.
 */
function ToolbarMenu({
  icon,
  label,
  title,
  open,
  onToggle,
  onClose,
  marked,
  className,
  children,
}: {
  icon: ReactNode;
  label: string;
  title: string;
  open: boolean;
  onToggle: () => void;
  onClose: () => void;
  marked?: boolean;
  className?: string;
  children: ReactNode;
}) {
  return (
    <Popover open={open} align="bottom-end" dropShadow onRequestClose={onClose}>
      <button
        type="button"
        className="cds--toolbar-action cmd-toolbar__action"
        aria-label={label}
        title={label}
        aria-haspopup="true"
        aria-expanded={open}
        onClick={onToggle}
      >
        {icon}
        {marked && <span className="cmd-toolbar__mark" aria-hidden="true" />}
      </button>
      <PopoverContent className={`cmd-popover${className ? ` ${className}` : ''}`}>
        <div role="dialog" aria-label={title}>
          <span className="cmd-popover__title">{title}</span>
          {children}
        </div>
      </PopoverContent>
    </Popover>
  );
}

/** Una fila sin contexto no reserva sitio para él: la columna que declara `sub` no
 *  tiene por qué tenerlo en todas sus filas. */
function CellSub({ value }: { value: ReactNode }) {
  if (value === null || value === undefined || value === false || value === '') return null;
  return <span className="cmd-cell__sub">{value}</span>;
}

function RowMenu({ actions }: { actions: RowAction[] }) {
  if (actions.length === 0) return null;
  return (
    <OverflowMenu size="sm" flipped aria-label="Acciones de la fila">
      {actions.map((a) => (
        <OverflowMenuItem
          key={a.label}
          itemText={a.label}
          isDelete={a.danger}
          hasDivider={a.danger}
          onClick={a.onClick}
        />
      ))}
    </OverflowMenu>
  );
}
