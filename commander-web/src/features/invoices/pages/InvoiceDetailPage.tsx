import { useMemo, useState } from 'react';
import {
  Button,
  Callout,
  SkeletonText,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableHeader,
  TableRow,
} from '@carbon/react';
import { useParams } from 'react-router-dom';
import { Link } from '@shared/components/AppLink';
import { INVOICE_STATUS, fmtDate, fmtMoney, fmtNum, fmtRel } from '@services/api/mock/commander-mock';
import { useApp } from '@app/provider';
import { useResource } from '@shared/hooks/useResource';
import { lut, pending } from '@shared/lib/data';
import { PageHeader } from '@shared/components/PageHeader';
import { Defs } from '@shared/components/Detail';
import { ChartBlock } from '@shared/components/charts';
import { StatusTag } from '@shared/components/StatusTag';
import { ActionModal } from '@shared/components/ActionModal';
import { ErrorState, Forbidden } from '@shared/components/States';
import { INVOICE_STATUS_OPTIONS } from '../model/invoiceLines';
import { useInvoicesService } from '../services/invoicesService';

const RIGHT = { textAlign: 'right' } as const;

export function InvoiceDetailPage() {
  const { can } = useApp();
  const invoicesService = useInvoicesService();
  const { id = '' } = useParams();
  const [dialog, setDialog] = useState<'status' | 'delete' | null>(null);

  const res = useResource(() => invoicesService.getWithContext(id), [id]);
  const [invoice, accounts, subscriptions] = pending(res.data, [null, [], []]);
  const subById = useMemo(() => lut(subscriptions), [subscriptions]);

  if (!can('show invoices')) return <Forbidden perm="show invoices" />;
  // Un identificador que no está en los datos cargados no es un fallo que se arregle
  // repitiendo la petición: tiene página propia y dice por qué puede faltar.
  if (res.error?.status === 404) {
    return (
      <PageHeader
        title="No encontrado"
        kicker="404"
        crumbs={[{ label: 'Facturas', to: '/invoices' }]}
        lede={`La factura ${id} no está en los datos cargados. No se asume que se haya borrado: puede tratarse de un identificador de otra sesión.`}
      />
    );
  }
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
        kicker={`Factura · ID ${invoice.id}`}
        title={invoice.number}
        crumbs={[
          { label: 'Facturas', to: '/invoices' },
          { label: account?.name ?? '—', to: `/accounts/${invoice.account_id}` },
        ]}
        lede={`${account?.name ?? '—'} · ${fmtMoney(invoice.total, invoice.currency)}`}
        statuses={<StatusTag catalog={INVOICE_STATUS} value={invoice.status} />}
        actions={
          <>
            {can('update invoices') && (
              <Button onClick={() => setDialog('status')}>Cambiar estado</Button>
            )}
            {can('delete invoices') && invoice.status === 'draft' && (
              <Button kind="danger" onClick={() => setDialog('delete')}>
                Borrar borrador
              </Button>
            )}
          </>
        }
      />

      <div className="cmd-stack">
        {/* El aviso sube por encima de los bloques: acota qué se puede hacer aquí antes
            de que el operador busque un botón de cobrar que no existe. */}
        <Callout
          kind="info"
          lowContrast
          title="Tras crear solo cambian estado, emisión y vencimiento"
          subtitle="No existe cobrar, reembolsar, enviar ni descargar PDF hasta que haya backend. Solo una factura en borrador ofrece borrar."
        />

        <div className="cmd-panels">
          <ChartBlock
            title="Cabecera"
            help="Tras crear la factura solo cambian estado, emisión y vencimiento. El total lo fija el servidor."
          >
            <Defs
              items={[
                { label: 'Número', value: <span className="cmd-mono">{invoice.number}</span> },
                {
                  label: 'Cuenta',
                  value: account ? <Link to={`/accounts/${account.id}`}>{account.name}</Link> : '—',
                },
                {
                  label: 'Estado',
                  value: <StatusTag catalog={INVOICE_STATUS} value={invoice.status} />,
                },
                { label: 'Emisión', value: fmtDate(invoice.issued_at) },
                {
                  label: 'Vencimiento',
                  value: fmtDate(invoice.due_at),
                  hint:
                    invoice.status === 'overdue' ? `vencida ${fmtRel(invoice.due_at)}` : undefined,
                },
                { label: 'Moneda', value: <span className="cmd-mono">{invoice.currency}</span> },
                {
                  label: 'Total',
                  value: fmtMoney(invoice.total, invoice.currency),
                  hint: 'cifra autoritativa del servidor',
                },
                { label: 'Condiciones de la cuenta', value: account?.payment_terms },
              ]}
            />
          </ChartBlock>

          <ChartBlock
            title="Líneas"
            help="Cada línea referencia un contrato de la cuenta y, opcionalmente, una de sus cabeceras."
          >
            <TableContainer className="cmd-table-scroll">
              <Table size="sm" aria-label="Líneas de la factura">
                <TableHead>
                  <TableRow>
                    <TableHeader>Contrato</TableHeader>
                    <TableHeader>Cabecera</TableHeader>
                    <TableHeader>Descripción</TableHeader>
                    <TableHeader style={RIGHT}>Cantidad</TableHeader>
                    <TableHeader style={RIGHT}>Precio unitario</TableHeader>
                    <TableHeader style={RIGHT}>Importe</TableHeader>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {invoice.lines.map((line, index) => (
                    // Una línea no tiene identidad propia en la API: su posición es la clave.
                    <TableRow key={index}>
                      <TableCell>
                        {line.subscription_id ? (
                          <Link to={`/subscriptions/${line.subscription_id}`}>
                            {subById.get(line.subscription_id)?.name ?? `#${line.subscription_id}`}
                          </Link>
                        ) : (
                          <span className="cmd-dim">—</span>
                        )}
                      </TableCell>
                      <TableCell>
                        {line.instance_id ? (
                          <Link to={`/instances/${line.instance_id}`}>
                            Instancia {line.instance_id}
                          </Link>
                        ) : (
                          <span className="cmd-dim">—</span>
                        )}
                      </TableCell>
                      <TableCell>{line.description}</TableCell>
                      <TableCell style={RIGHT}>{fmtNum(line.quantity)}</TableCell>
                      <TableCell className="cmd-dim" style={RIGHT}>
                        {fmtMoney(line.unit_price, invoice.currency)}
                      </TableCell>
                      <TableCell style={RIGHT}>{fmtMoney(line.amount, invoice.currency)}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </TableContainer>
          </ChartBlock>
        </div>
      </div>

      <ActionModal
        open={dialog === 'status'}
        kicker={`Factura ${invoice.number}`}
        title="Cambiar estado, emisión o vencimiento"
        body="Es lo único editable tras crear la factura. Resaltar una vencida no altera automáticamente el servicio."
        submitLabel="Guardar"
        fields={[
          { key: 'status', label: 'Estado', type: 'select', options: INVOICE_STATUS_OPTIONS },
          { key: 'issued_at', label: 'Emisión', helper: 'AAAA-MM-DD' },
          { key: 'due_at', label: 'Vencimiento', helper: 'AAAA-MM-DD' },
        ]}
        initial={{
          status: invoice.status,
          issued_at: invoice.issued_at.slice(0, 10),
          due_at: invoice.due_at.slice(0, 10),
        }}
        onClose={() => setDialog(null)}
        onSubmit={async (data) => {
          await invoicesService.updateStatus(
            invoice.id,
            String(data.status),
            String(data.issued_at),
            String(data.due_at),
          );
          res.reload();
        }}
      />

      {/* El botón existe en el diseño y no escribe nada: sin backend, decirlo al pulsar
          es más honesto que ofrecer un borrado que se quedaría a medias. */}
      <ActionModal
        open={dialog === 'delete'}
        kicker={`Factura ${invoice.number}`}
        title="Sin escritura destructiva en el prototipo"
        body="La API permite borrar una factura draft; aquí no se simula para no perder datos de la demo."
        submitLabel="Entendido"
        onClose={() => setDialog(null)}
        onSubmit={async () => {}}
      />
    </>
  );
}
