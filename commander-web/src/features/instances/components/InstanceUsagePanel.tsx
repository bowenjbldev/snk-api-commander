import {
  SkeletonText,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableHeader,
  TableRow,
} from '@carbon/react';
import { fmtDateTime, fmtNum } from '@services/api/mock/commander-mock';
import { useResource } from '@shared/hooks/useResource';
import { rate } from '@shared/lib/data';
import { ChartBlock, RequestsSeries } from '@shared/components/charts';
import { EmptyState, ErrorState } from '@shared/components/States';
import { usageSeries } from '@features/usage/model/usageSeries';
import { useInstancesService } from '../services/instancesService';

/** Las cifras se leen por la unidad, no por el primer dígito: van pegadas a la derecha. */
const RIGHT = { textAlign: 'right' } as const;

/** Un porcentaje con un decimal y coma, como el resto de la interfaz en español. */
const percent = (part: number, whole: number) =>
  `${(Math.round(rate(part, whole) * 10) / 10).toString().replace('.', ',')} %`;

/** Pestaña «Uso» de la ficha: carga sus propios buckets, no los del listado. */
export function InstanceUsagePanel({ instanceId }: { instanceId: string }) {
  const instancesService = useInstancesService();
  const res = useResource(() => instancesService.usage(instanceId), [instanceId]);

  if (res.error) return <ErrorState error={res.error} onRetry={res.reload} />;
  if (res.loading) return <SkeletonText paragraph lineCount={4} />;

  const buckets = res.data ?? [];
  if (buckets.length === 0) {
    return (
      <section className="cmd-chart">
        <h2 className="cmd-chart__title">Uso</h2>
        <EmptyState
          title="Sin mediciones de uso"
          body="Esta instancia no ha registrado tráfico en la ventana disponible, o todavía no está lista."
        />
      </section>
    );
  }

  const requests = buckets.reduce((t, b) => t + b.requests, 0);
  const errors = buckets.reduce((t, b) => t + b.e4xx + b.e5xx, 0);
  // Los doce últimos tramos, del más reciente al más antiguo: lo que acaba de pasar va arriba.
  const last = buckets.slice(-12).reverse();

  return (
    <>
      <ChartBlock
        title="Requests y errores por hora"
        summary={`${fmtNum(requests)} requests con un ${percent(
          errors,
          requests,
        )} de error. Los errores llevan su propio eje a la derecha.`}
      >
        <RequestsSeries data={usageSeries(buckets)} />
      </ChartBlock>

      <ChartBlock
        title="Los últimos doce tramos, como tabla"
        help="Una división por cero devuelve 0 %, no infinito ni dato ausente."
      >
        <TableContainer className="cmd-table-scroll">
          <Table size="sm" aria-label="Los últimos doce tramos">
            <TableHead>
              <TableRow>
                <TableHeader>Tramo</TableHeader>
                <TableHeader style={RIGHT}>Requests</TableHeader>
                <TableHeader style={RIGHT}>4xx</TableHeader>
                <TableHeader style={RIGHT}>5xx</TableHeader>
                <TableHeader style={RIGHT}>Tasa de error</TableHeader>
              </TableRow>
            </TableHead>
            <TableBody>
              {last.map((bucket) => {
                const errored = bucket.e4xx + bucket.e5xx;
                return (
                  <TableRow key={bucket.bucket}>
                    <TableCell className="cmd-dim">{fmtDateTime(bucket.bucket)}</TableCell>
                    <TableCell style={RIGHT}>{fmtNum(bucket.requests)}</TableCell>
                    <TableCell style={RIGHT}>{fmtNum(bucket.e4xx)}</TableCell>
                    <TableCell style={RIGHT}>{fmtNum(bucket.e5xx)}</TableCell>
                    <TableCell
                      className={rate(errored, bucket.requests) > 5 ? 'cmd-text-warn' : 'cmd-dim'}
                      style={RIGHT}
                    >
                      {percent(errored, bucket.requests)}
                    </TableCell>
                  </TableRow>
                );
              })}
            </TableBody>
          </Table>
        </TableContainer>
      </ChartBlock>
    </>
  );
}
