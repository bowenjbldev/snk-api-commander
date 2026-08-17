import { SkeletonText } from '@carbon/react';
import { fmtNum } from '@services/api/mock/commander-mock';
import { useResource } from '@shared/hooks/useResource';
import { rate } from '@shared/lib/data';
import { ChartBlock, RequestsSeries } from '@shared/components/charts';
import { Panel } from '@shared/components/Detail';
import { ErrorState } from '@shared/components/States';
import { usageSeries } from '@features/usage/model/usageSeries';
import { useInstancesService } from '../services/instancesService';

/** Pestaña «Uso» de la ficha: carga sus propios buckets, no los del listado. */
export function InstanceUsagePanel({ instanceId }: { instanceId: string }) {
  const instancesService = useInstancesService();
  const res = useResource(() => instancesService.usage(instanceId), [instanceId]);

  if (res.error) return <ErrorState error={res.error} onRetry={res.reload} />;
  if (res.loading) return <SkeletonText paragraph lineCount={4} />;

  const buckets = res.data ?? [];
  if (buckets.length === 0) {
    return (
      <Panel title="Sin medición">
        <p className="cmd-page__lede">
          Esta instancia no tiene buckets de uso registrados. Una instancia sin tráfico medido no es una instancia con
          cero tráfico.
        </p>
      </Panel>
    );
  }

  const requests = buckets.reduce((t, b) => t + b.requests, 0);
  const errors = buckets.reduce((t, b) => t + b.e4xx + b.e5xx, 0);

  return (
    <ChartBlock
      title="Requests y errores por hora"
      help="Ventana de 48 horas. Los errores usan el eje derecho porque su escala es dos órdenes de magnitud menor."
      summary={`${fmtNum(requests)} requests y ${fmtNum(errors)} errores en la ventana (${
        (Math.round(rate(errors, requests) * 10) / 10).toString()
      } % de tasa de error).`}
    >
      <RequestsSeries data={usageSeries(buckets)} />
    </ChartBlock>
  );
}
