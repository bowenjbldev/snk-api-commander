import { useMemo, useState } from 'react';
import { Button, SkeletonText } from '@carbon/react';
import { useParams } from 'react-router-dom';
import { Link } from '@shared/components/AppLink';
import { INVOICE_STATUS, fmtDateTime, fmtMoney, fmtNum } from '@services/api/mock/commander-mock';
import { useApp } from '@app/provider';
import { useResource } from '@shared/hooks/useResource';
import { lut, pending } from '@shared/lib/data';
import { PageHeader } from '@shared/components/PageHeader';
import { Defs, Panel } from '@shared/components/Detail';
import { DataView } from '@shared/components/DataView';
import { StatusTag } from '@shared/components/StatusTag';
import { ActionModal } from '@shared/components/ActionModal';
import { ErrorState, Forbidden } from '@shared/components/States';
import { useInvoicesService } from '../services/invoicesService';

export function InvoiceDetailPage() {
  const { can } = useApp();
  const invoicesService = useInvoicesService();
  const { id = '' } = useParams();
  const [dialog, setDialog] = useState(false);

  const res = useResource(() => invoicesService.getWithContext(id), [id]);
  const [invoice, accounts, subscriptions] = pending(res.data, [null, [], []]);
  const subById = useMemo(() => lut(subscriptions), [subscriptions]);

  if (!can('show invoices')) return <Forbidden perm="show invoices" />;
  if (res.error) {
    return (
      <>
        <PageHeader title="Factura" crumbs={[{ label: 'Facturas', to: '/invoices' }]} />
        <ErrorState error={res.error} onRetry={res.reload} />
      </>
    );
  }
  if (res.loading || !invoice) return <SkeletonText paragraph lineCount={6} />;

  const account = lut(accounts).get(invoice.account_id);

  return (
    <>
      <PageHeader
        title={invoice.number}
        crumbs={[{ label: 'Facturas', to: '/invoices' }, { label: invoice.number }]}
        lede={
          <>
            <StatusTag catalog={INVOICE_STATUS} value={invoice.status} /> {fmtMoney(invoice.total, invoice.currency)}
          </>
        }
        actions={
          can('update invoices') && (
            <Button kind="tertiary" onClick={() => setDialog(true)}>
              Cambiar estado y fechas
            </Button>
          )
        }
      />

      <div className="cmd-stack">
        <div className="cmd-panels">
          <Panel title="Cabecera">
            <Defs
              items={[
                { label: 'Número', value: <span className="cmd-mono">{invoice.number}</span> },
                { label: 'Cuenta', value: account ? <Link to={`/accounts/${account.id}`}>{account.name}</Link> : '—' },
                { label: 'Emisión', value: fmtDateTime(invoice.issued_at) },
                { label: 'Vencimiento', value: fmtDateTime(invoice.due_at) },
                { label: 'Moneda', value: invoice.currency },
              ]}
            />
          </Panel>
          <Panel title="Total">
            <Defs
              items={[
                { label: 'Líneas', value: fmtNum(invoice.lines.length) },
                { label: 'Total', value: fmtMoney(invoice.total, invoice.currency) },
              ]}
            />
            <p className="cmd-meter__caption">
              El total devuelto por el servidor es la cifra autoritativa. No hay cobrar, reembolsar, enviar ni descargar
              PDF: esos endpoints no existen.
            </p>
          </Panel>
        </div>

        <DataView
          label="Líneas de la factura"
          rows={invoice.lines.map((l, index) => ({ ...l, id: index }))}
          columns={[
            { key: 'description', header: 'Descripción', value: (l) => l.description },
            {
              key: 'subscription',
              header: 'Contrato',
              value: (l) => subById.get(l.subscription_id)?.name ?? '',
              render: (l) => (
                <Link to={`/subscriptions/${l.subscription_id}`}>{subById.get(l.subscription_id)?.name ?? '—'}</Link>
              ),
            },
            {
              key: 'instance',
              header: 'Instancia',
              value: (l) => l.instance_id,
              render: (l) =>
                l.instance_id ? (
                  <Link className="cmd-mono" to={`/instances/${l.instance_id}`}>
                    {l.instance_id}
                  </Link>
                ) : (
                  '—'
                ),
            },
            { key: 'quantity', header: 'Cantidad', align: 'right', value: (l) => l.quantity },
            {
              key: 'unit_price',
              header: 'Precio unitario',
              align: 'right',
              value: (l) => l.unit_price,
              render: (l) => fmtMoney(l.unit_price, invoice.currency),
            },
            {
              key: 'amount',
              header: 'Importe',
              align: 'right',
              value: (l) => l.amount,
              render: (l) => fmtMoney(l.amount, invoice.currency),
            },
          ]}
        />
      </div>

      <ActionModal
        open={dialog}
        title="Actualizar la factura"
        body="Tras crear una factura solo pueden cambiar su estado, la emisión y el vencimiento."
        submitLabel="Guardar"
        fields={[
          {
            key: 'status',
            label: 'Estado',
            type: 'select',
            options: Object.entries(INVOICE_STATUS).map(([value, meta]) => ({ value, label: meta.label })),
          },
          { key: 'issued_at', label: 'Emisión (AAAA-MM-DD)' },
          { key: 'due_at', label: 'Vencimiento (AAAA-MM-DD)' },
        ]}
        initial={{
          status: invoice.status,
          issued_at: invoice.issued_at.slice(0, 10),
          due_at: invoice.due_at.slice(0, 10),
        }}
        onClose={() => setDialog(false)}
        onSubmit={async (data) => {
          await invoicesService.updateStatus(invoice.id, String(data.status), String(data.issued_at), String(data.due_at));
          res.reload();
        }}
      />
    </>
  );
}
