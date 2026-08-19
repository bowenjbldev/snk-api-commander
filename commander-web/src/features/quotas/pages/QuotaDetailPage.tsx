import { useState } from 'react';
import { Button, Callout, SkeletonText, ToastNotification } from '@carbon/react';
import { useParams } from 'react-router-dom';
import { Link } from '@shared/components/AppLink';
import { METRICS, QUOTA_ACTION, fmtDateTime, fmtNum } from '@services/api/mock/commander-mock';
import { useApp } from '@app/provider';
import { useResource } from '@shared/hooks/useResource';
import { lut, pending } from '@shared/lib/data';
import { PageHeader } from '@shared/components/PageHeader';
import { Defs } from '@shared/components/Detail';
import { ChartBlock, Sparkline } from '@shared/components/charts';
import { StatusTag } from '@shared/components/StatusTag';
import { ActionModal } from '@shared/components/ActionModal';
import { EmptyState, ErrorState, Forbidden } from '@shared/components/States';
import { asQuotaAction } from '@shared/lib/enums';
import {
  quotaHealth,
  quotaOwnerHref,
  quotaOwnerName,
  quotaPercent,
} from '../model/quotaHealth';
import { useQuotasService } from '../services/quotasService';

/** El ámbito no es un estado: dice de quién cuelga la cuota, y por eso va en tonos neutros. */
const SCOPE = {
  instance: { label: 'Instancia', tone: 'cool-gray' },
  subscription: { label: 'Contrato', tone: 'purple' },
} as const;

/** Solo se rotula lo que pide atención: una cuota holgada no lleva pastilla. */
const SITUATION = {
  over: { label: 'Excedida', tone: 'red' },
  warn: { label: 'En aviso', tone: 'magenta' },
} as const;

export function QuotaDetailPage() {
  const { can } = useApp();
  const quotasService = useQuotasService();
  const { id = '' } = useParams();
  const [dialog, setDialog] = useState(false);
  const [saved, setSaved] = useState(false);

  const res = useResource(() => quotasService.getWithSeries(id), [id]);
  const [quota, measurements, instances, clients, subscriptions] = pending(res.data, [
    null, [], [], [], [],
  ]);

  if (!can('show quotas')) return <Forbidden perm="show quotas" />;
  // Un identificador que no está en los datos cargados no es un fallo que se arregle
  // repitiendo la petición: tiene página propia y dice por qué puede faltar.
  if (res.error?.status === 404) {
    return (
      <PageHeader
        title="No encontrado"
        kicker="404"
        crumbs={[{ label: 'Cuotas', to: '/quotas' }]}
        lede={`La cuota ${id} no está en los datos cargados. No se asume que se haya borrado: puede tratarse de un identificador de otra sesión.`}
      />
    );
  }
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
  const owner = quotaOwnerName(quota, lut(instances), lut(clients), lut(subscriptions));
  const percent = quotaPercent(quota);
  const situation = quotaHealth(quota);
  // El eje de la serie llega hasta el límite aunque ningún día lo haya rozado.
  const peak = Math.max(quota.limit_value ?? 0, ...measurements.map((m) => m.value));

  return (
    <>
      <PageHeader
        kicker={`Cuota · ID ${quota.id}`}
        title={`${metric.name} · ${owner}`}
        crumbs={[{ label: 'Cuotas', to: '/quotas' }]}
        lede={
          quota.limit_value === null
            ? 'Sin límite: observación pura.'
            : `${fmtNum(quota.current_value)} de ${fmtNum(quota.limit_value)} ${metric.unit}`
        }
        statuses={
          <>
            <StatusTag catalog={QUOTA_ACTION} value={quota.action} />
            {(situation === 'over' || situation === 'warn') && (
              <StatusTag catalog={SITUATION} value={situation} />
            )}
          </>
        }
        actions={can('update quotas') && <Button onClick={() => setDialog(true)}>Editar cuota</Button>}
      />

      <div className="cmd-stack">
        {/* El aviso sube por encima de los bloques: acota qué se puede hacer aquí antes de
            que el operador busque un botón de borrar que no existe. */}
        <Callout
          kind="info"
          lowContrast
          title="La API no permite borrar cuotas"
          subtitle="Solo editar. Las cuotas de instancia nacen del plan; una cuota compartida se crea desde su contrato."
        />

        {saved && (
          <ToastNotification
            kind="success"
            lowContrast
            title="Cuota guardada"
            subtitle="Los cambios aplican a partir de la siguiente medición."
            onClose={() => setSaved(false)}
          />
        )}

        <div className="cmd-panels">
          <ChartBlock
            title="Definición de la métrica"
            help="Qué mide la métrica y cómo: su unidad, su ventana y quién la mide."
          >
            <Defs
              items={[
                { label: 'Key', value: <span className="cmd-mono">{quota.metric_key}</span> },
                { label: 'Nombre', value: metric.name },
                { label: 'Unidad', value: metric.unit },
                { label: 'Ventana', value: metric.window },
                { label: 'Tipo', value: metric.kind },
                { label: 'Medidor', value: metric.meter },
              ]}
            />
          </ChartBlock>

          <ChartBlock
            title="Configuración y estado"
            help="El límite, el aviso y la acción configurados, junto al último valor medido."
          >
            <Defs
              items={[
                {
                  label: 'Ámbito',
                  value: <StatusTag catalog={SCOPE} value={quota.scope} size="sm" />,
                },
                {
                  label: 'Propietario',
                  value: <Link to={quotaOwnerHref(quota)}>{owner}</Link>,
                },
                {
                  label: 'Valor actual',
                  value:
                    quota.current_value === null
                      ? 'Sin medición'
                      : `${fmtNum(quota.current_value)} ${metric.unit}`,
                },
                {
                  label: 'Límite',
                  value:
                    quota.limit_value === null
                      ? 'Sin límite'
                      : `${fmtNum(quota.limit_value)} ${metric.unit}`,
                  hint: quota.limit_value === null ? 'medir sin limitar' : undefined,
                },
                { label: 'Consumo', value: percent === null ? '—' : `${percent} %` },
                {
                  label: 'Porcentaje de aviso',
                  value: quota.warning_percent === null ? '—' : `${quota.warning_percent} %`,
                },
                {
                  label: 'Acción',
                  value: <StatusTag catalog={QUOTA_ACTION} value={quota.action} size="sm" />,
                  hint: QUOTA_ACTION[quota.action].help,
                },
                {
                  label: 'Periodos consecutivos',
                  value: `${quota.consecutive_count} de ${quota.consecutive_periods ?? '—'}`,
                },
                {
                  label: 'Último warning',
                  value: quota.last_warning_at ? fmtDateTime(quota.last_warning_at) : '—',
                },
                { label: 'Última medición', value: fmtDateTime(quota.measured_at) },
              ]}
            />
          </ChartBlock>

          {measurements.length > 0 ? (
            <ChartBlock
              title="Serie diaria de measurements"
              summary={
                quota.limit_value === null
                  ? `Máximo ${fmtNum(peak)} ${metric.unit}. Sin límite configurado: la cuota solo observa.`
                  : `Máximo ${fmtNum(peak)} ${metric.unit} frente a un límite de ${fmtNum(quota.limit_value)}. Las barras en ámbar superan el aviso y las rojas superan el límite.`
              }
            >
              <div className="cmd-stack">
                <Sparkline
                  points={measurements}
                  limit={quota.limit_value}
                  warningPercent={quota.warning_percent}
                />
                {/* Las barras no llevan eje: el tramo que cubren se dice con palabras. */}
                <p className="cmd-meter__caption">
                  {measurements[0].date} — {measurements[measurements.length - 1].date}
                </p>
              </div>
            </ChartBlock>
          ) : (
            <ChartBlock title="Serie diaria">
              <EmptyState
                title="Sin mediciones"
                body="Esta cuota todavía no ha registrado valores diarios."
              />
            </ChartBlock>
          )}
        </div>
      </div>

      <ActionModal
        open={dialog}
        kicker={`Cuota ${quota.id}`}
        title={`Editar ${metric.name}`}
        body="Un límite vacío significa medir sin limitar. La API no permite borrar cuotas."
        submitLabel="Guardar cuota"
        fields={[
          { key: 'limit_value', label: `Límite (${metric.unit})`, placeholder: 'Sin límite' },
          { key: 'warning_percent', label: 'Porcentaje de aviso' },
          {
            key: 'action',
            label: 'Acción',
            type: 'select',
            options: Object.entries(QUOTA_ACTION).map(([value, meta]) => ({
              value,
              label: meta.label,
            })),
            helper: QUOTA_ACTION[quota.action].help,
          },
          { key: 'consecutive_periods', label: 'Periodos consecutivos' },
        ]}
        // Campos de texto, no numéricos: vaciar el límite es la forma de dejar de limitar,
        // y un `NumberInput` no sabe representar «ninguno».
        initial={{
          limit_value: quota.limit_value === null ? '' : String(quota.limit_value),
          warning_percent: quota.warning_percent === null ? '' : String(quota.warning_percent),
          action: quota.action,
          consecutive_periods: String(quota.consecutive_periods || 1),
        }}
        onClose={() => setDialog(false)}
        onSubmit={async (data) => {
          await quotasService.update(quota.id, {
            limit_value: data.limit_value === '' ? null : Number(data.limit_value),
            warning_percent:
              data.warning_percent === '' ? null : Number(data.warning_percent),
            action: asQuotaAction(String(data.action)),
            consecutive_periods: Number(data.consecutive_periods) || 1,
          });
          setSaved(true);
          res.reload();
        }}
      />
    </>
  );
}
