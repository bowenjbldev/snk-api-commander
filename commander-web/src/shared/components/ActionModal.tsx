import { useEffect, useState } from 'react';
import {
  InlineNotification,
  Modal,
  NumberInput,
  PasswordInput,
  Select,
  SelectItem,
  TextArea,
  TextInput,
} from '@carbon/react';
import type { ApiError } from '@services/api/mock/commander-mock';

export interface ModalField {
  key: string;
  label: string;
  type?: 'text' | 'password' | 'number' | 'textarea' | 'select';
  options?: { value: string; label: string }[];
  helper?: string;
  placeholder?: string;
}

export type ModalData = Record<string, string | number>;

/**
 * Diálogo de acción breve: confirmar, pedir una razón o unas credenciales.
 * Los formularios largos o con impacto contractual viven en su propia página.
 */
export function ActionModal({
  open,
  kicker,
  title,
  body,
  danger,
  submitLabel,
  fields = [],
  initial = {},
  onClose,
  onSubmit,
}: {
  open: boolean;
  kicker?: string;
  title: string;
  body?: string;
  danger?: boolean;
  submitLabel: string;
  fields?: ModalField[];
  initial?: ModalData;
  onClose: () => void;
  onSubmit: (data: ModalData) => Promise<void>;
}) {
  const [data, setData] = useState<ModalData>(initial);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [global, setGlobal] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (open) {
      setData(initial);
      setErrors({});
      setGlobal(null);
      setBusy(false);
    }
    // `initial` es un literal nuevo en cada render del padre: la apertura es la señal.
  }, [open]);

  async function submit() {
    if (busy) return;
    setBusy(true);
    setErrors({});
    setGlobal(null);
    try {
      await onSubmit(data);
      onClose();
    } catch (e) {
      const err = e as ApiError;
      if (err.fields) setErrors(err.fields);
      else setGlobal(err.message);
    } finally {
      setBusy(false);
    }
  }

  const set = (key: string, value: string | number) => {
    setData((prev) => ({ ...prev, [key]: value }));
    setErrors((prev) => {
      if (!prev[key]) return prev;
      const next = { ...prev };
      delete next[key];
      return next;
    });
  };

  return (
    <Modal
      open={open}
      modalHeading={title}
      modalLabel={kicker}
      danger={danger}
      primaryButtonText={submitLabel}
      secondaryButtonText="Cancelar"
      primaryButtonDisabled={busy}
      // El propio Modal sabe mostrar el progreso; no hace falta cambiarle el rótulo.
      loadingStatus={busy ? 'active' : 'inactive'}
      loadingDescription="Enviando…"
      loadingIconDescription="Enviando"
      onRequestClose={onClose}
      onRequestSubmit={submit}
      onSecondarySubmit={onClose}
      size="sm"
      preventCloseOnClickOutside={busy}
    >
      {body && <p className="cmd-page__lede">{body}</p>}
      {global && (
        <InlineNotification kind="error" lowContrast hideCloseButton title="No se pudo completar" subtitle={global} />
      )}
      <div className="cmd-stack">
        {fields.map((field) => {
          const invalid = Boolean(errors[field.key]);
          const value = data[field.key] ?? '';
          if (field.type === 'select') {
            return (
              <Select
                key={field.key}
                id={`modal-${field.key}`}
                labelText={field.label}
                helperText={field.helper}
                invalid={invalid}
                invalidText={errors[field.key]}
                value={String(value)}
                onChange={(e) => set(field.key, e.target.value)}
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
                key={field.key}
                id={`modal-${field.key}`}
                labelText={field.label}
                helperText={field.helper}
                invalid={invalid}
                invalidText={errors[field.key]}
                value={String(value)}
                rows={3}
                onChange={(e) => set(field.key, e.target.value)}
              />
            );
          }
          if (field.type === 'number') {
            return (
              <NumberInput
                key={field.key}
                id={`modal-${field.key}`}
                label={field.label}
                helperText={field.helper}
                invalid={invalid}
                invalidText={errors[field.key]}
                value={Number(value)}
                onChange={(_e, { value: next }) => set(field.key, Number(next))}
              />
            );
          }
          if (field.type === 'password') {
            return (
              <PasswordInput
                key={field.key}
                id={`modal-${field.key}`}
                labelText={field.label}
                helperText={field.helper}
                placeholder={field.placeholder}
                invalid={invalid}
                invalidText={errors[field.key]}
                showPasswordLabel="Mostrar contraseña"
                hidePasswordLabel="Ocultar contraseña"
                tooltipPosition="left"
                value={String(value)}
                onChange={(e) => set(field.key, e.target.value)}
              />
            );
          }
          return (
            <TextInput
              key={field.key}
              id={`modal-${field.key}`}
              type="text"
              labelText={field.label}
              helperText={field.helper}
              placeholder={field.placeholder}
              invalid={invalid}
              invalidText={errors[field.key]}
              value={String(value)}
              onChange={(e) => set(field.key, e.target.value)}
            />
          );
        })}
      </div>
    </Modal>
  );
}
