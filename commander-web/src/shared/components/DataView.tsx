import { useMemo } from 'react';
import type { ReactNode } from 'react';
import {
  Button,
  DataTableSkeleton,
  Dropdown,
  InlineLoading,
  OverflowMenu,
  OverflowMenuItem,
  Pagination,
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
  DismissibleTag,
} from '@carbon/react';
import type { ApiError } from '@services/api/mock/commander-mock';
import { useUrlState } from '@shared/hooks/useUrlState';
import { EmptyState, ErrorState } from './States';

export interface Column<T> {
  key: string;
  header: string;
  render?: (row: T) => ReactNode;
  /** Valor de orden. Si falta, la columna no es ordenable. */
  value?: (row: T) => string | number | null;
  align?: 'right';
}

export interface FilterDef<T> {
  id: string;
  label: string;
  options: { value: string; label: string }[];
  test: (row: T, value: string) => boolean;
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

export function DataView<T extends { id: number | string }>({
  rows,
  columns,
  search,
  filters = [],
  rowActions,
  loading,
  refreshing,
  error,
  onRetry,
  emptyTitle = 'Todavía no hay nada aquí',
  emptyBody = 'Cuando existan registros aparecerán en esta tabla.',
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
      ...Object.fromEntries(filters.map((f) => [f.id, ''])),
    }),
    [defaultSort?.key, defaultSort?.dir, filters.map((f) => f.id).join(',')],
  );

  const [state, setState] = useUrlState(defaults as Record<string, string>);

  const activeFilters = filters.filter((f) => state[f.id]);

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

  const size = Number(state.size) || 25;
  const page = Math.max(1, Number(state.page) || 1);
  const pageRows = sorted.slice((page - 1) * size, page * size);

  if (error) return <ErrorState error={error} onRetry={onRetry ?? (() => {})} />;
  if (loading) {
    return (
      <DataTableSkeleton columnCount={columns.length} rowCount={8} showHeader={false} showToolbar />
    );
  }

  const total = rows?.length ?? 0;
  const isFilteredEmpty = sorted.length === 0 && total > 0;

  return (
    <>
      <TableContainer className="cmd-table-scroll">
        <TableToolbar>
          <TableToolbarContent>
            {search && (
              <TableToolbarSearch
                persistent
                labelText="Buscar en la tabla"
                placeholder="Buscar…"
                defaultValue={state.q}
                onChange={(e) =>
                  setState({ q: (e as React.ChangeEvent<HTMLInputElement>)?.target?.value ?? '' })
                }
              />
            )}
            {filters.map((f) => (
              <div key={f.id} className="cmd-filter">
                <Dropdown
                  id={`filter-${f.id}`}
                  size="lg"
                  titleText={f.label}
                  hideLabel
                  label={f.label}
                  type="inline"
                  items={[{ value: '', label: f.label }, ...f.options]}
                  itemToString={(i) => (i ? i.label : '')}
                  selectedItem={
                    f.options.find((o) => o.value === state[f.id]) ?? { value: '', label: f.label }
                  }
                  onChange={({ selectedItem }) => setState({ [f.id]: selectedItem?.value ?? '' })}
                />
              </div>
            ))}
            {refreshing && <InlineLoading description="Actualizando…" />}
          </TableToolbarContent>
        </TableToolbar>

        {activeFilters.length > 0 && (
          <div className="cmd-page__actions" style={{ padding: '0.5rem 1rem' }}>
            {activeFilters.map((f) => (
              <DismissibleTag
                key={f.id}
                type="outline"
                // El texto va en `text`, no como hijo: DismissibleTag reserva el hueco
                // del hijo para el botón de cerrar.
                text={`${f.label}: ${f.options.find((o) => o.value === state[f.id])?.label ?? state[f.id]}`}
                title="Quitar filtro"
                onClose={(event: React.MouseEvent<HTMLButtonElement>) => {
                  event.preventDefault();
                  setState({ [f.id]: '' });
                }}
              />
            ))}
            <Button
              kind="ghost"
              size="sm"
              onClick={() => setState(Object.fromEntries(filters.map((f) => [f.id, ''])))}
            >
              Limpiar filtros
            </Button>
          </div>
        )}

        <span className="cmd-count">
          {sorted.length === total
            ? `${total} resultados`
            : `${sorted.length} de ${total} resultados`}
        </span>

        {sorted.length === 0 ? (
          <EmptyState
            title={isFilteredEmpty ? 'Ningún resultado con estos filtros' : emptyTitle}
            body={
              isFilteredEmpty
                ? 'Los filtros activos no dejan pasar ninguna fila. Quita alguno para volver a ver datos.'
                : emptyBody
            }
            action={
              isFilteredEmpty ? (
                <Button
                  kind="tertiary"
                  size="sm"
                  onClick={() =>
                    setState({ q: '', ...Object.fromEntries(filters.map((f) => [f.id, ''])) })
                  }
                >
                  Limpiar búsqueda y filtros
                </Button>
              ) : undefined
            }
          />
        ) : (
          <>
            <Table size="sm" useZebraStyles={false} aria-label={label}>
              <TableHead>
                <TableRow>
                  {columns.map((col) => {
                    const sortable = Boolean(col.value);
                    const isSorted = state.sort === col.key;
                    return (
                      <TableHeader
                        key={col.key}
                        isSortable={sortable}
                        isSortHeader={isSorted}
                        sortDirection={isSorted ? (state.dir === 'desc' ? 'DESC' : 'ASC') : 'NONE'}
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
                    {columns.map((col) => (
                      <TableCell
                        key={col.key}
                        style={col.align === 'right' ? { textAlign: 'right' } : undefined}
                      >
                        {col.render ? col.render(row) : String(col.value?.(row) ?? '—')}
                      </TableCell>
                    ))}
                    {rowActions && (
                      <TableCell style={{ inlineSize: '2.5rem' }}>
                        <RowMenu actions={rowActions(row)} />
                      </TableCell>
                    )}
                  </TableRow>
                ))}
              </TableBody>
            </Table>
            <Pagination
              page={page}
              pageSize={size}
              pageSizes={SIZES}
              totalItems={sorted.length}
              size="sm"
              backwardText="Anterior"
              forwardText="Siguiente"
              itemsPerPageText="Por página"
              itemRangeText={(min, max, totalItems) => `${min}–${max} de ${totalItems}`}
              pageRangeText={(_current, totalPages) =>
                `de ${totalPages} ${totalPages === 1 ? 'página' : 'páginas'}`
              }
              onChange={({ page: p, pageSize: s }) =>
                setState({ page: String(p), size: String(s) })
              }
            />
          </>
        )}
      </TableContainer>
    </>
  );
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
