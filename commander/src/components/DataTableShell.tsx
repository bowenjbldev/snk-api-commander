import { type ReactNode, useMemo, useState } from 'react'
import {
  Table,
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
  TableBatchActions,
  TableBatchAction,
  Pagination,
  DataTableSkeleton,
} from '@carbon/react'
import type { CarbonIconType } from '@carbon/icons-react'
import { EmptyState } from './EmptyState'

export interface ShellColumn<R> {
  key: string
  header: string
  render: (row: R) => ReactNode
  sortable?: boolean
  sortValue?: (row: R) => string | number
  align?: 'left' | 'right'
  width?: number | string
}

export interface BatchAction {
  label: string
  icon?: CarbonIconType
  onClick: (ids: (string | number)[]) => void
}

interface Props<R> {
  rows: R[]
  columns: ShellColumn<R>[]
  getId: (row: R) => string | number
  searchText?: (row: R) => string
  searchPlaceholder?: string
  filters?: ReactNode
  toolbarExtra?: ReactNode
  batchActions?: BatchAction[]
  rowActions?: (row: R) => ReactNode
  onRowClick?: (row: R) => void
  rowClassName?: (row: R) => string | undefined
  pageSizes?: number[]
  initialPageSize?: number
  initialSort?: { key: string; dir: 'ASC' | 'DESC' }
  itemLabel?: string
  empty?: ReactNode
  loading?: boolean
}

type SortDir = 'ASC' | 'DESC' | 'NONE'

export function DataTableShell<R>({
  rows,
  columns,
  getId,
  searchText,
  searchPlaceholder = 'Search',
  filters,
  toolbarExtra,
  batchActions,
  rowActions,
  onRowClick,
  rowClassName,
  pageSizes = [25, 50, 100],
  initialPageSize = 25,
  initialSort,
  itemLabel = 'items',
  empty,
  loading,
}: Props<R>) {
  const [search, setSearch] = useState('')
  const [sortKey, setSortKey] = useState<string | null>(initialSort?.key ?? null)
  const [sortDir, setSortDir] = useState<SortDir>(initialSort?.dir ?? 'NONE')
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(initialPageSize)
  const [selected, setSelected] = useState<Set<string | number>>(new Set())

  const selectable = !!batchActions?.length

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
      const av = col.sortValue!(a)
      const bv = col.sortValue!(b)
      if (av < bv) return -1 * dir
      if (av > bv) return 1 * dir
      return 0
    })
  }, [filtered, sortKey, sortDir, columns])

  const total = sorted.length
  const maxPage = Math.max(1, Math.ceil(total / pageSize))
  const safePage = Math.min(page, maxPage)
  const paged = sorted.slice((safePage - 1) * pageSize, safePage * pageSize)

  const toggleSort = (key: string) => {
    if (sortKey !== key) {
      setSortKey(key)
      setSortDir('ASC')
    } else {
      setSortDir((d) => (d === 'ASC' ? 'DESC' : d === 'DESC' ? 'NONE' : 'ASC'))
    }
  }

  const filteredIds = useMemo(() => filtered.map(getId), [filtered, getId])
  const allSelected = filteredIds.length > 0 && filteredIds.every((id) => selected.has(id))
  const someSelected = filteredIds.some((id) => selected.has(id))

  const toggleAll = () => {
    setSelected(allSelected ? new Set() : new Set(filteredIds))
  }
  const toggleRow = (id: string | number) => {
    setSelected((prev) => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }
  const clearSelection = () => setSelected(new Set())

  const colCount = columns.length + (selectable ? 1 : 0) + (rowActions ? 1 : 0)

  if (loading) {
    // Skeleton with the real headers so there's no layout jump when rows arrive.
    return (
      <DataTableSkeleton
        headers={columns.map((c) => ({ key: c.key, header: c.header }))}
        columnCount={columns.length}
        rowCount={8}
        showToolbar
        showHeader={false}
      />
    )
  }

  return (
    <TableContainer>
      <TableToolbar>
        {selectable && (
          <TableBatchActions
            shouldShowBatchActions={selected.size > 0}
            totalSelected={selected.size}
            onCancel={clearSelection}
          >
            {batchActions!.map((a) => (
              <TableBatchAction
                key={a.label}
                renderIcon={a.icon}
                onClick={() => {
                  a.onClick([...selected])
                  clearSelection()
                }}
              >
                {a.label}
              </TableBatchAction>
            ))}
          </TableBatchActions>
        )}
        <TableToolbarContent>
          {filters}
          {toolbarExtra}
          {searchText && (
            <TableToolbarSearch
              persistent
              placeholder={searchPlaceholder}
              onChange={(evt) => {
                setSearch(typeof evt === 'string' ? '' : evt.target.value)
                setPage(1)
              }}
            />
          )}
        </TableToolbarContent>
      </TableToolbar>
      <Table>
        <TableHead>
          <TableRow>
            {selectable && (
              <TableSelectAll
                id="select-all"
                name="select-all"
                aria-label="Select all rows"
                checked={allSelected}
                indeterminate={someSelected && !allSelected}
                onSelect={toggleAll}
              />
            )}
            {columns.map((c) => (
              <TableHeader
                key={c.key}
                isSortable={c.sortable}
                isSortHeader={sortKey === c.key && sortDir !== 'NONE'}
                sortDirection={sortKey === c.key ? sortDir : 'NONE'}
                onClick={c.sortable ? () => toggleSort(c.key) : undefined}
                style={{
                  width: c.width,
                  textAlign: c.align === 'right' ? 'right' : undefined,
                }}
              >
                {c.header}
              </TableHeader>
            ))}
            {rowActions && <TableHeader aria-label="Row actions" style={{ width: 48 }} />}
          </TableRow>
        </TableHead>
        <TableBody>
          {paged.length === 0 ? (
            <TableRow>
              <TableCell colSpan={colCount}>
                {empty ?? (
                  <EmptyState
                    title={`No ${itemLabel} found`}
                    description={search.trim() ? `No matches for “${search.trim()}”.` : undefined}
                    action={search.trim() ? { label: 'Clear search', onClick: () => setSearch('') } : undefined}
                  />
                )}
              </TableCell>
            </TableRow>
          ) : (
            paged.map((row) => {
              const id = getId(row)
              return (
                <TableRow
                  key={id}
                  className={rowClassName?.(row)}
                  isSelected={selected.has(id)}
                  onClick={(e) => {
                    if (!onRowClick) return
                    const el = e.target as HTMLElement
                    if (el.closest('input,button,a,label,[role="button"],.cds--overflow-menu')) return
                    onRowClick(row)
                  }}
                  style={onRowClick ? { cursor: 'pointer' } : undefined}
                >
                  {selectable && (
                    <TableSelectRow
                      id={`select-${id}`}
                      name={`select-${id}`}
                      aria-label={`Select ${id}`}
                      checked={selected.has(id)}
                      onSelect={() => toggleRow(id)}
                    />
                  )}
                  {columns.map((c) => (
                    <TableCell
                      key={c.key}
                      style={{ textAlign: c.align === 'right' ? 'right' : undefined }}
                    >
                      {c.render(row)}
                    </TableCell>
                  ))}
                  {rowActions && (
                    <TableCell className="cds--table-column-menu">{rowActions(row)}</TableCell>
                  )}
                </TableRow>
              )
            })
          )}
        </TableBody>
      </Table>
      <Pagination
        page={safePage}
        pageSize={pageSize}
        pageSizes={pageSizes}
        totalItems={total}
        size="md"
        onChange={({ page: p, pageSize: ps }) => {
          setPage(p)
          setPageSize(ps)
        }}
      />
    </TableContainer>
  )
}
