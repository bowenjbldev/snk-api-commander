import { SkeletonText } from '@carbon/react';
import { Link, useParams } from 'react-router-dom';
import { fmtDateTime, fmtRel } from '@services/api/mock/commander-mock';
import { useApp } from '@app/provider';
import { useResource } from '@shared/hooks/useResource';
import { PageHeader } from '@shared/components/PageHeader';
import { Defs, Panel } from '@shared/components/Detail';
import { ErrorState, Forbidden } from '@shared/components/States';
import { useActivityService } from '../services/activityService';

export function ActivityDetailPage() {
  const { can } = useApp();
  const activityService = useActivityService();
  const { id = '' } = useParams();
  const res = useResource(() => activityService.get(id), [id]);

  if (!can('show activity')) return <Forbidden perm="show activity" />;
  if (res.error) {
    return (
      <>
        <PageHeader title="Evento" crumbs={[{ label: 'Actividad', to: '/activity' }]} />
        <ErrorState error={res.error} onRetry={res.reload} />
      </>
    );
  }
  if (res.loading || !res.data) return <SkeletonText paragraph lineCount={4} />;

  const event = res.data;

  return (
    <>
      <PageHeader
        title={event.label}
        crumbs={[{ label: 'Actividad', to: '/activity' }, { label: event.label }]}
        lede="Auditoría operativa: el evento no se edita ni se borra."
      />
      <Panel>
        <Defs
          items={[
            { label: 'Tipo', value: <span className="cmd-mono">{event.type}</span> },
            { label: 'Razón', value: <span className="cmd-mono">{event.reason}</span> },
            { label: 'Mensaje', value: event.message },
            { label: 'Fecha', value: `${fmtDateTime(event.created_at)} (${fmtRel(event.created_at)})` },
            {
              label: 'Instancia',
              value: event.instance_id ? <Link to={`/instances/${event.instance_id}`}>{event.instance_id}</Link> : '—',
            },
            {
              label: 'Contrato',
              value: event.subscription_id ? (
                <Link to={`/subscriptions/${event.subscription_id}`}>{event.subscription_id}</Link>
              ) : (
                '—'
              ),
            },
            {
              label: 'Factura',
              value: event.invoice_id ? <Link to={`/invoices/${event.invoice_id}`}>{event.invoice_id}</Link> : '—',
            },
            { label: 'Cuenta', value: <Link to={`/accounts/${event.account_id}`}>{event.account_id}</Link> },
          ]}
        />
      </Panel>
    </>
  );
}
