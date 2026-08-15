import { useMemo, useState } from 'react';
import { Button, Callout, SkeletonText } from '@carbon/react';
import { Add, TrashCan } from '@carbon/icons-react';
import { useNavigate } from 'react-router-dom';
import { fmtMoney } from '@services/api/mock/commander-mock';
import type { ApiError } from '@services/api/commanderApi';
import { useApp } from '@app/provider';
import { useResource } from '@shared/hooks/useResource';
import { lut, pending } from '@shared/lib/data';
import { PageHeader } from '@shared/components/PageHeader';
import { Field } from '@shared/components/FormPage';
import { Panel } from '@shared/components/Detail';
import { ErrorState, Forbidden } from '@shared/components/States';
import { EMPTY_LINE, draftSubtotal, emptyInvoiceValues } from '../model/invoiceLines';
import type { DraftLine, InvoiceFormValues } from '../model/invoiceLines';
import { useInvoicesService } from '../services/invoicesService';

export function InvoiceFormPage() {
  const { can } = useApp();
  const invoicesService = useInvoicesService();
  const navigate = useNavigate();
  const res = useResource(() => invoicesService.listFormCatalog(), []);
  const [accounts, subscriptions, items, clients] = pending(res.data, [[], [], [], []]);
  const cliById = useMemo(() => lut(clients), [clients]);

  const [accountId, setAccountId] = useState('');
  const [head, setHead] = useState<InvoiceFormValues>(emptyInvoiceValues());
  const [lines, setLines] = useState<DraftLine[]>([{ ...EMPTY_LINE }]);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [globalError, setGlobalError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  if (!can('create invoices')) return <Forbidden perm="create invoices" />;
  if (res.error) return <ErrorState error={res.error} onRetry={res.reload} />;
  if (res.loading) return <SkeletonText paragraph lineCount={8} />;

  const account = accountId || String(accounts[0]?.id ?? '');
  const accountSubs = subscriptions.filter((s) => String(s.account_id) === account);
  const subtotal = draftSubtotal(lines);

  const setLine = (index: number, patch: Partial<DraftLine>) =>
    setLines(lines.map((l, i) => (i === index ? { ...l, ...patch } : l)));

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    if (busy) return;
    setBusy(true);
    setErrors({});
    setGlobalError(null);
    try {
      const saved = await invoicesService.create(head, Number(account), lines);
      navigate(`/invoices/${saved.id}`);
    } catch (e) {
      const err = e as ApiError;
      if (err.fields) setErrors(err.fields);
      else setGlobalError(err.message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      <PageHeader
        title="Nueva factura"
        lede="El navegador muestra el subtotal como ayuda; el total autoritativo es el que devuelve el servidor."
        crumbs={[{ label: 'Facturas', to: '/invoices' }, { label: 'Nueva' }]}
      />

      <form className="cmd-stack" onSubmit={submit} noValidate>
        {globalError && <Callout kind="error" lowContrast title="No se pudo emitir" subtitle={globalError} />}

        <fieldset className="cmd-form__section">
          <legend className="cmd-form__legend">Cabecera</legend>
          <div className="cmd-form__grid">
            <Field
              field={{
                key: 'account_id',
                label: 'Cuenta',
                type: 'select',
                options: accounts.map((a) => ({ value: String(a.id), label: a.name })),
              }}
              value={account}
              error={errors.account_id}
              onChange={(v) => {
                setAccountId(String(v));
                setLines([{ ...EMPTY_LINE }]);
              }}
            />
            <Field
              field={{ key: 'number', label: 'Número' }}
              value={head.number}
              error={errors.number}
              onChange={(v) => setHead({ ...head, number: String(v) })}
            />
            <Field
              field={{ key: 'currency', label: 'Moneda' }}
              value={head.currency}
              onChange={(v) => setHead({ ...head, currency: String(v) })}
            />
            <Field
              field={{ key: 'issued_at', label: 'Emisión (AAAA-MM-DD)' }}
              value={head.issued_at}
              error={errors.issued_at}
              onChange={(v) => setHead({ ...head, issued_at: String(v) })}
            />
            <Field
              field={{ key: 'due_at', label: 'Vencimiento (AAAA-MM-DD)' }}
              value={head.due_at}
              error={errors.due_at}
              onChange={(v) => setHead({ ...head, due_at: String(v) })}
            />
          </div>
        </fieldset>

        <Panel title="Líneas">
          {errors.lines && <Callout kind="error" lowContrast title="Faltan líneas" subtitle={errors.lines} />}
          <div className="cmd-stack">
            {lines.map((line, index) => {
              const lineItems = items.filter((it) => String(it.subscription_id) === String(line.subscription_id));
              return (
                <div key={index} className="cmd-form__grid">
                  <Field
                    field={{
                      key: `sub_${index}`,
                      label: 'Contrato',
                      type: 'select',
                      options: [
                        { value: '', label: 'Elige un contrato' },
                        ...accountSubs.map((s) => ({ value: String(s.id), label: s.name })),
                      ],
                    }}
                    value={String(line.subscription_id || '')}
                    onChange={(v) => {
                      const sub = subscriptions.find((s) => String(s.id) === String(v));
                      setLine(index, {
                        subscription_id: Number(v),
                        item_id: null,
                        description: sub ? `${sub.name} — mensualidad` : line.description,
                        unit_price: sub ? sub.monthly_amount : line.unit_price,
                      });
                    }}
                  />
                  <Field
                    field={{
                      key: `item_${index}`,
                      label: 'Cabecera (opcional)',
                      type: 'select',
                      options: [
                        { value: '', label: 'Toda la suscripción' },
                        ...lineItems.map((it) => ({
                          value: String(it.id),
                          label: `${cliById.get(it.client_id)?.name ?? 'Cliente'} · instancia ${it.instance_id}`,
                        })),
                      ],
                    }}
                    value={String(line.item_id ?? '')}
                    onChange={(v) => setLine(index, { item_id: v === '' ? null : Number(v) })}
                  />
                  <Field
                    field={{ key: `desc_${index}`, label: 'Descripción' }}
                    value={line.description}
                    error={errors[`line_desc_${index}`]}
                    onChange={(v) => setLine(index, { description: String(v) })}
                  />
                  <Field
                    field={{ key: `qty_${index}`, label: 'Cantidad', type: 'number' }}
                    value={line.quantity}
                    error={errors[`line_qty_${index}`]}
                    onChange={(v) => setLine(index, { quantity: Number(v) })}
                  />
                  <Field
                    field={{ key: `price_${index}`, label: 'Precio unitario', type: 'number' }}
                    value={line.unit_price}
                    error={errors[`line_price_${index}`]}
                    onChange={(v) => setLine(index, { unit_price: Number(v) })}
                  />
                  <Button
                    kind="ghost"
                    size="sm"
                    renderIcon={TrashCan}
                    disabled={lines.length === 1}
                    onClick={() => setLines(lines.filter((_l, i) => i !== index))}
                  >
                    Quitar línea
                  </Button>
                </div>
              );
            })}
            <Button kind="tertiary" size="sm" renderIcon={Add} onClick={() => setLines([...lines, { ...EMPTY_LINE }])}>
              Añadir línea
            </Button>
          </div>
        </Panel>

        <Panel title="Resumen">
          <p className="cmd-kpi__value">{fmtMoney(subtotal, head.currency)}</p>
          <p className="cmd-meter__caption">
            Subtotal calculado en el navegador sobre {lines.length} líneas. El servidor recalcula y su total manda.
          </p>
        </Panel>

        <div className="cmd-page__actions">
          <Button type="submit" disabled={busy}>
            {busy ? 'Emitiendo…' : 'Crear factura'}
          </Button>
          <Button kind="secondary" type="button" onClick={() => navigate('/invoices')}>
            Cancelar
          </Button>
        </div>
      </form>
    </>
  );
}
