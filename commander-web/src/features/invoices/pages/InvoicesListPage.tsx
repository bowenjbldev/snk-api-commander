import { useMemo, useState } from 'react';
import { Button } from '@carbon/react';
import { Add } from '@carbon/icons-react';
import { useNavigate } from 'react-router-dom';
import { Link } from '@shared/components/AppLink';
import { INVOICE_STATUS, fmtDate, fmtMoney, now } from '@services/api/mock/commander-mock';
import type { Invoice } from '@services/api/types';
import { useApp } from '@app/provider';
import { useResource } from '@shared/hooks/useResource';
import { lut, pending } from '@shared/lib/data';
import { DataView } from '@shared/components/DataView';
import type { Column, FilterDef } from '@shared/components/DataView';
import { ListPage } from '@shared/components/ListPage';
import { StatusTag } from '@shared/components/StatusTag';
import { ActionModal } from '@shared/components/ActionModal';
import { INVOICE_STATUS_OPTIONS } from '../model/invoiceLines';
import { useInvoicesService } from '../services/invoicesService';

export function InvoicesListPage() {
  const { can } = useApp();
  const invoicesService = useInvoicesService();
  const navigate = useNavigate();
  const res = useResource(() => invoicesService.listWithAccounts(), []);
  const [invoices, accounts] = pending(res.data, [[], []]);
  const accById = useMemo(() => lut(accounts), [accounts]);
  // Cambiar estado se ofrece desde la fila, no solo desde la ficha: es la única
  // escritura que admite una factura ya emitida.
  const [editing, setEditing] = useState<Invoice | null>(null);

  const columns: Column<Invoice>[] = [
    {
      key: 'number',
      header: 'Número',
      value: (i) => i.number,
      render: (i) => <Link to={`/invoices/${i.id}`}>{i.number}</Link>,
    },
    {
      key: 'account',
      header: 'Cuenta',
      value: (i) => accById.get(i.account_id)?.name ?? '',
      render: (i) => (
        <Link to={`/accounts/${i.account_id}`}>{accById.get(i.account_id)?.name ?? '—'}</Link>
      ),
    },
    {
      key: 'status',
      header: 'Estado',
      value: (i) => i.status,
      render: (i) => <StatusTag catalog={INVOICE_STATUS} value={i.status} />,
    },
    {
      key: 'issued',
      header: 'Emisión',
      value: (i) => i.issued_at,
      render: (i) => <span className="cmd-dim">{fmtDate(i.issued_at)}</span>,
    },
    {
      key: 'due',
      header: 'Vencimiento',
      value: (i) => i.due_at,
      // Una vencida se resalta, pero eso no suspende el servicio de nadie por sí solo.
      render: (i) => (
        <span className={i.status === 'overdue' ? 'cmd-text-error' : 'cmd-dim'}>
          {fmtDate(i.due_at)}
        </span>
      ),
      sub: (i) => (i.status === 'overdue' ? 'requiere atención' : null),
    },
    {
      key: 'total',
      header: 'Total',
      align: 'right',
      value: (i) => i.total,
      render: (i) => fmtMoney(i.total, i.currency),
    },
    {
      key: 'currency',
      header: 'Moneda',
      render: (i) => <span className="cmd-mono">{i.currency}</span>,
    },
  ];

  const filters: FilterDef<Invoice>[] = [
    {
      id: 'account',
      label: 'Cuenta',
      options: [...accounts]
        .sort((a, b) => a.name.localeCompare(b.name))
        .map((a) => ({ value: String(a.id), label: a.name })),
      test: (i, v) => String(i.account_id) === v,
    },
    { id: 'status', label: 'Estado', options: INVOICE_STATUS_OPTIONS, test: (i, v) => i.status === v },
    {
      id: 'currency',
      label: 'Moneda',
      options: [{ value: 'EUR', label: 'EUR' }],
      test: (i, v) => i.currency === v,
    },
    {
      id: 'issued',
      label: 'Emisión',
      all: 'Cualquiera',
      options: [
        { value: '30', label: 'Últimos 30 días' },
        { value: '90', label: 'Últimos 90 días' },
      ],
      test: (i, v) => new Date(i.issued_at) >= new Date(now().getTime() - Number(v) * 86400000),
    },
  ];

  return (
    <ListPage
      perm="index invoices"
      title="Facturas"
      lede="Ledger por cuenta. No existe cobrar, reembolsar, enviar ni descargar PDF: esas capacidades no tienen backend."
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
        searchHint="Número o cuenta"
        loading={res.loading}
        refreshing={res.refreshing}
        error={res.error}
        onRetry={res.reload}
        rowActions={(i) => [
          { label: 'Ver detalle', onClick: () => navigate(`/invoices/${i.id}`) },
          ...(can('update invoices')
            ? [{ label: 'Cambiar estado', onClick: () => setEditing(i) }]
            : []),
        ]}
      />

      <ActionModal
        open={editing !== null}
        kicker={editing ? `Factura ${editing.number}` : undefined}
        title="Cambiar estado, emisión o vencimiento"
        body="Es lo único editable tras crear la factura. Resaltar una vencida no altera automáticamente el servicio."
        submitLabel="Guardar"
        fields={[
          { key: 'status', label: 'Estado', type: 'select', options: INVOICE_STATUS_OPTIONS },
          { key: 'issued_at', label: 'Emisión', helper: 'AAAA-MM-DD' },
          { key: 'due_at', label: 'Vencimiento', helper: 'AAAA-MM-DD' },
        ]}
        initial={{
          status: editing?.status ?? 'draft',
          issued_at: editing?.issued_at.slice(0, 10) ?? '',
          due_at: editing?.due_at.slice(0, 10) ?? '',
        }}
        onClose={() => setEditing(null)}
        onSubmit={async (data) => {
          await invoicesService.updateStatus(
            editing!.id,
            String(data.status),
            String(data.issued_at),
            String(data.due_at),
          );
          res.reload();
        }}
      />
    </ListPage>
  );
}
