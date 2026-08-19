import { useMemo, type CSSProperties } from 'react';
import { Select, SelectItem, SkeletonText } from '@carbon/react';
import { fmtNum, now } from '@services/api/mock/commander-mock';
import { useApp } from '@app/provider';
import { useUsageService } from '../services/usageService';
import { useResource } from '@shared/hooks/useResource';
import { lut, pending, rate } from '@shared/lib/data';
import { usageSeries } from '../model/usageSeries';
import { useUrlState } from '@shared/hooks/useUrlState';
import { PageHeader } from '@shared/components/PageHeader';
import { ChartBlock, ThresholdBars, RequestsSeries } from '@shared/components/charts';
import { KpiGrid } from '@shared/components/KpiGrid';
import { EmptyState, ErrorState, Forbidden } from '@shared/components/States';

/** Sin valor en la URL manda la ventana entera: 48 horas en tramos horarios. */
const DEFAULTS = { period: '', hours: '', account: '', app: '', instance: '' };

/** Un decimal: es la precisión con la que se lee una tasa, y también la que decide el aviso. */
const round1 = (n: number) => Math.round(n * 10) / 10;

/** Volumen mínimo para que una tasa de error signifique algo. */
const MIN_REQUESTS = 200;

export function UsagePage() {
  const { can } = useApp();
  const usageService = useUsageService();
  const [state, setState] = useUrlState(DEFAULTS);

  const res = useResource(() => usageService.loadPlatform(), []);
  const [usage, instances, clients, accounts, applications] = pending(res.data, [[], [], [], [], []]);
  const instById = useMemo(() => lut(instances), [instances]);
  const cliById = useMemo(() => lut(clients), [clients]);
  const appById = useMemo(() => lut(applications), [applications]);

  const hours = Number(state.hours) || 48;

  // Cuenta, aplicación e instancia se aplican en el cliente tras relacionar cada instance_id.
  const filtered = useMemo(() => {
    // El reloj del conjunto simulado, no el del navegador: los tramos se generan contra
    // una fecha fija y medir la ventana con la hora real deja siempre la página vacía.
    const since = now().getTime() - hours * 3600000;
    return usage.filter((bucket) => {
      if (new Date(bucket.bucket).getTime() < since) return false;
      const inst = instById.get(bucket.instance_id);
      if (!inst) return false;
      if (state.account && String(inst.account_id) !== state.account) return false;
      if (state.app && String(inst.application_id) !== state.app) return false;
      if (state.instance && String(inst.id) !== state.instance) return false;
      return true;
    });
  }, [usage, instById, hours, state.account, state.app, state.instance]);

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

    // Cliente y aplicación juntos: un cliente puede ejecutar varias aplicaciones y el
    // nombre a secas no distinguiría dos barras de la misma cuenta.
    const label = (instanceId: number) => {
      const inst = instById.get(instanceId);
      if (!inst) return `Instancia ${instanceId}`;
      return [cliById.get(inst.client_id)?.name ?? `Instancia ${instanceId}`, appById.get(inst.application_id)?.name]
        .filter(Boolean)
        .join(' · ');
    };

    const measured = [...byInstance.entries()].map(([id, v]) => ({
      id,
      label: label(id),
      requests: v.requests,
      errors: v.errors,
      errorRate: rate(v.errors, v.requests),
    }));

    const topTraffic = measured
      .slice()
      .sort((a, b) => b.requests - a.requests)
      .slice(0, 8)
      .map((x) => ({ label: x.label, value: x.requests, to: `/instances/${x.id}/usage` }));

    // Un porcentaje sin volumen engaña: solo entran instancias con tráfico suficiente.
    const worstErrors = measured
      .filter((x) => x.requests > MIN_REQUESTS)
      .sort((a, b) => b.errorRate - a.errorRate)
      .slice(0, 8);

    // Agrupar por día colapsa los tramos horarios en un punto por jornada.
    const grouped =
      state.period === 'day'
        ? filtered.map((b) => ({ ...b, bucket: `${b.bucket.slice(0, 10)}T00:00:00.000Z` }))
        : filtered;

    return {
      requests,
      e4xx,
      e5xx,
      errors: e4xx + e5xx,
      errorRate: round1(rate(e4xx + e5xx, requests)),
      measured: measured.length,
      series: usageSeries(grouped),
      topTraffic,
      worstErrors: worstErrors.map((x) => ({
        label: x.label,
        value: round1(x.errorRate),
        to: `/instances/${x.id}/usage`,
        tone: x.errorRate > 8 ? ('crit' as const) : ('warn' as const),
      })),
      // El volumen va al lado de la tasa en el texto, no en la etiqueta de la barra.
      errorsSummary: worstErrors
        .slice(0, 3)
        .map(
          (x) => `${x.label}: ${fmtNum(round1(x.errorRate))} % de ${fmtNum(x.requests)} requests`,
        )
        .join(' · '),
      // Solo las instancias que han servido tráfico bajo los filtros vigentes.
      instanceOptions: instances
        .filter((i) => byInstance.has(i.id))
        .map((i) => ({ id: i.id, label: label(i.id) })),
    };
  }, [filtered, instances, instById, cliById, appById, state.period]);

  if (!can('index instances')) return <Forbidden perm="index instances" />;

  return (
    <>
      <PageHeader
        title="Uso global"
        lede="Serie temporal por instancia desde GET /usage/platform. El conjunto simulado cubre 48 horas de tramos horarios."
      />

      {res.error && <ErrorState error={res.error} onRetry={res.reload} />}
      {res.loading && <SkeletonText paragraph lineCount={6} />}

      {!res.loading && !res.error && (
        <>
          <div className="cmd-panel" style={{ marginBlockEnd: '2rem' }}>
            <div className="cmd-form__grid" style={{ '--cmd-form-cols': 5 } as CSSProperties}>
              <Select
                id="usage-period"
                size="sm"
                labelText="Periodo"
                value={state.period}
                onChange={(e) => setState({ period: e.target.value })}
              >
                <SelectItem value="" text="Hora" />
                <SelectItem value="day" text="Día" />
              </Select>
              <Select
                id="usage-hours"
                size="sm"
                labelText="Rango"
                value={state.hours}
                onChange={(e) => setState({ hours: e.target.value })}
              >
                <SelectItem value="" text="48 h" />
                <SelectItem value="6" text="Últimas 6 h" />
                <SelectItem value="24" text="Últimas 24 h" />
              </Select>
              <Select
                id="usage-account"
                size="sm"
                labelText="Cuenta"
                value={state.account}
                onChange={(e) => setState({ account: e.target.value })}
              >
                <SelectItem value="" text="Todas" />
                {[...accounts]
                  .sort((a, b) => a.name.localeCompare(b.name))
                  .map((a) => (
                    <SelectItem key={a.id} value={String(a.id)} text={a.name} />
                  ))}
              </Select>
              <Select
                id="usage-app"
                size="sm"
                labelText="Aplicación"
                value={state.app}
                onChange={(e) => setState({ app: e.target.value })}
              >
                <SelectItem value="" text="Todas" />
                {applications.map((a) => (
                  <SelectItem key={a.id} value={String(a.id)} text={a.name} />
                ))}
              </Select>
              <Select
                id="usage-instance"
                size="sm"
                labelText="Instancia"
                value={state.instance}
                onChange={(e) => setState({ instance: e.target.value })}
              >
                <SelectItem value="" text="Todas" />
                {view.instanceOptions.map((i) => (
                  <SelectItem key={i.id} value={String(i.id)} text={i.label} />
                ))}
              </Select>
            </div>
          </div>

          <KpiGrid
            items={[
              { label: 'Requests', value: fmtNum(view.requests), note: '' },
              {
                label: 'Errores',
                value: fmtNum(view.errors),
                note: `${fmtNum(view.e4xx)} 4xx · ${fmtNum(view.e5xx)} 5xx`,
              },
              {
                label: 'Tasa de error',
                value: `${fmtNum(view.errorRate)} %`,
                note: 'una división por cero devuelve 0 %',
                tone: view.errorRate > 5 ? 'warn' : undefined,
              },
              { label: 'Instancias con tráfico', value: fmtNum(view.measured), note: '' },
            ]}
          />

          <div className="cmd-panels">
            <div className="cmd-panels__wide">
              {view.series.length > 0 ? (
                // Sin `help`: el bloque de serie del diseño lleva una sola línea, la del
                // resumen, que ya dice cuánto tráfico y cuánto error se está mirando.
                <ChartBlock
                  title="Requests y errores en el tiempo"
                  summary={`${fmtNum(view.requests)} requests con un ${fmtNum(view.errorRate)} % de error. Los errores llevan su propio eje a la derecha.`}
                >
                  <RequestsSeries data={view.series} />
                </ChartBlock>
              ) : (
                // El vacío conserva su rótulo, pero fuera del cuerpo del bloque: `cmd-empty`
                // ya trae su propio fondo y su relleno, y anidarlos los doblaría.
                <section className="cmd-chart">
                  <h2 className="cmd-chart__title">Serie temporal</h2>
                  <EmptyState
                    title="Sin datos en la ventana"
                    body="Amplía el rango o quita filtros: los filtros de cuenta, aplicación e instancia se aplican localmente después de relacionar cada instance_id."
                  />
                </section>
              )}
            </div>

            {view.topTraffic.length > 0 && (
              <div className="cmd-panels__wide">
                <ChartBlock
                  title="Instancias con más tráfico"
                  help="Las que más requests han servido en la ventana elegida."
                >
                  <ThresholdBars items={view.topTraffic} unit="requests" />
                </ChartBlock>
              </div>
            )}

            {view.worstErrors.length > 0 && (
              <div className="cmd-panels__wide">
                <ChartBlock
                  title="Instancias con mayor tasa de error"
                  help="Siempre con el volumen al lado: un 50 % sobre cuatro requests no es una incidencia comparable a un 3 % sobre cien mil."
                  summary={view.errorsSummary}
                >
                  <ThresholdBars items={view.worstErrors} unit="%" />
                </ChartBlock>
              </div>
            )}
          </div>
        </>
      )}
    </>
  );
}
