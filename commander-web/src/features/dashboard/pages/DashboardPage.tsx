import { useMemo } from 'react';
import { Link } from '@shared/components/AppLink';
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
import {
  fmtDateTime,
  fmtMoney,
  fmtNum,
  METRICS,
  PROVISIONING,
  SERVICE,
  pct,
} from '@services/api/mock/commander-mock';
import { useApp } from '@app/provider';
import { useResource } from '@shared/hooks/useResource';
import { groupCount, lut, rate } from '@shared/lib/data';
import { buildSummary } from '../model/summary';
import { useDashboardService } from '../services/dashboardService';
import { usageSeries } from '@features/usage/model/usageSeries';
import { PageHeader } from '@shared/components/PageHeader';
import { AxisBars, ChartBlock, Gauge, ThresholdBars, RequestsSeries } from '@shared/components/charts';
import { KpiGrid, type KpiItem } from '@shared/components/KpiGrid';
import { ErrorState } from '@shared/components/States';

export function DashboardPage() {
  const { can } = useApp();
  const dashboardService = useDashboardService();

  // Sin endpoint de resumen: el dashboard deriva de los mismos listados que las páginas.
  const res = useResource(() => dashboardService.load(can), [can]);

  const view = useMemo(() => {
    if (!res.data) return null;
    const [accounts, clients, instances, subscriptions, quotas, invoices, usage, activity] =
      res.data;
    const summary = buildSummary(accounts, clients, instances, subscriptions, quotas, invoices);
    const series = usageSeries(usage);
    return {
      ...summary,
      instances,
      activity,
      clientById: lut(clients),
      series,
      seriesRequests: series
        .filter((p) => p.group === 'Requests')
        .reduce((t, p) => t + p.value, 0),
      seriesErrors: series.filter((p) => p.group !== 'Requests').reduce((t, p) => t + p.value, 0),
      byProvisioning: groupCount(instances, (i) => i.provisioning_status),
      byService: groupCount(summary.readyInstances, (i) => i.service_status),
    };
  }, [res.data]);

  // Cada indicador entra solo si el rol puede abrir el listado que lo explica, así que
  // el número final varía y la rejilla necesita conocerlo para repartir sus columnas.
  const kpis = useMemo<KpiItem[]>(() => {
    if (!view) return [];
    const items: KpiItem[] = [];
    const add = (perm: string, item: KpiItem) => {
      if (can(perm)) items.push(item);
    };

    add('index accounts', {
      label: 'Cuentas activas',
      value: fmtNum(view.activeAccounts),
      note: `de ${view.totalAccounts} registradas`,
      to: '/accounts?status=active',
      linkLabel: 'Ver cuentas',
    });
    add('index clients', {
      label: 'Clientes activos',
      value: fmtNum(view.activeClients),
      note: `de ${view.totalClients} tenants`,
      to: '/clients?status=active',
      linkLabel: 'Ver clientes',
    });
    add('index instances', {
      label: 'Instancias listas',
      value: fmtNum(view.readyInstances.length),
      note: `de ${view.totalInstances} en el parque`,
      to: '/instances?prov=ready',
      linkLabel: 'Ver instancias',
    });
    add('index instances', {
      label: 'Instancias con incidencia',
      value: fmtNum(view.incidentInstances.length),
      note: 'failed, suspended o en gracia',
      to: '/instances?health=incident',
      linkLabel: 'Revisar',
      tone: view.incidentInstances.length > 0 ? 'crit' : undefined,
    });
    add('index subscriptions', {
      label: 'MRR contractual',
      value: fmtMoney(view.mrr),
      note: 'contratos active y trialing',
      to: '/subscriptions?status=active',
      linkLabel: 'Ver contratos',
    });
    add('index instances', {
      label: 'Requests 24 h',
      value: fmtNum(view.requests24h),
      note: 'suma de requests_24h',
      to: '/usage',
      linkLabel: 'Ver uso',
    });
    add('index quotas', {
      label: 'Cuotas excedidas',
      value: fmtNum(view.exceededQuotas.length),
      note: 'valor por encima del límite',
      to: '/quotas?state=over',
      linkLabel: 'Ver cuotas',
      tone: view.exceededQuotas.length > 0 ? 'warn' : undefined,
    });
    add('index invoices', {
      label: 'Facturas vencidas',
      value: fmtNum(view.overdueInvoices.length),
      note: `${fmtMoney(view.overdueTotal)} pendientes`,
      to: '/invoices?status=overdue',
      linkLabel: 'Ver facturas',
      tone: view.overdueInvoices.length > 0 ? 'crit' : undefined,
    });

    return items;
  }, [view, can]);

  return (
    <>
      <PageHeader
        title="Resumen"
        kicker="Inicio"
        lede="Qué necesita atención ahora. Los valores se derivan de los listados cargados en el navegador; no son un ledger nuevo."
      />

      {res.error && <ErrorState error={res.error} onRetry={res.reload} />}

      {res.loading && <SkeletonText paragraph lineCount={6} />}

      {view && (
        <>
          <KpiGrid items={kpis} />

          <div className="cmd-panels">
            {view.series.length > 0 && (
              <div className="cmd-panels__wide">
                {/* Sin `help`: el resumen ya dice qué se está mirando, y en el diseño este
                    bloque solo lleva una línea. La tasa va escrita porque las series de
                    error son ilegibles a esta escala. */}
                <ChartBlock
                  title="Requests y errores, últimas 48 horas"
                  summary={`${fmtNum(view.seriesRequests)} requests con un ${String(
                    Math.round(rate(view.seriesErrors, view.seriesRequests) * 10) / 10,
                  ).replace('.', ',')} % de error en la ventana.`}
                >
                  <RequestsSeries data={view.series} />
                </ChartBlock>
              </div>
            )}

            {can('index instances') && (
              <ChartBlock
                title="Distribución por estado de provisioning"
                help="El parque por estado del último aprovisionamiento. Cada entrada abre el listado filtrado."
              >
                {/* Todos los estados, también los que valen cero: el ciclo de vida se lee
                    entero y un hueco dice tanto como una barra. */}
                <AxisBars
                  items={Object.entries(PROVISIONING).map(([key, meta]) => ({
                    label: meta.label,
                    value: view.byProvisioning[key] ?? 0,
                    to: `/instances?prov=${key}`,
                    tone: meta.tone,
                  }))}
                  total={`${fmtNum(view.totalInstances)} instancias en el parque, en el orden del ciclo de vida.`}
                />
              </ChartBlock>
            )}

            {can('index instances') && (
              <ChartBlock
                title="Instancias listas que sirven tráfico"
                help="Solo instancias ready: gracia sirve tráfico, suspendido responde 402."
              >
                <Gauge
                  value={view.byService.active ?? 0}
                  total={view.readyInstances.length}
                  caption={`${view.byService.active ?? 0} de ${view.readyInstances.length} activas`}
                  legend={Object.entries(SERVICE).map(([key, meta]) => ({
                    label: meta.label,
                    value: view.byService[key] ?? 0,
                    percent: Math.round(
                      ((view.byService[key] ?? 0) / (view.readyInstances.length || 1)) * 100,
                    ),
                    tone: meta.tone,
                    to: `/instances?svc=${key}`,
                  }))}
                  footer={`${fmtNum(view.readyInstances.length)} instancias listas de ${fmtNum(view.totalInstances)} en el parque.`}
                />
              </ChartBlock>
            )}

            {can('index quotas') && view.topQuotas.length > 0 && (
              <div className="cmd-panels__wide">
                <ChartBlock
                  title="Consumo más alto por cuota"
                  help="Valor, límite, unidad y porcentaje de las cuotas más apuradas del parque."
                  linkTo="/quotas"
                  linkLabel="Todas las cuotas"
                >
                  <ThresholdBars
                    items={view.topQuotas.map((q) => {
                      const p = pct(q.current_value, q.limit_value) ?? 0;
                      const metric = METRICS[q.metric_key];
                      const client = q.instance_id
                        ? view.clientById.get(
                            view.instances.find((i) => i.id === q.instance_id)?.client_id ?? -1,
                          )
                        : undefined;
                      return {
                        label: `${client?.name ?? 'Contrato'} · ${metric?.name ?? q.metric_key}`,
                        value: p,
                        reading: `${fmtNum(q.current_value)} / ${fmtNum(q.limit_value)} ${metric?.unit ?? ''}`.trim(),
                        over: p > 100,
                        to: `/quotas/${q.id}`,
                        warnAt: q.warning_percent ?? undefined,
                        tone: p >= 100 ? ('crit' as const) : p >= 80 ? ('warn' as const) : ('ok' as const),
                      };
                    })}
                    unit="%"
                    axisMax={100}
                    footnote="Marca de aviso de cada cuota. La barra llena equivale al límite; el porcentaje puede superarlo."
                  />
                </ChartBlock>
              </div>
            )}

            {can('index activity') && (
              <div className="cmd-panels__wide">
                <ChartBlock
                  title="Actividad reciente"
                  help="Los últimos eventos de auditoría, con su ámbito y su razón. El histórico completo está en Actividad."
                  linkTo="/activity"
                  linkLabel="Toda la actividad"
                >
                  {/* Tabla, no lista: son cinco datos por fila con la misma forma en todas,
                      y el ámbito necesita su propia columna para poder enlazarse. */}
                  <TableContainer className="cmd-table-scroll">
                    <Table size="sm" aria-label="Actividad reciente">
                      <TableHead>
                        <TableRow>
                          <TableHeader>Fecha</TableHeader>
                          <TableHeader>Tipo</TableHeader>
                          <TableHeader>Ámbito</TableHeader>
                          <TableHeader>Razón</TableHeader>
                          <TableHeader>Mensaje</TableHeader>
                        </TableRow>
                      </TableHead>
                      <TableBody>
                        {/* El tipo es dato, no destino: la salida del bloque es «Toda la
                            actividad», y el único enlace por fila lleva a su instancia. */}
                        {view.activity.slice(0, 8).map((a) => (
                          <TableRow key={a.id}>
                            <TableCell className="cmd-dim">{fmtDateTime(a.created_at)}</TableCell>
                            <TableCell>{a.label}</TableCell>
                            <TableCell>
                              {a.instance_id ? (
                                <Link to={`/instances/${a.instance_id}`}>
                                  Instancia {a.instance_id}
                                </Link>
                              ) : (
                                <span className="cmd-dim">—</span>
                              )}
                            </TableCell>
                            <TableCell className="cmd-mono cmd-dim">{a.reason}</TableCell>
                            <TableCell className="cmd-dim">{a.message}</TableCell>
                          </TableRow>
                        ))}
                      </TableBody>
                    </Table>
                  </TableContainer>
                </ChartBlock>
              </div>
            )}
          </div>
        </>
      )}
    </>
  );
}

