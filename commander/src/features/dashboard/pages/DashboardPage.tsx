import { useMemo } from 'react';
import { Link } from 'react-router-dom';
import { SkeletonText } from '@carbon/react';
import { fmtMoney, fmtNum, fmtRel, METRICS, PROVISIONING, SERVICE, pct } from '@services/api/mock/commander-mock';
import { useApp } from '@app/provider';
import { useResource } from '@shared/hooks/useResource';
import { groupCount, lut } from '@shared/lib/data';
import { buildSummary } from '../model/summary';
import { useDashboardService } from '../services/dashboardService';
import { usageSeries } from '@features/usage/model/usageSeries';
import { PageHeader } from '@shared/components/PageHeader';
import { ChartBlock, Gauge, HBars, RequestsSeries } from '@shared/components/charts';
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
    return {
      ...summary,
      instances,
      activity,
      clientById: lut(clients),
      series: usageSeries(usage),
      byProvisioning: groupCount(instances, (i) => i.provisioning_status),
      byService: groupCount(summary.readyInstances, (i) => i.service_status),
    };
  }, [res.data]);

  return (
    <>
      <PageHeader
        title="Resumen"
        lede="Qué necesita atención ahora. Los valores se derivan de los listados cargados en el navegador; no son un ledger nuevo."
      />

      {res.error && <ErrorState error={res.error} onRetry={res.reload} />}

      {res.loading && <SkeletonText paragraph lineCount={6} />}

      {view && (
        <>
          <div className="cmd-kpis">
            {can('index accounts') && (
              <Kpi
                label="Cuentas activas"
                value={fmtNum(view.activeAccounts)}
                note={`de ${view.totalAccounts} registradas`}
                to="/accounts?status=active"
                linkLabel="Ver cuentas"
              />
            )}
            {can('index clients') && (
              <Kpi
                label="Clientes activos"
                value={fmtNum(view.activeClients)}
                note={`de ${view.totalClients} tenants`}
                to="/clients?status=active"
                linkLabel="Ver clientes"
              />
            )}
            {can('index instances') && (
              <Kpi
                label="Instancias listas"
                value={fmtNum(view.readyInstances.length)}
                note={`de ${view.totalInstances} en el parque`}
                to="/instances?provisioning=ready"
                linkLabel="Ver instancias"
              />
            )}
            {can('index instances') && (
              <Kpi
                label="Instancias con incidencia"
                value={fmtNum(view.incidentInstances.length)}
                tone={view.incidentInstances.length > 0 ? 'crit' : undefined}
                note="failed, suspended o en gracia"
                to="/instances?health=incident"
                linkLabel="Revisar"
              />
            )}
            {can('index subscriptions') && (
              <Kpi
                label="MRR contractual"
                value={fmtMoney(view.mrr)}
                note="contratos active y trialing"
                to="/subscriptions?status=active"
                linkLabel="Ver contratos"
              />
            )}
            {can('index instances') && (
              <Kpi
                label="Requests 24 h"
                value={fmtNum(view.requests24h)}
                note="suma de requests_24h"
                to="/usage"
                linkLabel="Ver uso"
              />
            )}
            {can('index quotas') && (
              <Kpi
                label="Cuotas excedidas"
                value={fmtNum(view.exceededQuotas.length)}
                tone={view.exceededQuotas.length > 0 ? 'warn' : undefined}
                note="valor por encima del límite"
                to="/quotas?state=over"
                linkLabel="Ver cuotas"
              />
            )}
            {can('index invoices') && (
              <Kpi
                label="Facturas vencidas"
                value={fmtNum(view.overdueInvoices.length)}
                tone={view.overdueInvoices.length > 0 ? 'crit' : undefined}
                note={`${fmtMoney(view.overdueTotal)} pendientes`}
                to="/invoices?status=overdue"
                linkLabel="Ver facturas"
              />
            )}
          </div>

          <div className="cmd-panels">
            {view.series.length > 0 && (
              <div style={{ gridColumn: '1 / -1' }}>
                <ChartBlock
                  title="Requests y errores, últimas 48 horas"
                  help="Suma de todas las instancias medidas. Los errores usan el eje derecho para no quedar aplastados por el volumen."
                  summary={`${fmtNum(
                    view.series.filter((p) => p.group === 'Requests').reduce((t, p) => t + p.value, 0),
                  )} requests, ${fmtNum(
                    view.series.filter((p) => p.group === '5xx').reduce((t, p) => t + p.value, 0),
                  )} respuestas 5xx en la ventana.`}
                >
                  <RequestsSeries data={view.series} />
                </ChartBlock>
              </div>
            )}

            {can('index instances') && (
              <ChartBlock
                title="Instancias por estado de provisioning"
                help="Una instancia que no está lista no sirve tráfico, sea cual sea su estado de servicio."
              >
                <HBars
                  items={Object.entries(PROVISIONING)
                    .filter(([key]) => view.byProvisioning[key])
                    .map(([key, meta]) => ({
                      label: meta.label,
                      value: view.byProvisioning[key],
                      to: `/instances?provisioning=${key}`,
                      tone:
                        key === 'ready' ? ('ok' as const) : key === 'failed' ? ('crit' as const) : undefined,
                    }))}
                  unit="instancias"
                />
              </ChartBlock>
            )}

            {can('index instances') && (
              <ChartBlock
                title="Instancias listas que sirven tráfico"
                help="Solo cuenta instancias en estado ready: una instancia fallida no sirve tráfico de ninguna forma."
                summary={Object.entries(SERVICE)
                  .map(([key, meta]) => `${meta.label}: ${view.byService[key] ?? 0}`)
                  .join(' · ')}
              >
                <Gauge
                  value={view.byService.active ?? 0}
                  total={view.readyInstances.length}
                  caption={`${view.byService.active ?? 0} de ${view.readyInstances.length} instancias listas están activas.`}
                />
              </ChartBlock>
            )}

            {can('index quotas') && view.topQuotas.length > 0 && (
              <div style={{ gridColumn: '1 / -1' }}>
                <ChartBlock
                  title="Consumo más alto por cuota"
                  help="Porcentaje sobre el límite propio de cada cuota. La marca vertical es el porcentaje de aviso configurado."
                >
                  <HBars
                    items={view.topQuotas.map((q) => {
                      const p = pct(q.current_value, q.limit_value) ?? 0;
                      const client = q.instance_id
                        ? view.clientById.get(
                            view.instances.find((i) => i.id === q.instance_id)?.client_id ?? -1,
                          )
                        : undefined;
                      return {
                        label: `${client?.name ?? 'Contrato'} · ${METRICS[q.metric_key]?.name ?? q.metric_key}`,
                        value: p,
                        to: `/quotas/${q.id}`,
                        warnAt: q.warning_percent ?? undefined,
                        tone: p >= 100 ? ('crit' as const) : p >= 80 ? ('warn' as const) : ('ok' as const),
                      };
                    })}
                    unit="%"
                  />
                </ChartBlock>
              </div>
            )}

            {can('index activity') && (
              <div style={{ gridColumn: '1 / -1' }}>
                <ChartBlock
                  title="Actividad reciente"
                  help="Auditoría operativa del control plane, no un sistema de tareas."
                >
                  <ul>
                    {view.activity.slice(0, 8).map((a) => (
                      <li key={a.id} className="cmd-activity">
                        <Link to={`/activity/${a.id}`}>{a.label}</Link>
                        <span className="cmd-meter__caption">{a.message}</span>
                        <span className="cmd-meter__caption">{fmtRel(a.created_at)}</span>
                      </li>
                    ))}
                  </ul>
                </ChartBlock>
              </div>
            )}
          </div>
        </>
      )}
    </>
  );
}

function Kpi({
  label,
  value,
  note,
  to,
  linkLabel,
  tone,
}: {
  label: string;
  value: string;
  note: string;
  to: string;
  linkLabel: string;
  tone?: 'crit' | 'warn';
}) {
  return (
    <article className="cmd-kpi">
      <p className="cmd-kpi__label">{label}</p>
      <p className={`cmd-kpi__value${tone ? ` cmd-kpi__value--${tone}` : ''}`}>{value}</p>
      <p className="cmd-kpi__note">{note}</p>
      <Link to={to}>{linkLabel}</Link>
    </article>
  );
}
