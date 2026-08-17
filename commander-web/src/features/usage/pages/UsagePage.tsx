import { useMemo } from 'react';
import { Select, SelectItem, SkeletonText } from '@carbon/react';
import { fmtNum } from '@services/api/mock/commander-mock';
import { useApp } from '@app/provider';
import { useUsageService } from '../services/usageService';
import { useResource } from '@shared/hooks/useResource';
import { lut, pending, rate } from '@shared/lib/data';
import { usageSeries } from '../model/usageSeries';
import { useUrlState } from '@shared/hooks/useUrlState';
import { PageHeader } from '@shared/components/PageHeader';
import { ChartBlock, ThresholdBars, RequestsSeries } from '@shared/components/charts';
import { KpiGrid } from '@shared/components/KpiGrid';
import { ErrorState, Forbidden } from '@shared/components/States';

const DEFAULTS = { period: 'hour', account: '', application: '', instance: '' };

export function UsagePage() {
  const { can } = useApp();
  const usageService = useUsageService();
  const [state, setState] = useUrlState(DEFAULTS);

  const res = useResource(() => usageService.loadPlatform(), []);
  const [usage, instances, clients, accounts, applications] = pending(res.data, [[], [], [], [], []]);
  const instById = useMemo(() => lut(instances), [instances]);
  const cliById = useMemo(() => lut(clients), [clients]);

  // Cuenta, aplicación e instancia se aplican en el cliente tras relacionar cada instance_id.
  const filtered = useMemo(() => {
    return usage.filter((bucket) => {
      const inst = instById.get(bucket.instance_id);
      if (!inst) return false;
      if (state.account && String(inst.account_id) !== state.account) return false;
      if (state.application && String(inst.application_id) !== state.application) return false;
      if (state.instance && String(inst.id) !== state.instance) return false;
      return true;
    });
  }, [usage, instById, state.account, state.application, state.instance]);

  const view = useMemo(() => {
    const requests = filtered.reduce((t, b) => t + b.requests, 0);
    const e4xx = filtered.reduce((t, b) => t + b.e4xx, 0);
    const e5xx = filtered.reduce((t, b) => t + b.e5xx, 0);

    const byInstance = new Map<number, { requests: number; errors: number }>();
    for (const b of filtered) {
      const acc = byInstance.get(b.instance_id) ?? { requests: 0, errors: 0 };
      acc.requests += b.requests;
      acc.errors += b.e4xx + b.e5xx;
      byInstance.set(b.instance_id, acc);
    }

    const name = (instanceId: number) => {
      const inst = instById.get(instanceId);
      return inst ? (cliById.get(inst.client_id)?.name ?? `Instancia ${instanceId}`) : `Instancia ${instanceId}`;
    };

    const topTraffic = [...byInstance.entries()]
      .sort((a, b) => b[1].requests - a[1].requests)
      .slice(0, 8)
      .map(([instanceId, v]) => ({ label: name(instanceId), value: v.requests, to: `/instances/${instanceId}/usage` }));

    // Un porcentaje sin volumen engaña: solo entran instancias con tráfico suficiente.
    const topErrorRate = [...byInstance.entries()]
      .filter(([, v]) => v.requests > 200)
      .map(([instanceId, v]) => ({
        label: `${name(instanceId)} · ${fmtNum(v.requests)} requests`,
        value: Math.round(rate(v.errors, v.requests) * 10) / 10,
        to: `/instances/${instanceId}/usage`,
        tone: rate(v.errors, v.requests) > 8 ? ('crit' as const) : ('warn' as const),
      }))
      .sort((a, b) => b.value - a.value)
      .slice(0, 8);

    // Agrupar por día colapsa los 48 buckets horarios en dos puntos por serie.
    const grouped =
      state.period === 'day'
        ? filtered.map((b) => ({ ...b, bucket: `${b.bucket.slice(0, 10)}T00:00:00.000Z` }))
        : filtered;

    return {
      requests,
      e4xx,
      e5xx,
      errors: e4xx + e5xx,
      series: usageSeries(grouped),
      topTraffic,
      topErrorRate,
      instances: byInstance.size,
    };
  }, [filtered, instById, cliById, state.period]);

  if (!can('index instances')) return <Forbidden perm="index instances" />;

  return (
    <>
      <PageHeader
        title="Uso"
        lede="Serie temporal de la plataforma. Las divisiones por cero son 0 %, nunca infinito ni dato ausente."
      />

      {res.error && <ErrorState error={res.error} onRetry={res.reload} />}
      {res.loading && <SkeletonText paragraph lineCount={6} />}

      {!res.loading && !res.error && (
        <>
          <div className="cmd-form__grid" style={{ marginBlockEnd: '1.5rem' }}>
            <Select
              id="usage-period"
              labelText="Periodo"
              value={state.period}
              onChange={(e) => setState({ period: e.target.value })}
            >
              <SelectItem value="hour" text="Por hora" />
              <SelectItem value="day" text="Por día" />
            </Select>
            <Select
              id="usage-account"
              labelText="Cuenta"
              value={state.account}
              onChange={(e) => setState({ account: e.target.value })}
            >
              <SelectItem value="" text="Todas" />
              {accounts.map((a) => (
                <SelectItem key={a.id} value={String(a.id)} text={a.name} />
              ))}
            </Select>
            <Select
              id="usage-application"
              labelText="Aplicación"
              value={state.application}
              onChange={(e) => setState({ application: e.target.value })}
            >
              <SelectItem value="" text="Todas" />
              {applications.map((a) => (
                <SelectItem key={a.id} value={String(a.id)} text={a.name} />
              ))}
            </Select>
            <Select
              id="usage-instance"
              labelText="Instancia"
              value={state.instance}
              onChange={(e) => setState({ instance: e.target.value })}
            >
              <SelectItem value="" text="Todas" />
              {instances
                .filter((i) => usage.some((u) => u.instance_id === i.id))
                .map((i) => (
                  <SelectItem
                    key={i.id}
                    value={String(i.id)}
                    text={`${cliById.get(i.client_id)?.name ?? 'Cliente'} · ${i.id}`}
                  />
                ))}
            </Select>
          </div>

          <KpiGrid
            items={[
              {
                label: 'Requests',
                value: fmtNum(view.requests),
                note: `${view.instances} instancias medidas`,
              },
              { label: 'Errores', value: fmtNum(view.errors), note: '4xx y 5xx sumados' },
              {
                label: 'Tasa de error',
                value: `${(Math.round(rate(view.errors, view.requests) * 10) / 10).toString()} %`,
                note: 'errores entre requests',
              },
              { label: '4xx', value: fmtNum(view.e4xx), note: 'errores de cliente' },
              { label: '5xx', value: fmtNum(view.e5xx), note: 'errores de servidor' },
            ]}
          />

          <div className="cmd-panels">
            <div className="cmd-panels__wide">
              <ChartBlock
                title="Requests y errores en el tiempo"
                help={state.period === 'day' ? 'Buckets agregados por día.' : 'Buckets horarios sin agregar.'}
                summary={`${fmtNum(view.requests)} requests y ${fmtNum(view.errors)} errores en la ventana cargada.`}
              >
                <RequestsSeries data={view.series} />
              </ChartBlock>
            </div>

            <ChartBlock title="4xx frente a 5xx" help="Reparto entre error de cliente y error de servidor.">
              <ThresholdBars
                items={[
                  { label: '4xx (cliente)', value: view.e4xx, tone: 'warn' },
                  { label: '5xx (servidor)', value: view.e5xx, tone: 'crit' },
                ]}
                unit="errores"
              />
            </ChartBlock>

            <ChartBlock title="Instancias con más tráfico" help="Suma de requests en la ventana cargada.">
              <ThresholdBars items={view.topTraffic} unit="requests" />
            </ChartBlock>

            <div className="cmd-panels__wide">
              <ChartBlock
                title="Instancias con mayor tasa de error"
                help="Solo instancias con más de 200 requests: un porcentaje sin volumen detrás no dice nada."
              >
                {view.topErrorRate.length > 0 ? (
                  <ThresholdBars items={view.topErrorRate} unit="%" />
                ) : (
                  <p className="cmd-meter__caption">
                    Ninguna instancia supera el volumen mínimo para calcular una tasa fiable.
                  </p>
                )}
              </ChartBlock>
            </div>
          </div>
        </>
      )}
    </>
  );
}

