import { useMemo, useState } from 'react';
import type { CSSProperties } from 'react';
import {
  Button,
  InlineNotification,
  Select,
  SelectItem,
  SkeletonText,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableHeader,
  TableRow,
  TextInput,
} from '@carbon/react';
import { useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { ENTITY_STATUS, METRICS, QUOTA_ACTION, fmtMoney, fmtNum } from '@services/api/mock/commander-mock';
import type { ApiError } from '@services/api/commanderApi';
import { useApp } from '@app/provider';
import { useResource } from '@shared/hooks/useResource';
import { lut, pending } from '@shared/lib/data';
import { formColumns } from '@shared/lib/grid';
import { asQuotaAction } from '@shared/lib/enums';
import { PageHeader } from '@shared/components/PageHeader';
import { Field } from '@shared/components/FormPage';
import { ErrorState, Forbidden } from '@shared/components/States';
import { draftLimitsFor, effectiveAction, hasNoLimit } from '../model/planLimits';
import type { PlanLimitDraft } from '../model/planLimits';
import { emptyPlanValues } from '../model/planForm';
import type { PlanFormValues } from '../model/planForm';
import { usePlansService } from '../services/plansService';

/** Un plan del catálogo se activa o se desactiva; `suspended` es de cuentas y clientes. */
const STATUS_OPTIONS = ['active', 'inactive'].map((value) => ({
  value,
  label: ENTITY_STATUS[value].label,
}));

const ACTION_OPTIONS = Object.entries(QUOTA_ACTION).map(([value, meta]) => ({
  value,
  label: meta.label,
}));

export function PlanFormPage() {
  const { can } = useApp();
  const plansService = usePlansService();
  const { id } = useParams();
  const [params] = useSearchParams();
  const navigate = useNavigate();
  const editing = Boolean(id);
  const perm = editing ? 'update plans' : 'create plans';

  const res = useResource(() => plansService.load(can), [can]);
  const [plans, applications] = pending(res.data, [[], [], []]);
  const plan = editing ? plans.find((p) => String(p.id) === id) : undefined;

  const [applicationId, setApplicationId] = useState('');
  const [values, setValues] = useState<PlanFormValues | null>(null);
  const [limits, setLimits] = useState<PlanLimitDraft[] | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [global, setGlobal] = useState<{ title: string; body: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const [dirty, setDirty] = useState(false);

  // Al crear, la aplicación puede venir dada desde la ficha de la aplicación; si no, la
  // primera del catálogo, porque es la que decide qué métricas se pueden limitar.
  const appId =
    applicationId ||
    String(plan?.application_id ?? params.get('app') ?? applications[0]?.id ?? '');
  const application = useMemo(() => lut(applications).get(Number(appId)), [applications, appId]);

  const form = values ?? emptyPlanValues(plan);
  const rows = limits ?? draftLimitsFor(application, plan);

  if (!can(perm)) return <Forbidden perm={perm} />;
  if (res.error) return <ErrorState error={res.error} onRetry={res.reload} />;
  if (res.loading) return <SkeletonText paragraph lineCount={8} />;
  if (editing && !plan) {
    return (
      <PageHeader
        kicker="404"
        title="No encontrado"
        lede="Ese plan no está en los datos cargados. No se asume que se haya borrado: puede tratarse de un identificador de otra sesión."
        crumbs={[{ label: 'Planes', to: '/plans' }]}
      />
    );
  }

  const metrics = application?.metrics ?? [];
  const siblings = plans.filter((p) => p.application_id === Number(appId) && p.id !== plan?.id);

  const setValue = (key: keyof PlanFormValues, value: string) => {
    setDirty(true);
    setValues({ ...form, [key]: value });
    // Escribir en el campo retira su 422: el mensaje era sobre lo que ya no está ahí.
    setErrors((prev) => Object.fromEntries(Object.entries(prev).filter(([k]) => k !== key)));
  };

  const setLimit = (metricKey: string, patch: Partial<PlanLimitDraft>) => {
    setDirty(true);
    setLimits(rows.map((r) => (r.metric_key === metricKey ? { ...r, ...patch } : r)));
  };

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    if (busy) return;
    setBusy(true);
    setErrors({});
    setGlobal(null);
    try {
      await plansService.save(form, rows, Number(appId), plan?.id);
      setDirty(false);
      navigate('/plans');
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
    navigate('/plans');
  }

  return (
    <>
      <PageHeader
        kicker={application?.name}
        title={editing ? 'Editar plan' : 'Nuevo plan'}
        lede="La plantilla define límites. Modificarla no cambia las instancias existentes: solo provisioning o un cambio de plan materializa los límites."
        crumbs={[{ label: 'Planes', to: '/plans' }]}
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
          <legend className="cmd-form__legend">Definición</legend>
          <div
            className="cmd-form__grid"
            style={{ '--cmd-form-cols': formColumns(5) } as CSSProperties}
          >
            <Field
              field={{
                key: 'application_id',
                label: 'Aplicación',
                type: 'select',
                options: applications.map((a) => ({ value: String(a.id), label: a.name })),
                readOnly: editing,
                helper: editing ? 'Fija tras crear' : 'Determina las métricas disponibles',
              }}
              value={appId}
              onChange={(v) => {
                setDirty(true);
                setApplicationId(String(v));
                // Otra aplicación son otras métricas: el borrador de límites no sirve.
                setLimits(null);
              }}
            />
            <Field
              field={{
                key: 'status',
                label: 'Estado',
                type: 'select',
                options: STATUS_OPTIONS,
                helper: 'Un plan en uso se desactiva, no se borra.',
              }}
              value={form.status}
              onChange={(v) => setValue('status', String(v))}
            />
            <Field
              field={{ key: 'name', label: 'Nombre' }}
              value={form.name}
              error={errors.name}
              onChange={(v) => setValue('name', String(v))}
            />
            <Field
              field={{ key: 'slug', label: 'Slug' }}
              value={form.slug}
              error={errors.slug}
              onChange={(v) => setValue('slug', String(v))}
            />
            <Field
              field={{ key: 'monthly_price', label: 'Precio mensual', helper: 'En euros, sin símbolo' }}
              value={form.monthly_price}
              error={errors.monthly_price}
              onChange={(v) => setValue('monthly_price', String(v))}
            />
            {/* La descripción es prosa: media fila la parte en renglones de cinco palabras. */}
            <div style={{ gridColumn: '1 / -1' }}>
              <Field
                field={{ key: 'description', label: 'Descripción', type: 'textarea' }}
                value={form.description}
                onChange={(v) => setValue('description', String(v))}
              />
            </div>
          </div>
        </fieldset>

        <fieldset className="cmd-form__section">
          <legend className="cmd-form__legend">Límites por métrica</legend>
          <p className="cmd-page__lede">
            Un límite vacío significa medir sin limitar. Cambiar la plantilla no altera las
            instancias existentes: los límites se materializan al aprovisionar o al cambiar de
            plan.
          </p>
          <TableContainer className="cmd-table-scroll">
            <Table size="sm" aria-label="Límites del plan">
              <TableHead>
                <TableRow>
                  <TableHeader>Métrica</TableHeader>
                  <TableHeader>Límite</TableHeader>
                  <TableHeader>Aviso %</TableHeader>
                  <TableHeader>Acción</TableHeader>
                  <TableHeader>Periodos</TableHeader>
                </TableRow>
              </TableHead>
              <TableBody>
                {rows.map((row) => {
                  const metric = METRICS[row.metric_key];
                  // Sin límite no hay nada que vigilar: aviso, acción y periodos se apagan.
                  const noLimit = hasNoLimit(row);
                  return (
                    <TableRow key={row.metric_key}>
                      <TableCell>
                        {metric?.name ?? row.metric_key}
                        <span className="cmd-cell__sub cmd-mono">{row.metric_key}</span>
                      </TableCell>
                      <TableCell>
                        <TextInput
                          id={`limit-${row.metric_key}`}
                          size="sm"
                          labelText="Límite"
                          hideLabel
                          inputMode="numeric"
                          placeholder="Sin límite"
                          helperText={metric?.unit}
                          invalid={Boolean(errors[`limit_${row.metric_key}`])}
                          invalidText={errors[`limit_${row.metric_key}`]}
                          value={String(row.limit_value ?? '')}
                          onChange={(e) =>
                            setLimit(row.metric_key, {
                              limit_value: e.target.value === '' ? null : e.target.value,
                            })
                          }
                        />
                      </TableCell>
                      <TableCell>
                        <TextInput
                          id={`warn-${row.metric_key}`}
                          size="sm"
                          labelText="Aviso %"
                          hideLabel
                          inputMode="numeric"
                          disabled={noLimit}
                          invalid={Boolean(errors[`warn_${row.metric_key}`])}
                          invalidText={errors[`warn_${row.metric_key}`]}
                          value={String(row.warning_percent ?? '')}
                          onChange={(e) =>
                            setLimit(row.metric_key, {
                              warning_percent: e.target.value === '' ? null : e.target.value,
                            })
                          }
                        />
                      </TableCell>
                      <TableCell>
                        <Select
                          id={`action-${row.metric_key}`}
                          size="sm"
                          labelText="Acción"
                          hideLabel
                          disabled={noLimit}
                          value={effectiveAction(row)}
                          onChange={(e) =>
                            setLimit(row.metric_key, { action: asQuotaAction(e.target.value) })
                          }
                        >
                          {ACTION_OPTIONS.map((o) => (
                            <SelectItem key={o.value} value={o.value} text={o.label} />
                          ))}
                        </Select>
                      </TableCell>
                      <TableCell>
                        <TextInput
                          id={`periods-${row.metric_key}`}
                          size="sm"
                          labelText="Periodos"
                          hideLabel
                          inputMode="numeric"
                          disabled={noLimit}
                          value={String(row.consecutive_periods ?? '')}
                          onChange={(e) =>
                            setLimit(row.metric_key, {
                              consecutive_periods: e.target.value === '' ? null : e.target.value,
                            })
                          }
                        />
                      </TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
          </TableContainer>
        </fieldset>

        {/* La tabla se dibuja aunque no haya hermanos: el hueco dice que este plan sería
            el primero de la aplicación, que es información, no un bloque de más. */}
        <section>
          <h2 className="cmd-panel__title">Comparativa con los demás planes de la aplicación</h2>
          <TableContainer className="cmd-table-scroll">
            <Table size="sm" aria-label="Comparativa de planes">
              <TableHead>
                <TableRow>
                  <TableHeader>Plan</TableHeader>
                  <TableHeader>Precio</TableHeader>
                  {metrics.map((m) => (
                    <TableHeader key={m.key}>{m.name}</TableHeader>
                  ))}
                </TableRow>
              </TableHead>
              <TableBody>
                {siblings.map((p) => (
                  <TableRow key={p.id}>
                    <TableCell>{p.name}</TableCell>
                    <TableCell className="cmd-dim">
                      {fmtMoney(p.monthly_price, p.currency)}
                    </TableCell>
                    {metrics.map((m) => {
                      const limit = p.limits.find((l) => l.metric_key === m.key);
                      return (
                        <TableCell key={m.key} className="cmd-dim">
                          {!limit || limit.limit_value === null
                            ? 'Sin límite'
                            : fmtNum(limit.limit_value)}
                        </TableCell>
                      );
                    })}
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </TableContainer>
        </section>

        <div className="cmd-form__foot">
          <Button type="submit" disabled={busy}>
            {busy ? 'Guardando…' : editing ? 'Guardar plantilla' : 'Crear plan'}
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
