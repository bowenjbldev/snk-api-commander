import { useMemo } from 'react';
import { Button } from '@carbon/react';
import { Add } from '@carbon/icons-react';
import { useNavigate } from 'react-router-dom';
import { Link } from '@shared/components/AppLink';
import { INVOICE_STATUS, fmtDate, fmtMoney } from '@services/api/mock/commander-mock';
import type { Invoice } from '@services/api/types';
import { useApp } from '@app/provider';
import { useResource } from '@shared/hooks/useResource';
import { lut, pending } from '@shared/lib/data';
import { DataView } from '@shared/components/DataView';
import type { Column, FilterDef } from '@shared/components/DataView';
import { ListPage } from '@shared/components/ListPage';
import { StatusTag } from '@shared/components/StatusTag';
import { useInvoicesService } from '../services/invoicesService';

export function InvoicesListPage() {
  const { can } = useApp();
  const invoicesService = useInvoicesService();
  const navigate = useNavigate();
  const res = useResource(() => invoicesService.listWithAccounts(), []);
  const [invoices, accounts] = pending(res.data, [[], []]);
  const accById = useMemo(() => lut(accounts), [accounts]);

  const columns: Column<Invoice>[] = [
    {
      key: 'number',
      header: 'Número',
      value: (i) => i.number,
      render: (i) => (
        <Link className="cmd-mono" to={`/invoices/${i.id}`}>
          {i.number}
        </Link>
      ),
    },
    {
      key: 'account',
      header: 'Cuenta',
      value: (i) => accById.get(i.account_id)?.name ?? '',
      render: (i) => <Link to={`/accounts/${i.account_id}`}>{accById.get(i.account_id)?.name ?? '—'}</Link>,
    },
    { key: 'status', header: 'Estado', value: (i) => i.status, render: (i) => <StatusTag catalog={INVOICE_STATUS} value={i.status} /> },
    { key: 'issued_at', header: 'Emisión', value: (i) => i.issued_at, render: (i) => fmtDate(i.issued_at) },
    {
      key: 'due_at',
      header: 'Vencimiento',
      value: (i) => i.due_at,
      // Una vencida se resalta, pero eso no suspende nada por sí solo.
      render: (i) => <span className={i.status === 'overdue' ? 'cmd-text-error' : undefined}>{fmtDate(i.due_at)}</span>,
    },
    { key: 'total', header: 'Total', align: 'right', value: (i) => i.total, render: (i) => fmtMoney(i.total, i.currency) },
    { key: 'currency', header: 'Moneda', value: (i) => i.currency },
  ];

  const filters: FilterDef<Invoice>[] = [
    {
      id: 'account',
      label: 'Cuenta',
      options: accounts.map((a) => ({ value: String(a.id), label: a.name })),
      test: (i, v) => String(i.account_id) === v,
    },
    {
      id: 'status',
      label: 'Estado',
      options: Object.entries(INVOICE_STATUS).map(([value, meta]) => ({ value, label: meta.label })),
      test: (i, v) => i.status === v,
    },
    {
      id: 'currency',
      label: 'Moneda',
      options: [...new Set(invoices.map((i) => i.currency))].map((c) => ({ value: c, label: c })),
      test: (i, v) => i.currency === v,
    },
  ];

  return (
    <ListPage
      perm="index invoices"
      title="Facturas"
      lede="Ledger de la cuenta. Una factura vencida se resalta como atención; no altera por sí sola el servicio de ninguna instancia."
      actions={
        can('create invoices') && (
          <Button renderIcon={Add} onClick={() => navigate('/invoices/new')}>
            Nueva factura
          </Button>
        )
      }
    >
      <DataView
        label="Facturas"
        rows={invoices}
        columns={columns}
        filters={filters}
        search={(i) => `${i.number} ${accById.get(i.account_id)?.name ?? ''}`}
        defaultSort={{ key: 'issued_at', dir: 'desc' }}
        loading={res.loading}
        refreshing={res.refreshing}
        error={res.error}
        onRetry={res.reload}
        rowActions={(i) => [{ label: 'Abrir factura', onClick: () => navigate(`/invoices/${i.id}`) }]}
      />
    </ListPage>
  );
}
