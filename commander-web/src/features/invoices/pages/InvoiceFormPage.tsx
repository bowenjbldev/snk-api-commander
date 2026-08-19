import { useEffect, useMemo, useState } from 'react';
import type { CSSProperties } from 'react';
import { Button, InlineNotification, SkeletonText } from '@carbon/react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { fmtMoney } from '@services/api/mock/commander-mock';
import type { ApiError } from '@services/api/mock/commander-mock';
import { useApp } from '@app/provider';
import { useResource } from '@shared/hooks/useResource';
import { lut, pending } from '@shared/lib/data';
import { formColumns } from '@shared/lib/grid';
import { PageHeader } from '@shared/components/PageHeader';
import { Field } from '@shared/components/FormPage';
import { ErrorState, Forbidden } from '@shared/components/States';
import {
  EMPTY_LINE,
  INVOICE_STATUS_OPTIONS,
  draftSubtotal,
  emptyInvoiceValues,
} from '../model/invoiceLines';
import type { DraftLine, InvoiceFormValues } from '../model/invoiceLines';
import { useInvoicesService } from '../services/invoicesService';

/** Los seis controles de una línea caben en una fila, como la rejilla del prototipo. */
const LINE_COLUMNS = { '--cmd-form-cols': 6 } as CSSProperties;

/**
 * Alta de factura. No usa `FormPage` porque las líneas no son campos planos: su número
 * cambia en caliente y sus errores de validación llegan indexados por posición, así que
 * el formulario necesita ver a la vez los valores y los errores que aquel guarda dentro.
 */
export function InvoiceFormPage() {
  const { can } = useApp();
  const invoicesService = useInvoicesService();
  const navigate = useNavigate();
  const [query] = useSearchParams();
  const res = useResource(() => invoicesService.listFormCatalog(), []);
  const [accounts, subscriptions, items, clients] = pending(res.data, [[], [], [], []]);
  const cliById = useMemo(() => lut(clients), [clients]);

  // El alta desde la ficha de una cuenta llega con ella elegida en la URL.
  const [values, setValues] = useState<InvoiceFormValues>(() =>
    emptyInvoiceValues(query.get('account') ?? ''),
  );
  const [lines, setLines] = useState<DraftLine[]>([{ ...EMPTY_LINE }]);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [global, setGlobal] = useState<{ title: string; body: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const [dirty, setDirty] = useState(false);

  // Una factura a medio teclear son varias líneas de trabajo: el aviso del navegador es
  // lo único que puede frenar una recarga, igual que en `FormPage`.
  useEffect(() => {
    if (!dirty) return;
    const warn = (e: BeforeUnloadEvent) => e.preventDefault();
    window.addEventListener('beforeunload', warn);
    return () => window.removeEventListener('beforeunload', warn);
  }, [dirty]);

  if (!can('create invoices')) return <Forbidden perm="create invoices" />;
  if (res.error) return <ErrorState error={res.error} onRetry={res.reload} />;
  if (res.loading) return <SkeletonText paragraph lineCount={8} />;

  const accountSubs = subscriptions.filter((s) => String(s.account_id) === values.account_id);
  const subtotal = draftSubtotal(lines);

  const set = (patch: Partial<InvoiceFormValues>) => {
    setDirty(true);
    setValues((prev) => ({ ...prev, ...patch }));
  };

  const setLine = (index: number, patch: Partial<DraftLine>) => {
    setDirty(true);
    setLines((prev) => prev.map((l, i) => (i === index ? { ...l, ...patch } : l)));
  };

  // Quitar la última línea no deja la factura sin ninguna: se vacía y se queda.
  const removeLine = (index: number) =>
    setLines((prev) => {
      const next = prev.filter((_l, i) => i !== index);
      return next.length ? next : [{ ...EMPTY_LINE }];
    });

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    if (busy) return;
    setBusy(true);
    setErrors({});
    setGlobal(null);
    try {
      const saved = await invoicesService.create(values, lines);
      setDirty(false);
      navigate(`/invoices/${saved.id}`);
    } catch (e) {
      const err = e as ApiError;
      setErrors(err.fields ?? {});
      setGlobal({
        title: `Error ${err.status}`,
        body: err.fields ? 'Revisa los campos marcados.' : err.message,
      });
    } finally {
      setBusy(false);
    }
  }

  function cancel() {
    if (dirty && !window.confirm('Hay cambios sin guardar: al salir se pierden. ¿Salir de todas formas?')) return;
    navigate('/invoices');
  }

  return (
    <>
      <PageHeader
        title="Nueva factura"
        lede="Cada línea referencia un contrato de la cuenta y, opcionalmente, una de sus cabeceras."
        crumbs={[{ label: 'Facturas', to: '/invoices' }]}
      />

      <form className="cmd-form" onSubmit={submit} noValidate>
        {global && (
          <InlineNotification
            kind="error"
            lowContrast
            hideCloseButton
            title={global.title}
            subtitle={global.body}
          />
        )}

        <fieldset className="cmd-form__section">
          <legend className="cmd-form__legend">Cabecera</legend>
          <div className="cmd-form__grid" style={{ '--cmd-form-cols': formColumns(6) } as CSSProperties}>
            <Field
              field={{
                key: 'account_id',
                label: 'Cuenta',
                type: 'select',
                options: [
                  { value: '', label: 'Elige una cuenta' },
                  ...[...accounts]
                    .sort((a, b) => a.name.localeCompare(b.name, 'es'))
                    .map((a) => ({ value: String(a.id), label: a.name })),
                ],
                helper: 'Determina qué contratos pueden facturarse',
              }}
              value={values.account_id}
              error={errors.account_id}
              onChange={(v) => set({ account_id: String(v) })}
            />
            <Field
              field={{ key: 'number', label: 'Número' }}
              value={values.number}
              error={errors.number}
              onChange={(v) => set({ number: String(v) })}
            />
            <Field
              field={{
                key: 'currency',
                label: 'Moneda',
                type: 'select',
                options: [{ value: 'EUR', label: 'EUR' }],
              }}
              value={values.currency}
              onChange={(v) => set({ currency: String(v) })}
            />
            <Field
              field={{
                key: 'status',
                label: 'Estado',
                type: 'select',
                options: INVOICE_STATUS_OPTIONS,
              }}
              value={values.status}
              onChange={(v) => set({ status: String(v) })}
            />
            <Field
              field={{ key: 'issued_at', label: 'Emisión', helper: 'AAAA-MM-DD' }}
              value={values.issued_at}
              error={errors.issued_at}
              onChange={(v) => set({ issued_at: String(v) })}
            />
            <Field
              field={{ key: 'due_at', label: 'Vencimiento', helper: 'AAAA-MM-DD' }}
              value={values.due_at}
              error={errors.due_at}
              onChange={(v) => set({ due_at: String(v) })}
            />
          </div>
        </fieldset>

        <fieldset className="cmd-form__section">
          <legend className="cmd-form__legend">Líneas</legend>
          {errors.lines && <p className="cmd-text-error">{errors.lines}</p>}

          {lines.map((line, index) => {
            const lineItems = items.filter(
              (it) => String(it.subscription_id) === line.subscription_id,
            );
            const amount = (Number(line.quantity) || 0) * (Number(line.unit_price) || 0);
            return (
              <div key={index} className="cmd-panel">
                <div className="cmd-form__grid" style={LINE_COLUMNS}>
                  <Field
                    field={{
                      key: `line_${index}_sub`,
                      label: 'Contrato',
                      type: 'select',
                      options: [
                        { value: '', label: 'Elige contrato' },
                        ...accountSubs.map((s) => ({ value: String(s.id), label: s.name })),
                      ],
                    }}
                    value={line.subscription_id}
                    onChange={(v) => {
                      const sub = subscriptions.find((s) => String(s.id) === String(v));
                      // Elegir contrato rellena la línea con lo pactado y descarta la
                      // cabecera anterior, que ya no pertenece a este contrato.
                      setLine(index, {
                        subscription_id: String(v),
                        item_id: '',
                        description: sub ? `${sub.name} — mensualidad` : line.description,
                        unit_price: sub ? String(sub.monthly_amount) : line.unit_price,
                      });
                    }}
                  />
                  <Field
                    field={{
                      key: `line_${index}_item`,
                      label: 'Cabecera',
                      type: 'select',
                      options: [
                        { value: '', label: 'Sin cabecera' },
                        ...lineItems.map((it) => ({
                          value: String(it.id),
                          label: `Cabecera ${it.id} · ${cliById.get(it.client_id)?.name ?? ''}`,
                        })),
                      ],
                    }}
                    value={line.item_id}
                    onChange={(v) => setLine(index, { item_id: String(v) })}
                  />
                  <Field
                    field={{ key: `line_${index}_desc`, label: 'Descripción' }}
                    value={line.description}
                    error={errors[`line_desc_${index}`]}
                    onChange={(v) => setLine(index, { description: String(v) })}
                  />
                  <Field
                    field={{ key: `line_${index}_qty`, label: 'Cant.' }}
                    value={line.quantity}
                    error={errors[`line_qty_${index}`]}
                    onChange={(v) => setLine(index, { quantity: String(v) })}
                  />
                  <Field
                    field={{ key: `line_${index}_price`, label: 'Precio' }}
                    value={line.unit_price}
                    error={errors[`line_price_${index}`]}
                    onChange={(v) => setLine(index, { unit_price: String(v) })}
                  />
                  <div className="cmd-page__actions">
                    <span className="cmd-mono">{fmtMoney(amount, values.currency)}</span>
                    <Button
                      kind="danger--ghost"
                      size="sm"
                      type="button"
                      onClick={() => removeLine(index)}
                    >
                      Quitar
                    </Button>
                  </div>
                </div>
              </div>
            );
          })}

          <div className="cmd-page__actions">
            <Button
              kind="tertiary"
              size="sm"
              type="button"
              onClick={() => setLines([...lines, { ...EMPTY_LINE }])}
            >
              Añadir línea
            </Button>
            <p className="cmd-page__lede">
              Subtotal calculado en el navegador: <strong>{fmtMoney(subtotal, values.currency)}</strong>
            </p>
          </div>
          <p className="cmd-page__lede">
            El total autoritativo es el que devuelve el servidor al guardar.
          </p>
        </fieldset>

        <div className="cmd-form__foot">
          <Button type="submit" disabled={busy}>
            {busy ? 'Emitiendo…' : 'Emitir factura'}
          </Button>
          <Button kind="secondary" type="button" onClick={cancel}>
            Cancelar
          </Button>
          {dirty && (
            <span className="cmd-form__dirty">Hay cambios sin guardar: al salir se pierden.</span>
          )}
        </div>
      </form>
    </>
  );
}
