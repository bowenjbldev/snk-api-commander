import { useState } from 'react';
import { Button, SkeletonText } from '@carbon/react';
import { useParams } from 'react-router-dom';
import { METRICS, QUOTA_ACTION, fmtDateTime, fmtNum } from '@services/api/mock/commander-mock';
import { useApp } from '@app/provider';
import { useResource } from '@shared/hooks/useResource';
import { lut, pending } from '@shared/lib/data';
import { PageHeader } from '@shared/components/PageHeader';
import { Defs, Panel } from '@shared/components/Detail';
import { StatusTag } from '@shared/components/StatusTag';
import { Meter } from '@shared/components/Meter';
import { Sparkline } from '@shared/components/charts';
import { ActionModal } from '@shared/components/ActionModal';
import { ErrorState, Forbidden } from '@shared/components/States';
import { asQuotaAction } from '@shared/lib/enums';
import { quotaPercent } from '../model/quotaHealth';
import { useQuotasService } from '../services/quotasService';

export function QuotaDetailPage() {
  const { can } = useApp();
  const quotasService = useQuotasService();
  const { id = '' } = useParams();
  const [dialog, setDialog] = useState(false);

  const res = useResource(() => quotasService.getWithSeries(id), [id]);
  const [quota, measurements, instances, clients, subscriptions, applications] = pending(res.data, [
    null, [], [], [], [], [],
  ]);

  if (!can('show quotas')) return <Forbidden perm="show quotas" />;
  if (res.error) {
    return (
      <>
        <PageHeader title="Cuota" crumbs={[{ label: 'Cuotas', to: '/quotas' }]} />
        <ErrorState error={res.error} onRetry={res.reload} />
      </>
    );
  }
  if (res.loading || !quota) return <SkeletonText paragraph lineCount={6} />;

  const metric = METRICS[quota.metric_key];
  const instance = quota.instance_id ? lut(instances).get(quota.instance_id) : undefined;
  const client = instance ? lut(clients).get(instance.client_id) : undefined;
  const subscription = quota.subscription_id ? lut(subscriptions).get(quota.subscription_id) : undefined;
  const application = lut(applications).get(quota.application_id);
  const percent = quotaPercent(quota);

  return (
    <>
      <PageHeader
        title={metric?.name ?? quota.metric_key}
        crumbs={[{ label: 'Cuotas', to: '/quotas' }, { label: metric?.name ?? quota.metric_key }]}
        lede={
          quota.scope === 'subscription'
            ? `Cuota compartida del contrato ${subscription?.name ?? ''}: agrega el consumo de todas sus cabeceras.`
            : `Cuota de la instancia de ${client?.name ?? ''} en ${application?.name ?? ''}.`
        }
        actions={
          can('update quotas') && (
            <Button kind="tertiary" onClick={() => setDialog(true)}>
              Editar cuota
            </Button>
          )
        }
      />

      <div className="cmd-panels">
        <Panel title="Definición de la métrica">
          <Defs
            items={[
              { label: 'Key', value: <span className="cmd-mono">{quota.metric_key}</span> },
              { label: 'Unidad', value: metric?.unit ?? '—' },
              { label: 'Ventana', value: metric?.window ?? '—' },
              { label: 'Tipo', value: metric?.kind ?? '—' },
              { label: 'Medidor', value: metric?.meter ?? '—' },
            ]}
          />
        </Panel>

        <Panel title="Estado actual">
          <Defs
            items={[
              { label: 'Valor', value: fmtNum(quota.current_value) },
              { label: 'Límite', value: quota.limit_value === null ? 'Sin límite' : fmtNum(quota.limit_value) },
              { label: 'Porcentaje', value: percent === null ? 'Sin porcentaje' : `${percent} %` },
              { label: 'Medición', value: fmtDateTime(quota.measured_at) },
              { label: 'Último aviso', value: quota.last_warning_at ? fmtDateTime(quota.last_warning_at) : 'Nunca' },
            ]}
          />
          <Meter value={quota.current_value} limit={quota.limit_value} />
        </Panel>

        <Panel title="Enforcement">
          <Defs
            items={[
              { label: 'Acción', value: <StatusTag catalog={QUOTA_ACTION} value={quota.action} /> },
              { label: 'Porcentaje de aviso', value: quota.warning_percent ? `${quota.warning_percent} %` : '—' },
              {
                label: 'Periodos consecutivos',
                value: `${quota.consecutive_count} de ${quota.consecutive_periods ?? '—'}`,
              },
            ]}
          />
          <p className="cmd-meter__caption">{QUOTA_ACTION[quota.action]?.help}</p>
        </Panel>

        <div className="cmd-panels__wide">
          <Panel title="Serie diaria (30 días)">
            <Sparkline points={measurements} limit={quota.limit_value} warningPercent={quota.warning_percent} />
            <p className="cmd-meter__caption">
              {measurements.length} mediciones. Máximo {fmtNum(Math.max(0, ...measurements.map((m) => m.value)))}, media{' '}
              {fmtNum(
                measurements.length === 0
                  ? 0
                  : Math.round(measurements.reduce((t, m) => t + m.value, 0) / measurements.length),
              )}
              .
            </p>
          </Panel>
        </div>
      </div>

      <ActionModal
        open={dialog}
        title="Editar cuota"
        body="La API no permite borrar cuotas. Un límite vacío significa medir sin limitar."
        submitLabel="Guardar"
        fields={[
          { key: 'limit_value', label: 'Límite', type: 'number' },
          { key: 'warning_percent', label: 'Porcentaje de aviso', type: 'number', helper: 'Entre 1 y 100.' },
          {
            key: 'action',
            label: 'Acción',
            type: 'select',
            options: Object.entries(QUOTA_ACTION).map(([value, meta]) => ({ value, label: meta.label })),
          },
          { key: 'consecutive_periods', label: 'Periodos consecutivos', type: 'number' },
        ]}
        initial={{
          limit_value: quota.limit_value ?? '',
          warning_percent: quota.warning_percent ?? '',
          action: quota.action,
          consecutive_periods: quota.consecutive_periods ?? 1,
        }}
        onClose={() => setDialog(false)}
        onSubmit={async (data) => {
          await quotasService.update(quota.id, {
            limit_value: data.limit_value === '' ? null : Number(data.limit_value),
            warning_percent: data.warning_percent === '' ? null : Number(data.warning_percent),
            action: asQuotaAction(String(data.action)),
            consecutive_periods: Number(data.consecutive_periods) || 1,
          });
          res.reload();
        }}
      />
    </>
  );
}
