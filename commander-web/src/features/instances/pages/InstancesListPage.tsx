import { useMemo, useState } from 'react';
import { Button, InlineNotification } from '@carbon/react';
import { Add } from '@carbon/icons-react';
import { useNavigate } from 'react-router-dom';
import { Link } from '@shared/components/AppLink';
import { METRICS, PROVISIONING, SERVICE, fmtMoney, fmtNum, fmtRel } from '@services/api/mock/commander-mock';
import type { Instance } from '@services/api/types';
import { useApp } from '@app/provider';
import { useResource } from '@shared/hooks/useResource';
import { lut, pending } from '@shared/lib/data';
import { DataView } from '@shared/components/DataView';
import type { Column, FilterDef } from '@shared/components/DataView';
import { ListPage } from '@shared/components/ListPage';
import { StatusTag } from '@shared/components/StatusTag';
import { Meter } from '@shared/components/Meter';
import { ActionModal } from '@shared/components/ActionModal';
import { quotaPercent, worstQuotaByInstance } from '@features/quotas/model/quotaHealth';
import {
  ACTION_LABEL,
  ACTION_PERM,
  actionDialog,
  availableActions,
  hasIncident,
  isInFlight,
} from '../model/instanceActions';
import type { InstanceAction } from '../model/instanceActions';
import { useInstancePolling } from '../hooks/useInstancePolling';
import { useInstancesService } from '../services/instancesService';

export function InstancesListPage() {
  const { can, store } = useApp();
  const instancesService = useInstancesService();
  const navigate = useNavigate();
  const [dialog, setDialog] = useState<{ action: InstanceAction; instance: Instance } | null>(null);
  const [done, setDone] = useState<string | null>(null);
  const [watched, setWatched] = useState<number | null>(null);
  const res = useResource(() => instancesService.listWithContext(), []);
  const [instances, clients, accounts, applications, plans, quotas] = pending(res.data, [[], [], [], [], [], []]);

  // Alta y baja responden 202: sin sondear, la fila se quedaría en «Aprovisionando» hasta
  // que alguien recargara a mano. Se sondea la lista entera porque es lo que se ve.
  const working = instances.some((i) => i.id === watched && isInFlight(i));
  useInstancePolling(working, res.reload, String(watched));

  const cliById = useMemo(() => lut(clients), [clients]);
  const accById = useMemo(() => lut(accounts), [accounts]);
  const appById = useMemo(() => lut(applications), [applications]);
  const planById = useMemo(() => lut(plans), [plans]);
  // La cuota más alta solo cuenta si hay límite contra el que medir y medición que comparar:
  // una cuota sin límite no tiene porcentaje y no puede ser el pico de nadie.
  const peak = useMemo(
    () => worstQuotaByInstance(quotas.filter((q) => q.limit_value !== null && q.current_value !== null)),
    [quotas],
  );
  const peakPercent = (instance: Instance) => {
    const quota = peak.get(instance.id);
    return quota ? (quotaPercent(quota) ?? null) : null;
  };

  const columns: Column<Instance>[] = [
    {
      key: 'client',
      header: 'Instancia',
      value: (i) => (cliById.get(i.client_id)?.name ?? '').toLowerCase(),
      render: (i) => <Link to={`/instances/${i.id}`}>{cliById.get(i.client_id)?.name ?? '—'}</Link>,
      sub: (i) => `#${i.id} · ${cliById.get(i.client_id)?.slug ?? ''}`,
    },
    {
      key: 'account',
      header: 'Cuenta',
      value: (i) => accById.get(i.account_id)?.name ?? null,
      render: (i) => <Link to={`/accounts/${i.account_id}`}>{accById.get(i.account_id)?.name ?? '—'}</Link>,
    },
    {
      key: 'app',
      header: 'Aplicación y plan',
      value: (i) => appById.get(i.application_id)?.name ?? null,
      render: (i) => <span className="cmd-dim">{appById.get(i.application_id)?.name ?? '—'}</span>,
      sub: (i) => (i.plan_id ? (planById.get(i.plan_id)?.name ?? 'Sin plan') : 'Sin plan'),
    },
    {
      key: 'prov',
      header: 'Provisioning',
      value: (i) => i.provisioning_status,
      render: (i) => <StatusTag catalog={PROVISIONING} value={i.provisioning_status} />,
    },
    {
      key: 'svc',
      header: 'Servicio',
      value: (i) => i.service_status,
      render: (i) => <StatusTag catalog={SERVICE} value={i.service_status} />,
    },
    {
      key: 'quota',
      header: 'Cuota más alta',
      // Sin cuota medible la fila se va al final del orden, no al principio con un cero.
      value: (i) => peakPercent(i) ?? -1,
      render: (i) => {
        const quota = peak.get(i.id);
        if (!quota) return <span className="cmd-dim">Sin cuotas con límite</span>;
        return <Meter value={quota.current_value} limit={quota.limit_value} />;
      },
      sub: (i) => {
        const quota = peak.get(i.id);
        return quota ? (METRICS[quota.metric_key]?.name ?? quota.metric_key) : null;
      },
    },
    {
      key: 'req',
      header: 'Requests 24 h',
      align: 'right',
      value: (i) => i.requests_24h,
      render: (i) => fmtNum(i.requests_24h),
      sub: (i) => (i.last_activity_at ? fmtRel(i.last_activity_at) : 'Sin actividad'),
    },
  ];

  const filters: FilterDef<Instance>[] = [
    {
      id: 'account',
      label: 'Cuenta',
      options: [...accounts]
        .sort((a, b) => a.name.localeCompare(b.name, 'es'))
        .map((a) => ({ value: String(a.id), label: a.name })),
      test: (i, v) => String(i.account_id) === v,
    },
    {
      id: 'app',
      label: 'Aplicación',
      options: applications.map((a) => ({ value: String(a.id), label: a.name })),
      test: (i, v) => String(i.application_id) === v,
    },
    {
      id: 'plan',
      label: 'Plan',
      // Dos aplicaciones pueden llamar «Base» a su plan: el nombre solo distingue con el suyo delante.
      options: plans.map((p) => ({
        value: String(p.id),
        label: `${appById.get(p.application_id)?.name ?? '—'} · ${p.name}`,
      })),
      test: (i, v) => String(i.plan_id) === v,
    },
    {
      id: 'prov',
      label: 'Provisioning',
      options: Object.entries(PROVISIONING).map(([value, meta]) => ({ value, label: meta.label })),
      test: (i, v) => i.provisioning_status === v,
    },
    {
      id: 'svc',
      label: 'Servicio',
      options: Object.entries(SERVICE).map(([value, meta]) => ({ value, label: meta.label })),
      test: (i, v) => i.service_status === v,
    },
    {
      id: 'health',
      label: 'Salud',
      all: 'Indiferente',
      options: [
        { value: 'incident', label: 'Con incidencia' },
        { value: 'quota', label: 'Cuota en aviso' },
        { value: 'ok', label: 'Sin incidencia' },
      ],
      test: (i, v) => {
        const percent = peakPercent(i);
        if (v === 'incident') return hasIncident(i);
        if (v === 'quota') return percent !== null && percent >= 80;
        return (
          i.provisioning_status === 'ready' &&
          i.service_status === 'active' &&
          (percent === null || percent < 80)
        );
      },
    },
  ];

  const client = dialog ? cliById.get(dialog.instance.client_id) : undefined;
  const spec =
    dialog &&
    actionDialog(dialog.action, dialog.instance, {
      clientName: client?.name ?? '—',
      clientSlug: client?.slug ?? '',
      graceHours: store.settings.default_grace_hours,
      currentPlan: (dialog.instance.plan_id ? planById.get(dialog.instance.plan_id)?.name : null) ?? '—',
      planOptions: plans
        .filter(
          (p) =>
            p.application_id === dialog.instance.application_id &&
            p.status === 'active' &&
            p.id !== dialog.instance.plan_id,
        )
        .map((p) => ({ value: String(p.id), label: `${p.name} · ${fmtMoney(p.monthly_price, p.currency)}` })),
    });

  return (
    <ListPage
      perm="index instances"
      title="Instancias"
      lede="Una instancia es el par cliente-aplicación. Los filtros de cuenta y aplicación se envían al endpoint; el resto son locales hasta que exista filtrado de servidor."
      actions={
        can('provision instances') && (
          <Button renderIcon={Add} onClick={() => navigate('/provisioning/new')}>
            Nueva alta
          </Button>
        )
      }
    >
      {done && (
        <InlineNotification
          kind="success"
          lowContrast
          title="Acción aplicada"
          subtitle={done}
          onClose={() => setDone(null)}
        />
      )}

      <DataView
        label="Instancias"
        rows={instances}
        columns={columns}
        filters={filters}
        searchHint="Cliente, cuenta, base de datos o ID"
        search={(i) =>
          [
            i.id,
            cliById.get(i.client_id)?.name ?? '',
            cliById.get(i.client_id)?.slug ?? '',
            accById.get(i.account_id)?.name ?? '',
            i.database_name,
          ].join(' ')
        }
        loading={res.loading}
        refreshing={res.refreshing}
        error={res.error}
        onRetry={res.reload}
        rowActions={(i) => [
          { label: 'Abrir ficha', onClick: () => navigate(`/instances/${i.id}`) },
          ...availableActions(i)
            .filter((action) => can(ACTION_PERM[action]))
            .map((action) => ({
              label: ACTION_LABEL[action],
              danger: action === 'deprovision',
              onClick: () => setDialog({ action, instance: i }),
            })),
        ]}
      />

      {/* El diálogo se monta con la fila elegida: así estrena sus valores iniciales en cada
          apertura sin que el modal tenga que distinguir una instancia de otra. */}
      {dialog && spec && (
        <ActionModal
          open
          kicker={spec.kicker}
          title={spec.title}
          body={spec.body}
          danger={spec.danger}
          submitLabel={spec.submitLabel}
          fields={spec.fields}
          initial={spec.initial}
          onClose={() => setDialog(null)}
          onSubmit={async (data) => {
            await instancesService.run(dialog.instance.id, dialog.action, data);
            res.reload();
            setDone(spec.done);
            // Solo el alta y la baja dejan trabajo en vuelo; el resto ya viene resuelto.
            if (dialog.action === 'retry' || dialog.action === 'deprovision') {
              setWatched(dialog.instance.id);
            }
          }}
        />
      )}
    </ListPage>
  );
}
