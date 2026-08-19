import { useEffect, useState } from 'react';
import type { CSSProperties, ReactNode } from 'react';
import {
  Button,
  Checkbox,
  InlineNotification,
  NumberInput,
  PasswordInput,
  Select,
  SelectItem,
  TextArea,
  TextInput,
} from '@carbon/react';
import { useNavigate } from 'react-router-dom';
import type { ApiError } from '@services/api/mock/commander-mock';
import { formColumns } from '@shared/lib/grid';
import { PageHeader } from './PageHeader';
import type { Crumb } from './PageHeader';

export interface FormField {
  key: string;
  label: string;
  type?: 'text' | 'email' | 'password' | 'number' | 'textarea' | 'select' | 'checkbox';
  options?: { value: string; label: string }[];
  helper?: string;
  placeholder?: string;
  /** Campos inmutables tras el alta: se muestran, no se editan. */
  readOnly?: boolean;
  /** Dentro de una tabla la cabecera ya nombra la columna: la etiqueta solo la repite. */
  hideLabel?: boolean;
  /** Un texto largo o una casilla ocupan la fila entera; el resto media. */
  span?: 'full';
}

export interface FormSection {
  legend: string;
  /** Lo que hay que saber antes de rellenar la sección, no lo que hace cada campo. */
  help?: string;
  fields: FormField[];
}

/** Lo que un input puede devolver. Cada feature declara su propia forma sobre esto. */
export type FormValues = Record<string, string | number | boolean>;

/**
 * Formulario largo o con impacto contractual: página dedicada, nunca diálogo.
 * Valida al enviar, mapea los 422 al campo exacto y avisa antes de perder cambios.
 */
export function FormPage<T extends FormValues>({
  title,
  lede,
  crumbs,
  sections,
  initial,
  submitLabel,
  cancelTo,
  onSubmit,
  aside,
}: {
  title: string;
  lede: string;
  crumbs?: Crumb[];
  sections: FormSection[];
  initial: T;
  submitLabel: string;
  cancelTo: string;
  onSubmit: (data: T) => Promise<void>;
  aside?: ReactNode;
}) {
  const navigate = useNavigate();
  const [data, setData] = useState<T>(initial);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [global, setGlobal] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [dirty, setDirty] = useState(false);

  // El aviso del navegador es lo único que puede frenar una recarga o un cierre.
  useEffect(() => {
    if (!dirty) return;
    const warn = (e: BeforeUnloadEvent) => e.preventDefault();
    window.addEventListener('beforeunload', warn);
    return () => window.removeEventListener('beforeunload', warn);
  }, [dirty]);

  // Único punto donde se cruza del renderizador genérico de campos a los valores
  // tipados de la feature: `key` viene de una definición de sección, no del usuario.
  const set = (key: string, value: string | number | boolean) => {
    setDirty(true);
    setData((prev) => ({ ...prev, [key]: value }) as T);
    setErrors((prev) => {
      if (!prev[key]) return prev;
      const next = { ...prev };
      delete next[key];
      return next;
    });
  };

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    if (busy) return;
    setBusy(true);
    setErrors({});
    setGlobal(null);
    try {
      await onSubmit(data);
      setDirty(false);
    } catch (e) {
      const err = e as ApiError;
      if (err.fields) setErrors(err.fields);
      else setGlobal(err.message);
    } finally {
      setBusy(false);
    }
  }

  function cancel() {
    if (dirty && !window.confirm('Hay cambios sin guardar: al salir se pierden. ¿Salir de todas formas?')) return;
    navigate(cancelTo);
  }

  return (
    <>
      <PageHeader title={title} lede={lede} crumbs={crumbs} />

      <form className="cmd-form" onSubmit={submit} noValidate>
        {global && (
          <InlineNotification
            kind="error"
            lowContrast
            hideCloseButton
            title="No se pudo guardar"
            subtitle={global}
          />
        )}

        {sections.map((section) => (
          <fieldset key={section.legend} className="cmd-form__section">
            <legend className="cmd-form__legend">{section.legend}</legend>
            {section.help && <p className="cmd-page__lede">{section.help}</p>}
            <div
              className="cmd-form__grid"
              style={
                {
                  '--cmd-form-cols': formColumns(
                    section.fields.filter((f) => f.span !== 'full').length,
                  ),
                } as CSSProperties
              }
            >
              {section.fields.map((field) => (
                <div
                  key={field.key}
                  style={field.span === 'full' ? { gridColumn: '1 / -1' } : undefined}
                >
                  <Field
                    field={field}
                    value={data[field.key]}
                    error={errors[field.key]}
                    onChange={(v) => set(field.key, v)}
                  />
                </div>
              ))}
            </div>
          </fieldset>
        ))}

        {aside}

        <div className="cmd-form__foot">
          <Button type="submit" disabled={busy}>
            {busy ? 'Guardando…' : submitLabel}
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

export function Field({
  field,
  value,
  error,
  onChange,
}: {
  field: FormField;
  value: string | number | boolean | undefined;
  error?: string;
  onChange: (value: string | number | boolean) => void;
}) {
  const id = `form-${field.key}`;
  const invalid = Boolean(error);

  if (field.type === 'checkbox') {
    return (
      <Checkbox
        id={id}
        labelText={field.label}
        helperText={field.helper}
        checked={Boolean(value)}
        disabled={field.readOnly}
        onChange={(_e, { checked }) => onChange(checked)}
      />
    );
  }

  if (field.type === 'select') {
    return (
      <Select
        id={id}
        labelText={field.label}
        hideLabel={field.hideLabel}
        helperText={field.helper}
        invalid={invalid}
        invalidText={error}
        disabled={field.readOnly}
        value={String(value ?? '')}
        onChange={(e) => onChange(e.target.value)}
      >
        {(field.options ?? []).map((o) => (
          <SelectItem key={o.value} value={o.value} text={o.label} />
        ))}
      </Select>
    );
  }

  if (field.type === 'textarea') {
    return (
      <TextArea
        id={id}
        labelText={field.label}
        helperText={field.helper}
        invalid={invalid}
        invalidText={error}
        readOnly={field.readOnly}
        rows={3}
        value={String(value ?? '')}
        onChange={(e) => onChange(e.target.value)}
      />
    );
  }

  if (field.type === 'number') {
    return (
      <NumberInput
        id={id}
        label={field.label}
        hideLabel={field.hideLabel}
        helperText={field.helper}
        invalid={invalid}
        invalidText={error}
        readOnly={field.readOnly}
        value={value === '' || value === undefined ? '' : Number(value)}
        onChange={(_e, { value: next }) => onChange(next === '' ? '' : Number(next))}
      />
    );
  }

  // Una contraseña necesita poder verse: escribir doce caracteres a ciegas y no
  // poder comprobarlos es la receta para un 422 evitable.
  if (field.type === 'password') {
    return (
      <PasswordInput
        id={id}
        labelText={field.label}
        hideLabel={field.hideLabel}
        helperText={field.helper}
        placeholder={field.placeholder}
        invalid={invalid}
        invalidText={error}
        readOnly={field.readOnly}
        showPasswordLabel="Mostrar contraseña"
        hidePasswordLabel="Ocultar contraseña"
        tooltipPosition="left"
        value={String(value ?? '')}
        onChange={(e) => onChange(e.target.value)}
      />
    );
  }

  return (
    <TextInput
      id={id}
      type={field.type === 'email' ? 'email' : 'text'}
      labelText={field.label}
      hideLabel={field.hideLabel}
      helperText={field.helper}
      placeholder={field.placeholder}
      invalid={invalid}
      invalidText={error}
      readOnly={field.readOnly}
      value={String(value ?? '')}
      onChange={(e) => onChange(e.target.value)}
    />
  );
}
