import { useMemo, useState, type ReactNode } from 'react'
import {
  DataTableSkeleton,
  Pagination,
  Table,
  TableBatchAction,
  TableBatchActions,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableHeader,
  TableRow,
  TableSelectAll,
  TableSelectRow,
  TableToolbar,
  TableToolbarContent,
  TableToolbarSearch,
} from '@carbon/react'

export interface GridColumn<T> {
  key: string
  header: ReactNode
  render: (row: T) => ReactNode
  /** Value used for sorting; enables the sortable header when present. */
  sortValue?: (row: T) => string | number
  width?: number | string
  align?: 'left' | 'right'
}

export interface BatchAction {
  label: string
  icon?: React.ComponentType<{ size?: number }>
  onClick: () => void
}

type SortDir = 'ASC' | 'DESC' | 'NONE'

export function DataGrid<T>({
  rows,
  getRowId,
  columns,
  size = 'lg',
  selectable = false,
  searchText,
  searchPlaceholder = 'Search…',
  toolbarContent,
  batchActions,
  onRowClick,
  rowOverflow,
  initialSort,
  pageSizes = [25, 50, 100],
  loading = false,
  emptyState,
  selectedRowId,
}: {
  rows: T[]
  getRowId: (row: T) => string
  columns: GridColumn<T>[]
  size?: 'xs' | 'sm' | 'md' | 'lg' | 'xl'
  selectable?: boolean
  /** Provide to enable the toolbar search; returns the haystack for a row. */
  searchText?: (row: T) => string
  searchPlaceholder?: string
  toolbarContent?: ReactNode
  /** Receives the selected ids; render <TableBatchAction/>s or pass via helper. */
  batchActions?: (selectedIds: string[], clear: () => void) => BatchAction[]
  onRowClick?: (row: T) => void
  rowOverflow?: (row: T) => ReactNode
  initialSort?: { key: string; dir: 'ASC' | 'DESC' }
  pageSizes?: number[]
  loading?: boolean
  emptyState?: ReactNode
  /** Highlights a row as selected (e.g. open detail/drawer). */
  selectedRowId?: string | null
}) {
  const [search, setSearch] = useState('')
  const [sortKey, setSortKey] = useState<string | null>(initialSort?.key ?? null)
  const [sortDir, setSortDir] = useState<SortDir>(initialSort?.dir ?? 'NONE')
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(pageSizes[0])
  const [selected, setSelected] = useState<Set<string>>(new Set())

  const filtered = useMemo(() => {
    if (!searchText || !search.trim()) return rows
    const q = search.trim().toLowerCase()
    return rows.filter((r) => searchText(r).toLowerCase().includes(q))
  }, [rows, search, searchText])

  const sorted = useMemo(() => {
    if (!sortKey || sortDir === 'NONE') return filtered
    const col = columns.find((c) => c.key === sortKey)
    if (!col?.sortValue) return filtered
    const dir = sortDir === 'ASC' ? 1 : -1
    return [...filtered].sort((a, b) => {
      const va = col.sortValue!(a)
      const vb = col.sortValue!(b)
      if (va < vb) return -1 * dir
      if (va > vb) return 1 * dir
      return 0
    })
  }, [filtered, sortKey, sortDir, columns])

  const total = sorted.length
  const start = (page - 1) * pageSize
  const pageRows = sorted.slice(start, start + pageSize)

  const clearSelection = () => setSelected(new Set())
  const toggleRow = (id: string) =>
    setSelected((cur) => {
      const next = new Set(cur)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  const allOnPageSelected =
    pageRows.length > 0 && pageRows.every((r) => selected.has(getRowId(r)))
  const toggleAll = () =>
    setSelected((cur) => {
      const next = new Set(cur)
      if (allOnPageSelected) pageRows.forEach((r) => next.delete(getRowId(r)))
      else pageRows.forEach((r) => next.add(getRowId(r)))
      return next
    })

  const onHeaderSort = (col: GridColumn<T>) => {
    if (!col.sortValue) return
    if (sortKey !== col.key) {
      setSortKey(col.key)
      setSortDir('ASC')
    } else {
      setSortDir((d) => (d === 'ASC' ? 'DESC' : d === 'DESC' ? 'NONE' : 'ASC'))
    }
  }

  if (loading) {
    return (
      <DataTableSkeleton
        columnCount={columns.length + (selectable ? 1 : 0)}
        rowCount={pageSize > 10 ? 10 : pageSize}
        showHeader={false}
        showToolbar
      />
    )
  }

  const selectedIds = [...selected]
  const actions = batchActions?.(selectedIds, clearSelection) ?? []

  return (
    <TableContainer>
      <TableToolbar>
        {selectable && (
          <TableBatchActions
            shouldShowBatchActions={selectedIds.length > 0}
            totalSelected={selectedIds.length}
            onCancel={clearSelection}
          >
            {actions.map((a, i) => (
              <TableBatchAction key={i} renderIcon={a.icon} onClick={a.onClick}>
                {a.label}
              </TableBatchAction>
            ))}
          </TableBatchActions>
        )}
        <TableToolbarContent>
          {searchText && (
            <TableToolbarSearch
              persistent
              placeholder={searchPlaceholder}
              onChange={(e) => {
                setSearch((e as React.ChangeEvent<HTMLInputElement>)?.target?.value ?? '')
                setPage(1)
              }}
            />
          )}
          {toolbarContent}
        </TableToolbarContent>
      </TableToolbar>

      <div style={{ overflowX: 'auto' }}>
        <Table size={size} useZebraStyles={false}>
          <TableHead>
            <TableRow>
              {selectable && (
                <TableSelectAll
                  id="select-all"
                  name="select-all"
                  ariaLabel="Select all rows on this page"
                  checked={allOnPageSelected}
                  indeterminate={
                    selectedIds.length > 0 && !allOnPageSelected
                  }
                  onSelect={toggleAll}
                />
              )}
              {columns.map((col) => {
                const isSorted = sortKey === col.key && sortDir !== 'NONE'
                return (
                  <TableHeader
                    key={col.key}
                    isSortable={!!col.sortValue}
                    isSortHeader={isSorted}
                    sortDirection={isSorted ? sortDir : 'NONE'}
                    onClick={() => onHeaderSort(col)}
                    style={{
                      width: col.width,
                      textAlign: col.align === 'right' ? 'right' : undefined,
                    }}
                  >
                    {col.header}
                  </TableHeader>
                )
              })}
              {rowOverflow && <TableHeader aria-label="Row actions" />}
            </TableRow>
          </TableHead>
          <TableBody>
            {pageRows.map((row) => {
              const id = getRowId(row)
              const isSel = selected.has(id)
              const isActive = selectedRowId === id
              // Ignore clicks that originate from interactive cell content
              // (checkbox, overflow menu, links/buttons) so they don't navigate.
              const handleRowClick = (e: React.MouseEvent) => {
                if (!onRowClick) return
                const t = e.target as HTMLElement
                if (
                  t.closest(
                    '.cds--table-column-checkbox, .cds--table-column-menu, button, a, input',
                  )
                )
                  return
                onRowClick(row)
              }
              return (
                <TableRow
                  key={id}
                  isSelected={isSel}
                  onClick={handleRowClick}
                  style={{
                    cursor: onRowClick ? 'pointer' : undefined,
                    background: isActive
                      ? 'var(--cds-layer-selected)'
                      : undefined,
                  }}
                >
                  {selectable && (
                    <TableSelectRow
                      id={`select-${id}`}
                      name={`select-${id}`}
                      ariaLabel={`Select ${id}`}
                      checked={isSel}
                      onSelect={() => toggleRow(id)}
                    />
                  )}
                  {columns.map((col) => (
                    <TableCell
                      key={col.key}
                      style={{
                        textAlign: col.align === 'right' ? 'right' : undefined,
                      }}
                    >
                      {col.render(row)}
                    </TableCell>
                  ))}
                  {rowOverflow && (
                    <TableCell
                      className="cds--table-column-menu"
                      onClick={(e) => e.stopPropagation()}
                    >
                      {rowOverflow(row)}
                    </TableCell>
                  )}
                </TableRow>
              )
            })}
          </TableBody>
        </Table>
      </div>

      {total === 0 && emptyState ? (
        <div style={{ padding: 48 }}>{emptyState}</div>
      ) : (
        <Pagination
          page={page}
          pageSize={pageSize}
          pageSizes={pageSizes}
          totalItems={total}
          onChange={({ page: p, pageSize: ps }) => {
            setPage(p)
            setPageSize(ps)
          }}
          size="md"
        />
      )}
    </TableContainer>
  )
}
