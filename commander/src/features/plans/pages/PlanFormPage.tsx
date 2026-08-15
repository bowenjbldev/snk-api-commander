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
import { useNavigate, useParams } from 'react-router-dom';
import { ENTITY_STATUS, METRICS, QUOTA_ACTION, fmtMoney, fmtNum } from '@services/api/mock/commander-mock';
import type { ApiError } from '@services/api/commanderApi';
import type { QuotaAction } from '@services/api/types';
import { useApp } from '@app/provider';
import { useResource } from '@shared/hooks/useResource';
import { lut, pending } from '@shared/lib/data';
import { PageHeader } from '@shared/components/PageHeader';
import { Field } from '@shared/components/FormPage';
import { Panel } from '@shared/components/Detail';
import { ErrorState, Forbidden } from '@shared/components/States';
import { draftLimitsFor, effectiveAction, hasNoLimit } from '../model/planLimits';
import type { PlanLimitDraft } from '../model/planLimits';
import { emptyPlanValues } from '../model/planForm';
import type { PlanFormValues } from '../model/planForm';
import { usePlansService } from '../services/plansService';

const statusOptions = Object.entries(ENTITY_STATUS).map(([value, meta]) => ({ value, label: meta.label }));

export function PlanFormPage() {
  const { can } = useApp();
  const plansService = usePlansService();
  const { id } = useParams();
  const navigate = useNavigate();
  const editing = Boolean(id);
  const perm = editing ? 'update plans' : 'create plans';

  const res = useResource(() => plansService.listWithApplications(), []);
  const [plans, applications] = pending(res.data, [[], []]);
  const plan = editing ? plans.find((p) => String(p.id) === id) : undefined;

  const [applicationId, setApplicationId] = useState('');
  const [values, setValues] = useState<PlanFormValues | null>(null);
  const [limits, setLimits] = useState<PlanLimitDraft[] | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [globalError, setGlobalError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const appId = applicationId || String(plan?.application_id ?? applications[0]?.id ?? '');
  const application = useMemo(() => lut(applications).get(Number(appId)), [applications, appId]);

  const form = values ?? emptyPlanValues(plan);
  const rows = limits ?? draftLimitsFor(application, plan);

  if (!can(perm)) return <Forbidden perm={perm} />;
  if (res.error) return <ErrorState error={res.error} onRetry={res.reload} />;
  if (res.loading) return <SkeletonText paragraph lineCount={8} />;
  if (editing && !plan) {
    return (
      <>
        <PageHeader title="Plan" crumbs={[{ label: 'Planes', to: '/plans' }]} />
        <Callout kind="info" lowContrast title="No encontrado" subtitle="Ese plan no existe." />
      </>
    );
  }

  const siblings = plans.filter((p) => p.application_id === Number(appId) && p.id !== plan?.id);

  const setLimit = (metricKey: string, patch: Partial<PlanLimitDraft>) =>
    setLimits(rows.map((r) => (r.metric_key === metricKey ? { ...r, ...patch } : r)));

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    if (busy) return;
    setBusy(true);
    setErrors({});
    setGlobalError(null);
    try {
      const saved = await plansService.save(form, rows, Number(appId), plan?.id);
      navigate(`/applications/${saved.application_id}/plans`);
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
        title={editing ? `Editar plan · ${plan?.name}` : 'Nuevo plan'}
        lede="Modificar la plantilla no cambia instancias existentes: solo el alta o el cambio de plan materializa los límites."
        crumbs={[{ label: 'Planes', to: '/plans' }, { label: editing ? 'Editar' : 'Nuevo' }]}
      />

      <form className="cmd-stack" onSubmit={submit} noValidate>
        {globalError && <Callout kind="error" lowContrast title="No se pudo guardar" subtitle={globalError} />}

        <fieldset className="cmd-form__section">
          <legend className="cmd-form__legend">Identidad</legend>
          <div className="cmd-form__grid">
            <Field
              field={{
                key: 'application_id',
                label: 'Aplicación',
                type: 'select',
                options: applications.map((a) => ({ value: String(a.id), label: a.name })),
                readOnly: editing,
                helper: editing ? 'La aplicación queda fija tras crear el plan.' : undefined,
              }}
              value={appId}
              onChange={(v) => {
                setApplicationId(String(v));
                setLimits(null);
              }}
            />
            <Field
              field={{ key: 'name', label: 'Nombre' }}
              value={form.name}
              error={errors.name}
              onChange={(v) => setValues({ ...form, name: String(v) })}
            />
            <Field
              field={{ key: 'slug', label: 'Slug', helper: 'Minúsculas, números y guiones.' }}
              value={form.slug}
              error={errors.slug}
              onChange={(v) => setValues({ ...form, slug: String(v) })}
            />
            <Field
              field={{ key: 'monthly_price', label: 'Precio mensual', type: 'number' }}
              value={form.monthly_price}
              error={errors.monthly_price}
              onChange={(v) => setValues({ ...form, monthly_price: Number(v) })}
            />
            <Field
              field={{ key: 'status', label: 'Estado', type: 'select', options: statusOptions }}
              value={form.status}
              onChange={(v) => setValues({ ...form, status: String(v) })}
            />
            <Field
              field={{ key: 'description', label: 'Descripción', type: 'textarea' }}
              value={form.description}
              onChange={(v) => setValues({ ...form, description: String(v) })}
            />
          </div>
        </fieldset>

        <Panel title="Límites por métrica">
          <p className="cmd-meter__caption">
            Un límite vacío significa medir sin limitar; en ese caso la acción pasa a observación y no hay porcentaje de
            aviso.
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
                  const noLimit = hasNoLimit(row);
                  return (
                    <TableRow key={row.metric_key}>
                      <TableCell>
                        {metric?.name ?? row.metric_key}
                        <br />
                        <span className="cmd-meter__caption">{metric?.unit}</span>
                      </TableCell>
                      <TableCell>
                        <Field
                          field={{ key: `limit_${row.metric_key}`, label: 'Límite', type: 'number', hideLabel: true }}
                          value={row.limit_value ?? ''}
                          error={errors[`limit_${row.metric_key}`]}
                          onChange={(v) => setLimit(row.metric_key, { limit_value: v === '' ? null : Number(v) })}
                        />
                      </TableCell>
                      <TableCell>
                        <Field
                          field={{ key: `warn_${row.metric_key}`, label: 'Aviso', type: 'number', readOnly: noLimit, hideLabel: true }}
                          value={row.warning_percent ?? ''}
                          error={errors[`warn_${row.metric_key}`]}
                          onChange={(v) => setLimit(row.metric_key, { warning_percent: v === '' ? null : Number(v) })}
                        />
                      </TableCell>
                      <TableCell>
                        <Field
                          field={{
                            key: `action_${row.metric_key}`,
                            label: 'Acción',
                            type: 'select',
                            readOnly: noLimit,
                            hideLabel: true,
                            options: Object.entries(QUOTA_ACTION).map(([value, meta]) => ({ value, label: meta.label })),
                          }}
                          value={effectiveAction(row)}
                          onChange={(v) => setLimit(row.metric_key, { action: v as QuotaAction })}
                        />
                      </TableCell>
                      <TableCell>
                        <Field
                          field={{ key: `periods_${row.metric_key}`, label: 'Periodos', type: 'number', readOnly: noLimit, hideLabel: true }}
                          value={row.consecutive_periods ?? ''}
                          onChange={(v) => setLimit(row.metric_key, { consecutive_periods: v === '' ? null : Number(v) })}
                        />
                      </TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
          </TableContainer>
        </Panel>

        {siblings.length > 0 && (
          <Panel title="Comparativa con los demás planes de la aplicación">
            <TableContainer className="cmd-table-scroll">
              <Table size="sm" aria-label="Comparativa de planes">
                <TableHead>
                  <TableRow>
                    <TableHeader>Métrica</TableHeader>
                    {siblings.map((p) => (
                      <TableHeader key={p.id}>{`${p.name} · ${fmtMoney(p.monthly_price, p.currency)}`}</TableHeader>
                    ))}
                  </TableRow>
                </TableHead>
                <TableBody>
                  {rows.map((row) => (
                    <TableRow key={row.metric_key}>
                      <TableCell>{METRICS[row.metric_key]?.name ?? row.metric_key}</TableCell>
                      {siblings.map((p) => {
                        const limit = p.limits.find((l) => l.metric_key === row.metric_key);
                        return (
                          <TableCell key={p.id}>
                            {!limit ? '—' : limit.limit_value === null ? 'Sin límite' : fmtNum(limit.limit_value)}
                          </TableCell>
                        );
                      })}
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </TableContainer>
          </Panel>
        )}

        <div className="cmd-page__actions">
          <Button type="submit" disabled={busy}>
            {busy ? 'Guardando…' : editing ? 'Guardar plan' : 'Crear plan'}
          </Button>
          <Button kind="secondary" type="button" onClick={() => navigate('/plans')}>
            Cancelar
          </Button>
        </div>
      </form>
    </>
  );
}
