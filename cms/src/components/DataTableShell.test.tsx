import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { DataTableShell, type ShellColumn } from './DataTableShell'

interface Row {
  id: string
  name: string
}
const rows: Row[] = [
  { id: 'a', name: 'Alpha' },
  { id: 'b', name: 'Beta' },
]
const columns: ShellColumn<Row>[] = [{ key: 'name', header: 'Name', render: (r) => r.name }]

describe('DataTableShell', () => {
  it('renders rows', () => {
    render(<DataTableShell rows={rows} columns={columns} getId={(r) => r.id} itemLabel="things" />)
    expect(screen.getByText('Alpha')).toBeInTheDocument()
    expect(screen.getByText('Beta')).toBeInTheDocument()
  })

  it('filters by search text', async () => {
    render(
      <DataTableShell rows={rows} columns={columns} getId={(r) => r.id} searchText={(r) => r.name} itemLabel="things" />,
    )
    await userEvent.type(screen.getByPlaceholderText('Search'), 'alph')
    expect(screen.getByText('Alpha')).toBeInTheDocument()
    expect(screen.queryByText('Beta')).not.toBeInTheDocument()
  })

  it('shows a skeleton while loading', () => {
    const { container } = render(
      <DataTableShell rows={rows} columns={columns} getId={(r) => r.id} itemLabel="things" loading />,
    )
    expect(container.querySelector('.cds--skeleton')).toBeTruthy()
    expect(screen.queryByText('Alpha')).not.toBeInTheDocument()
  })
})
