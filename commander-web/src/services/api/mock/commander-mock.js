// Datos y API simulada del control plane de Commander.
// Determinista: la misma semilla produce el mismo conjunto en cada recarga.

const SEED = 20260814;
function mulberry32(a) {
  return function () {
    a |= 0; a = (a + 0x6d2b79f5) | 0;
    let t = Math.imul(a ^ (a >>> 15), 1 | a);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}
let rnd = mulberry32(SEED);
const int = (a, b) => a + Math.floor(rnd() * (b - a + 1));
const pick = (arr) => arr[Math.floor(rnd() * arr.length)];
const chance = (p) => rnd() < p;
const pad = (n, w = 2) => String(n).padStart(w, '0');

const NOW = new Date('2026-08-14T09:20:00Z');
const iso = (d) => new Date(d).toISOString();
const daysAgo = (n, h = 10) => iso(new Date(NOW.getTime() - n * 86400000 + h * 3600000 - 10 * 3600000));
const hoursAgo = (n) => iso(new Date(NOW.getTime() - n * 3600000));

/* ---------------------------------------------------------------- catálogos */

export const PROVISIONING = {
  pending: { label: 'Pendiente', tone: 'gray', sev: 'neutral' },
  provisioning: { label: 'Aprovisionando', tone: 'blue', sev: 'info' },
  ready: { label: 'Lista', tone: 'green', sev: 'ok' },
  failed: { label: 'Fallida', tone: 'red', sev: 'crit' },
  deprovisioning: { label: 'Eliminando recursos', tone: 'magenta', sev: 'warn' },
  deprovisioned: { label: 'Dada de baja', tone: 'cool-gray', sev: 'neutral' },
};
export const SERVICE = {
  active: { label: 'Activo', tone: 'green', note: 'sirve tráfico' },
  grace: { label: 'Periodo de gracia', tone: 'magenta', note: 'sirve tráfico hasta grace_until' },
  suspended: { label: 'Suspendido', tone: 'red', note: 'responde 402' },
};
export const SUB_STATUS = {
  trialing: { label: 'En prueba', tone: 'blue' },
  active: { label: 'Activo', tone: 'green' },
  past_due: { label: 'Impagado', tone: 'magenta' },
  canceled: { label: 'Cancelado', tone: 'cool-gray' },
};
export const INVOICE_STATUS = {
  draft: { label: 'Borrador', tone: 'gray' },
  pending: { label: 'Pendiente', tone: 'blue' },
  paid: { label: 'Pagada', tone: 'green' },
  overdue: { label: 'Vencida', tone: 'red' },
  void: { label: 'Anulada', tone: 'cool-gray' },
};
export const ENTITY_STATUS = {
  active: { label: 'Activo', tone: 'green' },
  inactive: { label: 'Inactivo', tone: 'cool-gray' },
  suspended: { label: 'Suspendido', tone: 'red' },
};
export const QUOTA_ACTION = {
  none: { label: 'Observar', tone: 'gray', help: 'Solo medición, sin consecuencias.' },
  warn: { label: 'Avisar', tone: 'blue', help: 'Genera warning al superar el aviso.' },
  suspend: { label: 'Suspender', tone: 'red', help: 'Abre gracia y puede suspender el servicio.' },
  recommend: { label: 'Recomendar', tone: 'magenta', help: 'Propuesta comercial, sin cambio automático.' },
};
export const METRICS = {
  requests_day: { name: 'Requests del día', unit: 'requests', window: 'day', kind: 'counter', meter: 'técnico' },
  disk_db_mb: { name: 'Base de datos', unit: 'MB', window: 'point', kind: 'gauge', meter: 'técnico' },
  disk_storage_mb: { name: 'Storage', unit: 'MB', window: 'point', kind: 'gauge', meter: 'técnico' },
  disk_total_mb: { name: 'Disco total', unit: 'MB', window: 'point', kind: 'gauge', meter: 'técnico' },
  users_total: { name: 'Usuarios locales', unit: 'usuarios', window: 'point', kind: 'gauge', meter: 'técnico' },
  editorial_seats: { name: 'Asientos editoriales', unit: 'asientos', window: 'month', kind: 'gauge', meter: 'package' },
  reads_month: { name: 'Lecturas del mes', unit: 'lecturas', window: 'month', kind: 'counter', meter: 'package' },
};

export const ROLES = {
  admin: { label: 'Administrador', perms: ['*'], hint: 'Acceso completo, incluido control y baja de instancias.' },
  comercial: {
    label: 'Comercial',
    hint: 'Cuentas, contratos y facturas. Sin control de instancias.',
    perms: ['index accounts', 'show accounts', 'create accounts', 'update accounts', 'index clients', 'show clients',
      'index subscriptions', 'show subscriptions', 'create subscriptions', 'update subscriptions',
      'index invoices', 'show invoices', 'create invoices', 'update invoices',
      'index applications', 'show applications', 'index plans', 'show plans',
      'index instances', 'show instances', 'index quotas', 'show quotas', 'index activity', 'show activity'],
  },
  operaciones: {
    label: 'Operaciones',
    hint: 'Instancias, cuotas, logs y altas. Sin facturación.',
    perms: ['index clients', 'show clients', 'create clients', 'update clients',
      'index instances', 'show instances', 'control instances', 'provision instances',
      'index quotas', 'show quotas', 'update quotas', 'index applications', 'show applications',
      'index plans', 'show plans', 'create plans', 'update plans', 'index activity', 'show activity',
      'index accounts', 'show accounts'],
  },
  lectura: {
    label: 'Solo lectura',
    hint: 'Ve todo, no cambia nada.',
    perms: ['index accounts', 'show accounts', 'index clients', 'show clients', 'index applications', 'show applications',
      'index plans', 'show plans', 'index instances', 'show instances', 'index subscriptions', 'show subscriptions',
      'index invoices', 'show invoices', 'index quotas', 'show quotas', 'index activity', 'show activity'],
  },
};

/* ------------------------------------------------------------- formateadores */

const NF = new Intl.NumberFormat('es-ES');
export const fmtNum = (n) => (n === null || n === undefined ? 'Sin medición' : NF.format(n));
export const fmtMoney = (n, cur = 'EUR') =>
  new Intl.NumberFormat('es-ES', { style: 'currency', currency: cur, maximumFractionDigits: 2 }).format(n);
const DTF = new Intl.DateTimeFormat('es-ES', {
  timeZone: 'Europe/Madrid', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit',
});
const DF = new Intl.DateTimeFormat('es-ES', { timeZone: 'Europe/Madrid', day: '2-digit', month: '2-digit', year: 'numeric' });
export const fmtDateTime = (s) => (s ? DTF.format(new Date(s)).replace(',', '') : '—');
export const fmtDate = (s) => (s ? DF.format(new Date(s)) : '—');
export function fmtRel(s) {
  if (!s) return '';
  const diff = (new Date(s).getTime() - NOW.getTime()) / 1000;
  const abs = Math.abs(diff);
  const unit = abs < 60 ? ['s', 1] : abs < 3600 ? ['min', 60] : abs < 86400 ? ['h', 3600] : ['d', 86400];
  const v = Math.round(diff / unit[1]);
  return v === 0 ? 'ahora' : v < 0 ? `hace ${-v} ${unit[0]}` : `en ${v} ${unit[0]}`;
}
export const fmtMb = (mb) => (mb === null || mb === undefined ? 'Sin medición' : mb >= 1024 ? `${(mb / 1024).toFixed(1)} GB` : `${NF.format(mb)} MB`);
export const pct = (v, l) => (l === null || l === undefined || l === 0 ? null : Math.round((v / l) * 100));
export const now = () => NOW;

/* ------------------------------------------------------------------- semilla */

const CITIES = ['Vigo', 'Lugo', 'Ourense', 'Gijón', 'Bilbao', 'Cádiz', 'Almería', 'Lleida', 'Girona', 'Cuenca', 'Zamora',
  'Huelva', 'Teruel', 'Soria', 'Ávila', 'Jaén', 'Badajoz', 'Albacete', 'Tarragona', 'Segovia', 'Palencia', 'Burgos',
  'Melilla', 'Ceuta', 'Ferrol', 'Elche', 'Reus', 'Mataró', 'Aranjuez', 'Utrera'];
const BRANDS = ['Ediciones', 'Prensa', 'Grupo', 'Diario', 'Editorial', 'Medios', 'Comunicación', 'Publicaciones'];
const SURNAMES = ['Aurora', 'Boreal', 'Caudal', 'Delta', 'Estela', 'Faro', 'Gaviota', 'Horizonte', 'Ícaro', 'Jara',
  'Kalima', 'Lumbre', 'Marea', 'Nébula', 'Ónice', 'Península', 'Quimera', 'Ribera', 'Solano', 'Tramontana'];
const PAYMENT_TERMS = ['15 días', '30 días', '45 días', '60 días', 'Al contado'];
const PATHS = ['/api/v1/articles', '/api/v1/articles/482', '/api/v1/media/upload', '/api/v1/auth/login',
  '/api/v1/users', '/api/v1/search', '/api/v1/feeds/rss', '/api/v1/stats/views', '/api/v1/sections', '/health'];

function slugify(s) {
  return s.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
}

export function createStore() {
  rnd = mulberry32(SEED);
  const store = {
    accounts: [], clients: [], applications: [], plans: [], subscriptions: [], items: [], instances: [],
    quotas: [], measurements: {}, invoices: [], activity: [], logs: [], usage: [], operators: [], settings: null,
    seq: { account: 1, client: 1, plan: 1, sub: 1, item: 1, instance: 1, quota: 1, invoice: 1, activity: 1, log: 1, operator: 1 },
  };

  /* aplicaciones ---------------------------------------------------------- */
  const apps = [
    { name: 'Kilkana', pkg: 'kilkana/kilkana', desc: 'Publicación editorial multi-tenant para diarios y revistas.', code: 'available',
      metrics: ['requests_day', 'disk_db_mb', 'disk_storage_mb', 'disk_total_mb', 'users_total', 'editorial_seats', 'reads_month'] },
    { name: 'DemoApp', pkg: 'commander/demo-app', desc: 'Aplicación de referencia para validar provisioning y cuotas.', code: 'available',
      metrics: ['requests_day', 'disk_db_mb', 'disk_storage_mb', 'disk_total_mb', 'users_total'] },
    { name: 'Atenea', pkg: 'kilkana/atenea', desc: 'Gestión de suscriptores y muros de pago.', code: 'available',
      metrics: ['requests_day', 'disk_db_mb', 'disk_total_mb', 'users_total'] },
    { name: 'Bitácora', pkg: 'kilkana/bitacora', desc: 'Archivo histórico y hemeroteca.', code: 'missing',
      metrics: ['requests_day', 'disk_db_mb', 'disk_storage_mb', 'disk_total_mb'] },
    { name: 'Faro', pkg: 'kilkana/faro', desc: 'Boletines y envíos programados.', code: 'available',
      metrics: ['requests_day', 'disk_db_mb', 'users_total'] },
  ];
  apps.forEach((a, i) => {
    store.applications.push({
      id: i + 1, name: a.name, slug: slugify(a.name), package_name: a.pkg, description: a.desc,
      status: a.name === 'Bitácora' ? 'inactive' : 'active', code_status: a.code,
      metrics: a.metrics.map((k) => ({ key: k, ...METRICS[k], status: 'active' })),
      created_at: daysAgo(400 - i * 20), updated_at: daysAgo(int(2, 60)),
    });
  });
  const appBy = (n) => store.applications.find((a) => a.name === n);

  /* planes ---------------------------------------------------------------- */
  const planSpecs = [
    ['Kilkana', 'Lector', 149, { requests_day: 50000, disk_db_mb: 2048, disk_storage_mb: 10240, disk_total_mb: 12288, users_total: 25, editorial_seats: 3, reads_month: 100000 }],
    ['Kilkana', 'Redacción', 390, { requests_day: 200000, disk_db_mb: 8192, disk_storage_mb: 51200, disk_total_mb: 59392, users_total: 80, editorial_seats: 12, reads_month: 500000 }],
    ['Kilkana', 'Editorial', 890, { requests_day: 800000, disk_db_mb: 20480, disk_storage_mb: 204800, disk_total_mb: 225280, users_total: 300, editorial_seats: 40, reads_month: 2000000 }],
    ['Kilkana', 'Grupo', 1490, { requests_day: null, disk_db_mb: 51200, disk_storage_mb: 512000, disk_total_mb: 563200, users_total: null, editorial_seats: 120, reads_month: null }],
    ['DemoApp', 'Básico', 29, { requests_day: 10000, disk_db_mb: 512, disk_storage_mb: 1024, disk_total_mb: 1536, users_total: 10 }],
    ['DemoApp', 'Estándar', 79, { requests_day: 60000, disk_db_mb: 2048, disk_storage_mb: 8192, disk_total_mb: 10240, users_total: 50 }],
    ['Atenea', 'Muro simple', 210, { requests_day: 120000, disk_db_mb: 4096, disk_total_mb: 4096, users_total: 60 }],
    ['Atenea', 'Muro avanzado', 540, { requests_day: 400000, disk_db_mb: 12288, disk_total_mb: 12288, users_total: 200 }],
    ['Bitácora', 'Archivo', 120, { requests_day: 40000, disk_db_mb: 10240, disk_storage_mb: 102400, disk_total_mb: 112640 }],
    ['Faro', 'Boletín', 95, { requests_day: 30000, disk_db_mb: 1024, users_total: 20 }],
    ['Faro', 'Boletín Pro', 245, { requests_day: 90000, disk_db_mb: 4096, users_total: 90 }],
  ];
  planSpecs.forEach(([appName, name, price, limits]) => {
    const app = appBy(appName);
    store.plans.push({
      id: store.seq.plan++, application_id: app.id, name, slug: slugify(`${appName}-${name}`),
      description: `Plantilla de límites ${name.toLowerCase()} para ${appName}.`,
      monthly_price: price, currency: 'EUR', status: appName === 'Bitácora' ? 'inactive' : 'active',
      limits: Object.entries(limits).map(([key, limit_value]) => ({
        metric_key: key, limit_value,
        warning_percent: limit_value === null ? null : pick([80, 85, 90]),
        action: limit_value === null ? 'none' : key === 'requests_day' ? 'warn' : key === 'reads_month' ? 'recommend' : pick(['warn', 'suspend']),
        consecutive_periods: limit_value === null ? null : int(1, 3),
      })),
      created_at: daysAgo(int(120, 380)), updated_at: daysAgo(int(1, 90)),
    });
  });
  const planBy = (appName, name) => store.plans.find((p) => p.application_id === appBy(appName).id && p.name === name);

  /* cuentas, clientes, contratos, instancias ------------------------------- */
  function addAccount(name, opts = {}) {
    const a = {
      id: store.seq.account++, name, slug: slugify(name),
      status: opts.status || 'active',
      email: opts.email || `admin@${slugify(name).replace(/-/g, '')}.es`,
      billing_email: opts.billing_email || `facturacion@${slugify(name).replace(/-/g, '')}.es`,
      tax_id: opts.tax_id || `B${int(10000000, 99999999)}`,
      payment_terms: opts.payment_terms || pick(PAYMENT_TERMS),
      country: opts.country || 'ES',
      address: opts.address || `Calle ${pick(SURNAMES)} ${int(1, 90)}, ${int(10000, 52000)} ${pick(CITIES)}`,
      phone: `+34 ${int(600, 989)} ${int(100, 999)} ${int(100, 999)}`,
      notes: opts.notes || '',
      created_at: opts.created_at || daysAgo(int(30, 700)),
      updated_at: daysAgo(int(0, 40)),
    };
    store.accounts.push(a);
    return a;
  }
  function addClient(account, name, slug, opts = {}) {
    const c = {
      id: store.seq.client++, account_id: account.id, name, slug,
      email: opts.email || `soporte@${slug}.es`,
      status: opts.status || 'active',
      notes: opts.notes || '',
      created_at: opts.created_at || daysAgo(int(10, 600)),
      updated_at: daysAgo(int(0, 30)),
    };
    store.clients.push(c);
    return c;
  }
  function addSubscription(account, app, opts = {}) {
    const s = {
      id: store.seq.sub++, account_id: account.id, application_id: app.id,
      name: opts.name || `${app.name} · ${account.name}`,
      mode: opts.mode || 'catalog',
      status: opts.status || 'active',
      monthly_amount: opts.monthly_amount ?? 0,
      currency: 'EUR',
      max_instances: opts.max_instances ?? 1,
      trial_ends_at: opts.trial_ends_at || null,
      starts_at: opts.starts_at || daysAgo(int(30, 500)),
      ends_at: opts.ends_at || null,
      notes: opts.notes || '',
      created_at: opts.created_at || daysAgo(int(30, 500)),
      updated_at: daysAgo(int(0, 25)),
    };
    store.subscriptions.push(s);
    return s;
  }
  function addInstance(client, app, plan, sub, opts = {}) {
    const ps = opts.provisioning_status || 'ready';
    const inst = {
      id: store.seq.instance++, client_id: client.id, account_id: client.account_id, application_id: app.id,
      plan_id: plan ? plan.id : null, subscription_id: sub ? sub.id : null,
      database_name: `kk_${client.slug.replace(/-/g, '_')}_${app.slug.slice(0, 6)}`,
      database_user: `kk_${client.slug.replace(/-/g, '_')}`,
      provisioning_status: ps,
      service_status: opts.service_status || (ps === 'ready' ? 'active' : 'active'),
      status_reason: opts.status_reason || null,
      status_message: opts.status_message || null,
      grace_until: opts.grace_until || null,
      last_error: opts.last_error || null,
      requests_24h: ps === 'ready' ? int(300, 190000) : 0,
      last_activity_at: ps === 'ready' ? hoursAgo(int(0, 30)) : null,
      created_at: opts.created_at || daysAgo(int(5, 500)),
      updated_at: opts.updated_at || daysAgo(int(0, 12)),
      provisioned_at: ps === 'ready' ? daysAgo(int(5, 480)) : null,
    };
    store.instances.push(inst);
    if (sub) {
      store.items.push({
        id: store.seq.item++, subscription_id: sub.id, client_id: client.id, instance_id: inst.id,
        plan_id: plan ? plan.id : null, status: ps === 'deprovisioned' ? 'terminated' : 'active',
        started_at: inst.created_at, ended_at: ps === 'deprovisioned' ? daysAgo(int(1, 20)) : null,
      });
    }
    if (plan && ps !== 'deprovisioned') {
      plan.limits.forEach((l) => {
        const lim = l.limit_value;
        const val = lim === null ? int(100, 900000) : Math.round(lim * (opts.load ?? (chance(0.14) ? 1.08 + rnd() * 0.5 : rnd() * 0.85)));
        const q = {
          id: store.seq.quota++, scope: 'instance', instance_id: inst.id, subscription_id: null,
          account_id: inst.account_id, application_id: app.id, metric_key: l.metric_key,
          limit_value: lim, current_value: chance(0.04) ? null : val,
          warning_percent: l.warning_percent, action: l.action, consecutive_periods: l.consecutive_periods,
          consecutive_count: 0, last_warning_at: null, measured_at: hoursAgo(int(0, 8)),
        };
        if (q.limit_value && q.current_value !== null) {
          const p = pct(q.current_value, q.limit_value);
          if (p >= (q.warning_percent || 80)) { q.last_warning_at = hoursAgo(int(1, 40)); q.consecutive_count = int(1, 3); }
        }
        store.quotas.push(q);
      });
    }
    return inst;
  }

  /* --- escenarios de aceptación (fixtures explícitas) --- */

  // 1 · Aplicación sencilla
  const aurora = addAccount('Ediciones Aurora', { payment_terms: '30 días', created_at: daysAgo(210) });
  const auroraC = addClient(aurora, 'Aurora Diario', 'aurora', { created_at: daysAgo(205) });
  const auroraSub = addSubscription(aurora, appBy('DemoApp'), { mode: 'catalog', monthly_amount: 79, max_instances: 1, name: 'DemoApp · Ediciones Aurora' });
  addInstance(auroraC, appBy('DemoApp'), planBy('DemoApp', 'Estándar'), auroraSub, { created_at: daysAgo(205), load: 0.42 });

  // 2 · Kilkana individual
  const sur = addAccount('Diario del Sur SL', { payment_terms: '45 días', created_at: daysAgo(380) });
  const surC = addClient(sur, 'Diario del Sur', 'diario-sur', { created_at: daysAgo(378) });
  const surSub = addSubscription(sur, appBy('Kilkana'), { mode: 'catalog', monthly_amount: 390, max_instances: 1, name: 'Kilkana · Diario del Sur SL' });
  const surInst = addInstance(surC, appBy('Kilkana'), planBy('Kilkana', 'Redacción'), surSub, { created_at: daysAgo(378), load: 0.61 });
  store.quotas.filter((q) => q.instance_id === surInst.id).forEach((q) => {
    if (q.metric_key === 'editorial_seats') { q.current_value = 12; q.limit_value = 12; q.last_warning_at = hoursAgo(6); }
    if (q.metric_key === 'reads_month') q.current_value = 384210;
  });

  // 3 · Grupo Kilkana: contrato negociado, capacidad 3, tres cabeceras, cuota compartida
  const norte = addAccount('Grupo Editorial Norte', { payment_terms: '60 días', created_at: daysAgo(300), notes: 'Grupo con tres cabeceras. Facturación centralizada.' });
  const norteSub = addSubscription(norte, appBy('Kilkana'), {
    mode: 'negotiated', name: 'Kilkana · Grupo Norte (3 cabeceras)', monthly_amount: 2400, max_instances: 3,
    status: 'active', starts_at: daysAgo(295),
  });
  ['Norte Vigo|norte-vigo|Redacción', 'Norte Lugo|norte-lugo|Lector', 'Norte Ourense|norte-ourense|Editorial'].forEach((row, i) => {
    const [nm, sl, pl] = row.split('|');
    const c = addClient(norte, nm, sl, { created_at: daysAgo(294 - i * 3) });
    addInstance(c, appBy('Kilkana'), planBy('Kilkana', pl), norteSub, { created_at: daysAgo(293 - i * 3), load: [0.55, 0.31, 0.93][i] });
  });
  store.quotas.push({
    id: store.seq.quota++, scope: 'subscription', instance_id: null, subscription_id: norteSub.id,
    account_id: norte.id, application_id: appBy('Kilkana').id, metric_key: 'reads_month',
    limit_value: 3000000, current_value: 2610400, warning_percent: 85, action: 'recommend',
    consecutive_periods: 2, consecutive_count: 1, last_warning_at: hoursAgo(20), measured_at: hoursAgo(1),
  });

  // 4 · Contrato personalizado: 5.000 EUR, capacidad cinco, planes distintos
  const iberica = addAccount('Prensa Ibérica Digital', { payment_terms: '30 días', created_at: daysAgo(260) });
  const ibericaSub = addSubscription(iberica, appBy('Kilkana'), {
    mode: 'negotiated', name: 'Kilkana · Acuerdo marco Ibérica', monthly_amount: 5000, max_instances: 5, status: 'active', starts_at: daysAgo(255),
  });
  ['Ibérica Levante|iberica-levante|Editorial', 'Ibérica Centro|iberica-centro|Redacción', 'Ibérica Islas|iberica-islas|Grupo'].forEach((row, i) => {
    const [nm, sl, pl] = row.split('|');
    const c = addClient(iberica, nm, sl, { created_at: daysAgo(250 - i * 8) });
    addInstance(c, appBy('Kilkana'), planBy('Kilkana', pl), ibericaSub, { created_at: daysAgo(249 - i * 8), load: [0.74, 0.48, 0.22][i] });
  });

  // 5 · Fallos e historia
  const cadiz = addAccount('Medios Faro de Cádiz', { created_at: daysAgo(90) });
  const cadizC = addClient(cadiz, 'Faro de Cádiz', 'faro-cadiz', { created_at: daysAgo(88) });
  const cadizSub = addSubscription(cadiz, appBy('Kilkana'), { monthly_amount: 149, max_instances: 1, name: 'Kilkana · Faro de Cádiz' });
  addInstance(cadizC, appBy('Kilkana'), planBy('Kilkana', 'Lector'), cadizSub, {
    provisioning_status: 'failed', created_at: daysAgo(3), updated_at: daysAgo(3, 11),
    last_error: 'SQLSTATE[HY000] [1044] Access denied for user \'kk_faro_cadiz\'@\'%\' to database \'kk_faro_cadiz_kilkan\' — el usuario runtime no pudo crear el esquema.',
    status_reason: 'provision_failed', status_message: 'Fallo al crear la base tenant. Revisa credenciales del servidor MySQL.',
  });
  const teruel = addAccount('Publicaciones Teruel', { status: 'inactive', created_at: daysAgo(420) });
  const teruelC = addClient(teruel, 'Teruel Semanal', 'teruel-semanal', { status: 'inactive', created_at: daysAgo(415) });
  const teruelSub = addSubscription(teruel, appBy('Kilkana'), { status: 'canceled', monthly_amount: 149, ends_at: daysAgo(25), name: 'Kilkana · Teruel Semanal' });
  addInstance(teruelC, appBy('Kilkana'), planBy('Kilkana', 'Lector'), teruelSub, {
    provisioning_status: 'deprovisioned', created_at: daysAgo(410), updated_at: daysAgo(25),
    status_reason: 'contract_ended', status_message: 'Baja solicitada al cancelar el contrato.',
  });
  const jaen = addAccount('Comunicación Jaén', { created_at: daysAgo(150) });
  const jaenC = addClient(jaen, 'Jaén Actualidad', 'jaen-actualidad', { created_at: daysAgo(148) });
  const jaenSub = addSubscription(jaen, appBy('Kilkana'), { monthly_amount: 390, name: 'Kilkana · Jaén Actualidad', status: 'past_due' });
  addInstance(jaenC, appBy('Kilkana'), planBy('Kilkana', 'Redacción'), jaenSub, {
    service_status: 'suspended', status_reason: 'invoice_overdue', created_at: daysAgo(147),
    status_message: 'Suspendida por factura vencida hace 21 días.', load: 0.2,
  });
  const soria = addAccount('Medios Soria', { created_at: daysAgo(200) });
  const soriaC = addClient(soria, 'Soria Digital', 'soria-digital', { created_at: daysAgo(198) });
  const soriaSub = addSubscription(soria, appBy('Atenea'), { monthly_amount: 210, name: 'Atenea · Soria Digital' });
  addInstance(soriaC, appBy('Atenea'), planBy('Atenea', 'Muro simple'), soriaSub, {
    service_status: 'grace', status_reason: 'quota_exceeded', grace_until: iso(new Date(NOW.getTime() + 26 * 3600000)),
    status_message: 'Cuota de requests superada tres periodos consecutivos.', created_at: daysAgo(197), load: 1.22,
  });

  /* --- volumen: el resto del parque --- */
  const restApps = ['Kilkana', 'Kilkana', 'Kilkana', 'Atenea', 'DemoApp', 'Faro', 'Bitácora'];
  for (let i = 0; i < 34; i++) {
    const nm = `${pick(BRANDS)} ${pick(SURNAMES)} ${chance(0.35) ? pick(CITIES) : ''}`.trim();
    const acc = addAccount(nm, { status: chance(0.1) ? 'inactive' : 'active' });
    const nClients = chance(0.22) ? int(2, 4) : 1;
    let accSub = null;
    if (nClients > 1) {
      accSub = addSubscription(acc, appBy('Kilkana'), {
        mode: 'negotiated', name: `Kilkana · ${acc.name}`, monthly_amount: int(8, 40) * 100,
        max_instances: nClients + (chance(0.5) ? 1 : 0), status: pick(['active', 'active', 'active', 'trialing', 'past_due']),
      });
    }
    for (let c = 0; c < nClients; c++) {
      const city = pick(CITIES);
      const cname = nClients > 1 ? `${acc.name.split(' ')[1]} ${city}` : acc.name;
      const cl = addClient(acc, cname, `${slugify(cname)}-${store.seq.client}`, { status: acc.status === 'inactive' ? 'inactive' : 'active' });
      const appName = accSub ? 'Kilkana' : pick(restApps);
      const app = appBy(appName);
      const plans = store.plans.filter((p) => p.application_id === app.id);
      const plan = pick(plans);
      const sub = accSub || addSubscription(acc, app, {
        monthly_amount: plan.monthly_price, name: `${app.name} · ${cl.name}`,
        status: acc.status === 'inactive' ? 'canceled' : pick(['active', 'active', 'active', 'active', 'trialing', 'past_due']),
        trial_ends_at: chance(0.12) ? iso(new Date(NOW.getTime() + int(2, 20) * 86400000)) : null,
      });
      const roll = rnd();
      const ps = roll > 0.94 ? 'failed' : roll > 0.9 ? 'deprovisioned' : roll > 0.885 ? 'provisioning' : roll > 0.875 ? 'pending' : 'ready';
      const ss = ps === 'ready' ? (roll < 0.05 ? 'suspended' : roll < 0.1 ? 'grace' : 'active') : 'active';
      addInstance(cl, app, plan, sub, {
        provisioning_status: ps, service_status: ss,
        last_error: ps === 'failed' ? pick(['Timeout al ejecutar migraciones (300 s).', 'El host de base de datos rechazó la conexión.', 'El package no expone el comando install.']) : null,
        status_reason: ss === 'suspended' ? 'invoice_overdue' : ss === 'grace' ? 'quota_exceeded' : null,
        status_message: ss === 'suspended' ? 'Suspendida por impago.' : ss === 'grace' ? 'Cuota superada, gracia abierta.' : null,
        grace_until: ss === 'grace' ? iso(new Date(NOW.getTime() + int(4, 60) * 3600000)) : null,
      });
    }
  }

  /* facturas -------------------------------------------------------------- */
  store.accounts.forEach((acc) => {
    const subs = store.subscriptions.filter((s) => s.account_id === acc.id);
    if (!subs.length) return;
    const n = int(1, 6);
    for (let i = 0; i < n; i++) {
      const issued = daysAgo(int(1, 300));
      const dueDays = parseInt(acc.payment_terms, 10) || 0;
      const due = iso(new Date(new Date(issued).getTime() + dueDays * 86400000));
      const overdue = new Date(due) < NOW;
      const st = i === 0 && chance(0.18) ? 'draft' : overdue ? pick(['paid', 'paid', 'paid', 'overdue']) : pick(['pending', 'paid', 'pending']);
      const lines = subs.slice(0, int(1, Math.min(3, subs.length))).map((s) => {
        const item = store.items.find((it) => it.subscription_id === s.id);
        const qty = 1;
        return {
          subscription_id: s.id, item_id: item ? item.id : null, instance_id: item ? item.instance_id : null,
          description: `${s.name} — mensualidad`, quantity: qty, unit_price: s.monthly_amount, amount: qty * s.monthly_amount,
        };
      });
      const total = lines.reduce((t, l) => t + l.amount, 0);
      store.invoices.push({
        id: store.seq.invoice, number: `F-2026-${pad(store.seq.invoice++, 4)}`, account_id: acc.id,
        status: st, issued_at: issued, due_at: due, currency: 'EUR', lines, total,
        created_at: issued, updated_at: issued,
      });
    }
  });
  store.invoices.sort((a, b) => new Date(b.issued_at) - new Date(a.issued_at));

  /* medidas de cuota ------------------------------------------------------ */
  store.quotas.forEach((q) => {
    const base = q.current_value ?? int(10, 5000);
    store.measurements[q.id] = Array.from({ length: 30 }, (_, i) => ({
      date: daysAgo(29 - i, 12).slice(0, 10),
      value: Math.max(0, Math.round(base * (0.55 + rnd() * 0.6) * (1 + (i - 15) * 0.012))),
    }));
  });

  /* actividad ------------------------------------------------------------- */
  const ACT = [
    ['instance_provisioned', 'Instancia aprovisionada', 'provision_ok'],
    ['instance_failed', 'Provisioning fallido', 'provision_failed'],
    ['instance_suspended', 'Instancia suspendida', 'invoice_overdue'],
    ['instance_resumed', 'Instancia reanudada', 'payment_received'],
    ['instance_grace', 'Gracia abierta', 'quota_exceeded'],
    ['plan_changed', 'Cambio de plan', 'commercial_upgrade'],
    ['quota_warning', 'Aviso de cuota', 'quota_warning'],
    ['quota_recommendation', 'Recomendación comercial', 'quota_recommend'],
    ['invoice_overdue', 'Factura vencida', 'due_date_passed'],
    ['subscription_canceled', 'Contrato cancelado', 'client_request'],
    ['instance_deprovisioned', 'Instancia dada de baja', 'contract_ended'],
  ];
  for (let i = 0; i < 480; i++) {
    const [type, label, reason] = pick(ACT);
    const inst = pick(store.instances);
    store.activity.push({
      id: store.seq.activity++, type, label,
      instance_id: chance(0.85) ? inst.id : null,
      subscription_id: chance(0.3) ? inst.subscription_id : null,
      invoice_id: type === 'invoice_overdue' ? pick(store.invoices).id : null,
      account_id: inst.account_id, reason,
      message: {
        instance_provisioned: 'Base tenant creada y migraciones aplicadas.',
        instance_failed: 'El proceso terminó con error, se conserva el ID para retry.',
        instance_suspended: 'Servicio detenido: responde 402 hasta reanudar.',
        instance_resumed: 'Servicio restablecido tras cobro.',
        instance_grace: 'Gracia de 48 h abierta por cuota superada.',
        plan_changed: 'Límites materializados desde la nueva plantilla.',
        quota_warning: 'Consumo por encima del porcentaje de aviso.',
        quota_recommendation: 'Consumo sostenido: proponer plan superior.',
        invoice_overdue: 'Vencimiento superado sin cobro registrado.',
        subscription_canceled: 'Contrato cerrado a fin de periodo.',
        instance_deprovisioned: 'Recursos eliminados, ficha histórica conservada.',
      }[type],
      created_at: hoursAgo(int(0, 1400)),
    });
  }
  store.activity.sort((a, b) => new Date(b.created_at) - new Date(a.created_at));

  /* logs ------------------------------------------------------------------ */
  const readyInst = store.instances.filter((i) => i.provisioning_status === 'ready');
  for (let i = 0; i < 2200; i++) {
    const inst = pick(readyInst);
    const roll = rnd();
    const status = roll > 0.965 ? pick([500, 502, 503]) : roll > 0.9 ? pick([401, 403, 404, 422, 429]) : pick([200, 200, 200, 201, 204, 304]);
    store.logs.push({
      id: store.seq.log++, instance_id: inst.id, account_id: inst.account_id, application_id: inst.application_id,
      method: pick(['GET', 'GET', 'GET', 'POST', 'PUT', 'PATCH', 'DELETE']),
      path: pick(PATHS), status, duration_ms: Math.round(4 + rnd() * (status >= 500 ? 3000 : 700)),
      actor: chance(0.3) ? `user:${int(2, 400)}` : chance(0.2) ? `token:${int(1, 40)}` : 'guest',
      ip: `${int(2, 220)}.${int(0, 255)}.${int(0, 255)}.${int(1, 254)}`,
      bytes: int(180, 240000), memory_mb: Math.round((12 + rnd() * 90) * 10) / 10,
      created_at: hoursAgo(rnd() * 72),
    });
  }
  store.logs.sort((a, b) => new Date(b.created_at) - new Date(a.created_at));

  /* uso de plataforma: 48 buckets horarios por instancia activa ------------ */
  const usageInst = readyInst.slice(0, 60);
  usageInst.forEach((inst) => {
    const base = Math.max(20, Math.round(inst.requests_24h / 24));
    for (let h = 47; h >= 0; h--) {
      const dayCurve = 0.45 + 0.75 * Math.sin(((24 - (h % 24)) / 24) * Math.PI);
      const req = Math.max(0, Math.round(base * dayCurve * (0.7 + rnd() * 0.6)));
      store.usage.push({
        instance_id: inst.id, bucket: hoursAgo(h), requests: req,
        e4xx: Math.round(req * (0.01 + rnd() * 0.06)),
        e5xx: chance(0.22) ? Math.round(req * (0.002 + rnd() * 0.02)) : 0,
      });
    }
  });

  /* operadores y preferencias -------------------------------------------- */
  [['Marta Ferreiro', 'marta.ferreiro@commander.es', 'Marta', 'Commander SL', '+34 986 110 220'],
   ['Iván Cobos', 'ivan.cobos@commander.es', 'Iván', 'Commander SL', '+34 986 110 221'],
   ['Nuria Salas', 'nuria.salas@commander.es', 'Nuria', 'Commander SL', '+34 986 110 222'],
   ['Óscar Beltrán', 'oscar.beltran@commander.es', 'Óscar', 'Commander SL', '+34 986 110 223']].forEach((o, i) => {
    store.operators.push({
      id: store.seq.operator++, name: o[0], email: o[1], short_name: o[2], company: o[3], phone: o[4],
      role: 'admin', created_at: daysAgo(500 - i * 60),
    });
  });
  store.settings = {
    operator_name: 'Marta Ferreiro', operator_email: 'marta.ferreiro@commander.es',
    default_grace_hours: 48, notify_suspensions: true, notify_overdue_invoices: true,
  };
  return store;
}

/* ------------------------------------------------------------------ API ---- */

export function createApi(store, ctx) {
  // ctx: { role:'admin' }. El retardo imita la red para que se vean los estados de carga.
  const wait = () => new Promise((r) => setTimeout(r, 260 + Math.random() * 320));
  async function call(fn) {
    await wait();
    return fn();
  }
  const err = (status, message, fields) => { const e = new Error(message); e.status = status; e.fields = fields || null; throw e; };
  const can = (perm) => {
    const p = ROLES[ctx.role].perms;
    return p.includes('*') || p.includes(perm);
  };
  const guard = (perm) => { if (!can(perm)) err(403, `Tu rol no tiene el permiso «${perm}».`); };

  const byId = (coll, id) => store[coll].find((r) => String(r.id) === String(id));

  const api = {
    can,
    permissions: () => (ROLES[ctx.role].perms.includes('*')
      ? Object.values(ROLES).flatMap((r) => r.perms).filter((p) => p !== '*').concat(['control instances', 'provision instances', 'delete accounts', 'delete clients', 'delete plans', 'delete subscriptions', 'delete invoices', 'update settings', 'show settings', 'index users', 'create users', 'update users', 'delete users'])
      : ROLES[ctx.role].perms),

    async login(email, password) {
      await wait();
      const fields = {};
      if (!email) fields.email = 'El correo es obligatorio.';
      else if (!/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i.test(email)) fields.email = 'Formato de correo no válido.';
      if (!password) fields.password = 'La contraseña es obligatoria.';
      if (Object.keys(fields).length) err(422, 'Revisa los campos marcados.', fields);
      if (password.length < 6) err(401, 'Credenciales no válidas.');
      return { token: 'demo.' + Math.random().toString(36).slice(2), operator: store.operators[0], role: ctx.role };
    },

    list(coll) { return call(() => store[coll].slice()); },
    get(coll, id) {
      return call(() => {
        const r = byId(coll, id);
        if (!r) err(404, 'No encontrado.');
        return r;
      });
    },
    measurements(quotaId) { return call(() => store.measurements[quotaId] || []); },
    instanceUsage(id) { return call(() => store.usage.filter((u) => u.instance_id === Number(id))); },
    instanceLogs(id) { return call(() => store.logs.filter((l) => l.instance_id === Number(id)).slice(0, 120)); },

    async createAccount(data) {
      guard('create accounts');
      return call(() => {
        const fields = {};
        if (!data.name || data.name.trim().length < 3) fields.name = 'El nombre necesita al menos 3 caracteres.';
        if (!data.email) fields.email = 'El correo es obligatorio.';
        else if (!/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i.test(data.email)) fields.email = 'Formato de correo no válido.';
        if (data.billing_email && !/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i.test(data.billing_email)) fields.billing_email = 'Formato de correo no válido.';
        if (data.tax_id && !/^[A-Z]\d{8}$/i.test(data.tax_id)) fields.tax_id = 'NIF con formato letra + 8 dígitos.';
        if (Object.keys(fields).length) err(422, 'No se pudo guardar la cuenta.', fields);
        const a = {
          id: store.seq.account++, slug: slugify(data.name), status: data.status || 'active', country: 'ES',
          payment_terms: data.payment_terms || '30 días', created_at: iso(new Date()), updated_at: iso(new Date()),
          ...data,
        };
        store.accounts.unshift(a);
        return a;
      });
    },

    async createClient(data) {
      guard('create clients');
      return call(() => {
        const fields = {};
        if (!data.account_id) fields.account_id = 'Elige la cuenta pagadora.';
        if (!data.name) fields.name = 'El nombre es obligatorio.';
        if (!data.slug) fields.slug = 'El slug es obligatorio.';
        else if (!/^[a-z0-9-]{3,40}$/.test(data.slug)) fields.slug = 'Solo minúsculas, números y guiones (3–40).';
        else if (store.clients.some((c) => c.slug === data.slug)) fields.slug = 'Ese slug ya existe: el subdominio debe ser único.';
        if (!data.email) fields.email = 'El correo de contacto es obligatorio.';
        if (Object.keys(fields).length) err(422, 'No se pudo guardar el cliente.', fields);
        const c = { id: store.seq.client++, status: 'active', created_at: iso(new Date()), updated_at: iso(new Date()), ...data };
        store.clients.unshift(c);
        return c;
      });
    },

    async savePlan(data) {
      guard(data.id ? 'update plans' : 'create plans');
      return call(() => {
        const fields = {};
        if (!data.name) fields.name = 'El nombre es obligatorio.';
        if (!data.slug || !/^[a-z0-9-]{3,60}$/.test(data.slug || '')) fields.slug = 'Slug con minúsculas, números y guiones.';
        if (data.monthly_price === '' || data.monthly_price === null || isNaN(Number(data.monthly_price))) fields.monthly_price = 'Precio mensual numérico.';
        else if (Number(data.monthly_price) < 0) fields.monthly_price = 'El precio no puede ser negativo.';
        (data.limits || []).forEach((l) => {
          if (l.limit_value !== null && l.limit_value !== '' && Number(l.limit_value) < 0) fields['limit_' + l.metric_key] = 'El límite no puede ser negativo.';
          if (l.warning_percent !== null && l.warning_percent !== '' && (Number(l.warning_percent) < 1 || Number(l.warning_percent) > 100)) fields['warn_' + l.metric_key] = 'Entre 1 y 100.';
        });
        if (Object.keys(fields).length) err(422, 'Revisa la plantilla de límites.', fields);
        if (data.id) {
          const p = byId('plans', data.id);
          Object.assign(p, data, { monthly_price: Number(data.monthly_price), updated_at: iso(new Date()) });
          return p;
        }
        const p = { id: store.seq.plan++, currency: 'EUR', created_at: iso(new Date()), updated_at: iso(new Date()), ...data, monthly_price: Number(data.monthly_price) };
        store.plans.push(p);
        return p;
      });
    },

    async createSubscription(data) {
      guard('create subscriptions');
      return call(() => {
        const fields = {};
        if (!data.account_id) fields.account_id = 'Elige la cuenta.';
        if (!data.application_id) fields.application_id = 'Elige la aplicación.';
        if (!data.name) fields.name = 'Pon un nombre reconocible.';
        if (data.monthly_amount === '' || isNaN(Number(data.monthly_amount))) fields.monthly_amount = 'Importe mensual numérico.';
        if (!data.max_instances || Number(data.max_instances) < 1) fields.max_instances = 'La capacidad mínima es 1.';
        if (Object.keys(fields).length) err(422, 'No se pudo crear el contrato.', fields);
        const s = {
          id: store.seq.sub++, currency: 'EUR', status: data.status || 'active', mode: data.mode || 'negotiated',
          starts_at: data.starts_at || iso(new Date()), ends_at: null, trial_ends_at: null,
          created_at: iso(new Date()), updated_at: iso(new Date()), ...data,
          monthly_amount: Number(data.monthly_amount), max_instances: Number(data.max_instances),
        };
        store.subscriptions.unshift(s);
        return s;
      });
    },

    async updateSubscription(id, data) {
      guard('update subscriptions');
      return call(() => {
        const s = byId('subscriptions', id);
        const active = store.items.filter((it) => it.subscription_id === s.id && it.status === 'active').length;
        if (data.max_instances !== undefined && Number(data.max_instances) < active) {
          err(422, 'La capacidad no puede quedar por debajo de las cabeceras activas.', { max_instances: `Hay ${active} cabeceras activas.` });
        }
        Object.assign(s, data, { updated_at: iso(new Date()) });
        return s;
      });
    },

    async createInvoice(data) {
      guard('create invoices');
      return call(() => {
        const fields = {};
        if (!data.account_id) fields.account_id = 'Elige la cuenta.';
        if (!data.number) fields.number = 'El número es obligatorio.';
        else if (store.invoices.some((i) => i.number === data.number)) fields.number = 'Ese número ya existe.';
        if (!data.issued_at) fields.issued_at = 'Fecha de emisión obligatoria.';
        if (!data.due_at) fields.due_at = 'Fecha de vencimiento obligatoria.';
        else if (data.issued_at && new Date(data.due_at) < new Date(data.issued_at)) fields.due_at = 'El vencimiento no puede ser anterior a la emisión.';
        const lines = (data.lines || []).filter((l) => l.subscription_id);
        if (!lines.length) fields.lines = 'Añade al menos una línea con contrato.';
        lines.forEach((l, i) => {
          if (!l.description) fields['line_desc_' + i] = 'Descripción obligatoria.';
          if (isNaN(Number(l.quantity)) || Number(l.quantity) <= 0) fields['line_qty_' + i] = 'Cantidad mayor que cero.';
          if (isNaN(Number(l.unit_price))) fields['line_price_' + i] = 'Precio numérico.';
        });
        if (Object.keys(fields).length) err(422, 'No se pudo emitir la factura.', fields);
        const withAmount = lines.map((l) => ({ ...l, quantity: Number(l.quantity), unit_price: Number(l.unit_price), amount: Number(l.quantity) * Number(l.unit_price) }));
        const inv = {
          id: store.seq.invoice++, status: data.status || 'draft', currency: data.currency || 'EUR',
          account_id: Number(data.account_id), number: data.number, issued_at: data.issued_at, due_at: data.due_at,
          lines: withAmount, total: withAmount.reduce((t, l) => t + l.amount, 0),
          created_at: iso(new Date()), updated_at: iso(new Date()),
        };
        store.invoices.unshift(inv);
        return inv;
      });
    },

    async updateInvoice(id, data) {
      guard('update invoices');
      return call(() => {
        const inv = byId('invoices', id);
        Object.assign(inv, data, { updated_at: iso(new Date()) });
        return inv;
      });
    },

    async updateQuota(id, data) {
      guard('update quotas');
      return call(() => {
        const q = byId('quotas', id);
        const fields = {};
        if (data.limit_value !== null && data.limit_value !== '' && Number(data.limit_value) < 0) fields.limit_value = 'No puede ser negativo.';
        if (data.warning_percent !== null && data.warning_percent !== '' && (Number(data.warning_percent) < 1 || Number(data.warning_percent) > 100)) fields.warning_percent = 'Entre 1 y 100.';
        if (Object.keys(fields).length) err(422, 'No se pudo guardar la cuota.', fields);
        Object.assign(q, {
          limit_value: data.limit_value === '' || data.limit_value === null ? null : Number(data.limit_value),
          warning_percent: data.warning_percent === '' ? null : Number(data.warning_percent),
          action: data.action, consecutive_periods: Number(data.consecutive_periods) || 1,
        });
        return q;
      });
    },

    async createSharedQuota(data) {
      guard('create quotas');
      return call(() => {
        const fields = {};
        if (!data.metric_key) fields.metric_key = 'Elige la métrica.';
        else if (store.quotas.some((q) => q.scope === 'subscription' && q.subscription_id === Number(data.subscription_id) && q.metric_key === data.metric_key)) {
          err(409, 'Ya existe una cuota compartida para esa métrica en este contrato.');
        }
        if (Object.keys(fields).length) err(422, 'No se pudo crear la cuota.', fields);
        const sub = byId('subscriptions', data.subscription_id);
        const q = {
          id: store.seq.quota++, scope: 'subscription', instance_id: null, subscription_id: sub.id,
          account_id: sub.account_id, application_id: sub.application_id, metric_key: data.metric_key,
          limit_value: data.limit_value === '' ? null : Number(data.limit_value),
          current_value: 0, warning_percent: Number(data.warning_percent) || 80, action: data.action || 'warn',
          consecutive_periods: Number(data.consecutive_periods) || 1, consecutive_count: 0,
          last_warning_at: null, measured_at: iso(new Date()),
        };
        store.quotas.push(q);
        store.measurements[q.id] = [];
        return q;
      });
    },

    async saveSettings(data) {
      guard('update settings');
      return call(() => {
        const fields = {};
        if (!data.operator_name) fields.operator_name = 'El nombre es obligatorio.';
        if (!/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i.test(data.operator_email || '')) fields.operator_email = 'Formato de correo no válido.';
        const h = Number(data.default_grace_hours);
        if (isNaN(h) || h < 1 || h > 720) fields.default_grace_hours = 'Entre 1 y 720 horas.';
        if (Object.keys(fields).length) err(422, 'No se pudieron guardar las preferencias.', fields);
        Object.assign(store.settings, data, { default_grace_hours: h });
        return store.settings;
      });
    },

    /* provisioning ------------------------------------------------------- */
    async provision(payload) {
      guard('provision instances');
      await wait();
      const fields = {};
      const pw = payload.admin.password || '';
      if (!payload.plan_id) fields.plan_id = 'Elige el plan.';
      if (!payload.admin.name) fields['admin.name'] = 'El nombre del administrador es obligatorio.';
      if (!/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i.test(payload.admin.email || '')) fields['admin.email'] = 'Formato de correo no válido.';
      if (pw.length < 12) fields['admin.password'] = 'Al menos doce caracteres.';
      else if (!/[a-zA-Z]/.test(pw) || !/[0-9]/.test(pw)) fields['admin.password'] = 'Debe combinar letras y números.';
      if (payload.mode === 'new_client') {
        if (!payload.client.name) fields['client.name'] = 'Nombre del cliente obligatorio.';
        if (!/^[a-z0-9-]{3,40}$/.test(payload.client.slug || '')) fields['client.slug'] = 'Slug con minúsculas, números y guiones.';
        else if (store.clients.some((c) => c.slug === payload.client.slug)) fields['client.slug'] = 'Ese slug ya existe.';
        if (!payload.account_id && !payload.account.name) fields['account.name'] = 'Nombre de la cuenta obligatorio.';
      }
      if (payload.mode === 'extra_app' && !payload.client_id) fields.client_id = 'Elige el cliente existente.';
      if (payload.mode === 'group_item') {
        if (!payload.subscription_id) fields.subscription_id = 'Elige el contrato negociado.';
        if (!payload.client.name) fields['client.name'] = 'Nombre del cliente obligatorio.';
        if (!/^[a-z0-9-]{3,40}$/.test(payload.client.slug || '')) fields['client.slug'] = 'Slug con minúsculas, números y guiones.';
        else if (store.clients.some((c) => c.slug === payload.client.slug)) fields['client.slug'] = 'Ese slug ya existe.';
      }
      if (Object.keys(fields).length) err(422, 'Revisa los campos marcados antes de enviar.', fields);

      if (payload.mode === 'group_item') {
        const sub = byId('subscriptions', payload.subscription_id);
        const active = store.items.filter((it) => it.subscription_id === sub.id && it.status === 'active').length;
        if (active >= sub.max_instances) {
          err(422, `La capacidad del contrato está agotada: ${active} de ${sub.max_instances} cabeceras activas. Amplía la capacidad antes de añadir otra.`,
            { subscription_id: 'Contrato sin capacidad libre.' });
        }
      }
      if (payload.mode === 'extra_app') {
        const cl = byId('clients', payload.client_id);
        if (store.instances.some((i) => i.client_id === cl.id && i.application_id === Number(payload.application_id) && i.provisioning_status !== 'deprovisioned')) {
          err(409, 'Ese cliente ya tiene una instancia de esta aplicación. Retry no crea duplicados.');
        }
      }

      const plan = byId('plans', payload.plan_id);
      const app = byId('applications', plan.application_id);
      let account, client, sub;
      if (payload.mode === 'extra_app') {
        client = byId('clients', payload.client_id);
        account = byId('accounts', client.account_id);
        sub = { id: store.seq.sub++, account_id: account.id, application_id: app.id, name: `${app.name} · ${client.name}`, mode: 'catalog', status: 'active', monthly_amount: plan.monthly_price, currency: 'EUR', max_instances: 1, starts_at: iso(new Date()), ends_at: null, trial_ends_at: null, created_at: iso(new Date()), updated_at: iso(new Date()) };
        store.subscriptions.unshift(sub);
      } else if (payload.mode === 'group_item') {
        sub = byId('subscriptions', payload.subscription_id);
        account = byId('accounts', sub.account_id);
        client = { id: store.seq.client++, account_id: account.id, name: payload.client.name, slug: payload.client.slug, email: payload.client.email || `soporte@${payload.client.slug}.es`, status: 'active', notes: '', created_at: iso(new Date()), updated_at: iso(new Date()) };
        store.clients.unshift(client);
      } else {
        account = payload.account_id ? byId('accounts', payload.account_id) : null;
        if (!account) {
          account = { id: store.seq.account++, name: payload.account.name, slug: slugify(payload.account.name), status: 'active', email: payload.account.email || payload.admin.email, billing_email: payload.account.billing_email || payload.admin.email, tax_id: payload.account.tax_id || '', payment_terms: payload.account.payment_terms || '30 días', country: 'ES', address: payload.account.address || '', phone: '', notes: '', created_at: iso(new Date()), updated_at: iso(new Date()) };
          store.accounts.unshift(account);
        }
        client = { id: store.seq.client++, account_id: account.id, name: payload.client.name, slug: payload.client.slug, email: payload.client.email || payload.admin.email, status: 'active', notes: '', created_at: iso(new Date()), updated_at: iso(new Date()) };
        store.clients.unshift(client);
        sub = { id: store.seq.sub++, account_id: account.id, application_id: app.id, name: `${app.name} · ${client.name}`, mode: 'catalog', status: 'active', monthly_amount: plan.monthly_price, currency: 'EUR', max_instances: 1, starts_at: iso(new Date()), ends_at: null, trial_ends_at: null, created_at: iso(new Date()), updated_at: iso(new Date()) };
        store.subscriptions.unshift(sub);
      }

      const inst = {
        id: store.seq.instance++, client_id: client.id, account_id: account.id, application_id: app.id,
        plan_id: plan.id, subscription_id: sub.id,
        database_name: `kk_${client.slug.replace(/-/g, '_')}_${app.slug.slice(0, 6)}`,
        database_user: `kk_${client.slug.replace(/-/g, '_')}`,
        provisioning_status: 'pending', service_status: 'active',
        status_reason: null, status_message: 'Trabajo encolado.', grace_until: null, last_error: null,
        requests_24h: 0, last_activity_at: null,
        created_at: iso(new Date()), updated_at: iso(new Date()), provisioned_at: null,
      };
      store.instances.unshift(inst);
      store.items.push({ id: store.seq.item++, subscription_id: sub.id, client_id: client.id, instance_id: inst.id, plan_id: plan.id, status: 'active', started_at: inst.created_at, ended_at: null });
      startProvisioningJob(store, inst, plan);
      return { accepted: true, instance: inst, account, client, subscription: sub };
    },

    async instanceAction(id, action, data = {}) {
      guard(action === 'retry' ? 'provision instances' : 'control instances');
      await wait();
      const inst = byId('instances', id);
      const plan = byId('plans', inst.plan_id);
      if (action === 'grace') {
        if (inst.provisioning_status !== 'ready') err(409, 'Solo una instancia lista admite gracia.');
        const h = Number(data.hours);
        if (isNaN(h) || h < 1 || h > 720) err(422, 'Horas de gracia no válidas.', { hours: 'Entre 1 y 720 horas.' });
        if (!data.reason) err(422, 'Indica la razón operativa.', { reason: 'La razón es obligatoria.' });
        inst.service_status = 'grace';
        inst.grace_until = iso(new Date(Date.now() + h * 3600000));
        inst.status_reason = data.reason; inst.status_message = data.message || null;
        logActivity(store, 'instance_grace', 'Gracia abierta', inst, data.reason, `Gracia de ${h} h abierta desde el SPA.`);
      } else if (action === 'suspend') {
        if (!data.reason) err(422, 'Indica la razón operativa.', { reason: 'La razón es obligatoria.' });
        inst.service_status = 'suspended'; inst.grace_until = null;
        inst.status_reason = data.reason; inst.status_message = data.message || null;
        logActivity(store, 'instance_suspended', 'Instancia suspendida', inst, data.reason, 'Suspensión manual desde el SPA.');
      } else if (action === 'resume') {
        inst.service_status = 'active'; inst.grace_until = null;
        inst.status_reason = 'manual_resume'; inst.status_message = data.message || null;
        logActivity(store, 'instance_resumed', 'Instancia reanudada', inst, 'manual_resume', 'Servicio restablecido desde el SPA.');
      } else if (action === 'change-plan') {
        if (!data.plan_id) err(422, 'Elige el plan destino.', { plan_id: 'Selecciona un plan activo.' });
        const target = byId('plans', data.plan_id);
        if (target.application_id !== inst.application_id) err(422, 'El plan debe ser de la misma aplicación.', { plan_id: 'Aplicación distinta.' });
        inst.plan_id = target.id;
        const item = store.items.find((it) => it.instance_id === inst.id && it.status === 'active');
        if (item) item.plan_id = target.id;
        // materializa límites: las cuotas de la instancia se rehacen desde la plantilla
        store.quotas = store.quotas.filter((q) => q.instance_id !== inst.id);
        target.limits.forEach((l) => {
          store.quotas.push({
            id: store.seq.quota++, scope: 'instance', instance_id: inst.id, subscription_id: null,
            account_id: inst.account_id, application_id: inst.application_id, metric_key: l.metric_key,
            limit_value: l.limit_value, current_value: l.limit_value === null ? Math.round(Math.random() * 5000) : Math.round(l.limit_value * (0.2 + Math.random() * 0.5)),
            warning_percent: l.warning_percent, action: l.action, consecutive_periods: l.consecutive_periods,
            consecutive_count: 0, last_warning_at: null, measured_at: iso(new Date()),
          });
        });
        logActivity(store, 'plan_changed', 'Cambio de plan', inst, 'commercial_upgrade', `Plan operativo ahora ${target.name}. El precio del contrato no se recalcula.`);
      } else if (action === 'retry') {
        if (!['failed', 'deprovisioned'].includes(inst.provisioning_status)) err(409, 'Retry solo aplica a instancias failed o deprovisioned.');
        const pw = data.password || '';
        if (pw.length < 12 || !/[a-zA-Z]/.test(pw) || !/[0-9]/.test(pw)) err(422, 'Credenciales no válidas.', { password: 'Doce caracteres con letras y números.' });
        inst.provisioning_status = 'pending'; inst.last_error = null; inst.status_message = 'Reintento encolado.'; inst.status_reason = 'retry';
        startProvisioningJob(store, inst, plan);
        logActivity(store, 'instance_provisioned', 'Reintento de alta', inst, 'retry', 'Retry reutiliza el mismo ID de instancia.');
      } else if (action === 'deprovision') {
        if (['deprovisioning', 'deprovisioned'].includes(inst.provisioning_status)) err(409, 'La instancia ya está de baja.');
        const client = byId('clients', inst.client_id);
        if (data.confirm !== client.slug) err(422, 'La confirmación no coincide.', { confirm: `Escribe exactamente «${client.slug}».` });
        inst.provisioning_status = 'deprovisioning'; inst.status_message = 'Eliminando recursos.'; inst.status_reason = data.reason || 'operator_request';
        startDeprovisioningJob(store, inst);
        logActivity(store, 'instance_deprovisioned', 'Baja iniciada', inst, inst.status_reason, 'Se conserva la ficha histórica.');
      }
      inst.updated_at = iso(new Date());
      return inst;
    },
  };
  return api;
}

function logActivity(store, type, label, inst, reason, message) {
  store.activity.unshift({
    id: store.seq.activity++, type, label, instance_id: inst.id, subscription_id: inst.subscription_id,
    invoice_id: null, account_id: inst.account_id, reason, message, created_at: new Date().toISOString(),
  });
}

/* Trabajos asíncronos: el SPA solo los descubre consultando GET /instances/:id */
function startProvisioningJob(store, inst, plan) {
  setTimeout(() => {
    if (inst.provisioning_status !== 'pending') return;
    inst.provisioning_status = 'provisioning';
    inst.status_message = 'Creando base tenant y aplicando migraciones.';
    inst.updated_at = new Date().toISOString();
  }, 4500);
  setTimeout(() => {
    if (inst.provisioning_status !== 'provisioning') return;
    {
      inst.provisioning_status = 'ready';
      inst.service_status = 'active';
      inst.provisioned_at = new Date().toISOString();
      inst.status_reason = 'provision_ok';
      inst.status_message = 'Base tenant creada, migraciones aplicadas y administrador inicial creado.';
      inst.last_error = null;
      inst.requests_24h = 0;
      inst.last_activity_at = new Date().toISOString();
      if (plan) {
        plan.limits.forEach((l) => {
          store.quotas.push({
            id: store.seq.quota++, scope: 'instance', instance_id: inst.id, subscription_id: null,
            account_id: inst.account_id, application_id: inst.application_id, metric_key: l.metric_key,
            limit_value: l.limit_value, current_value: l.limit_value === null ? 0 : 0,
            warning_percent: l.warning_percent, action: l.action, consecutive_periods: l.consecutive_periods,
            consecutive_count: 0, last_warning_at: null, measured_at: new Date().toISOString(),
          });
          store.measurements[store.seq.quota - 1] = [];
        });
      }
      logActivity(store, 'instance_provisioned', 'Instancia aprovisionada', inst, 'provision_ok', 'Base tenant creada y migraciones aplicadas.');
    }
    inst.updated_at = new Date().toISOString();
  }, 14000);
}

function startDeprovisioningJob(store, inst) {
  setTimeout(() => {
    if (inst.provisioning_status !== 'deprovisioning') return;
    inst.provisioning_status = 'deprovisioned';
    inst.service_status = 'suspended';
    inst.status_message = 'Recursos eliminados. La ficha histórica se conserva.';
    inst.updated_at = new Date().toISOString();
    const item = store.items.find((it) => it.instance_id === inst.id && it.status === 'active');
    if (item) { item.status = 'terminated'; item.ended_at = new Date().toISOString(); }
    store.quotas = store.quotas.filter((q) => q.instance_id !== inst.id);
  }, 12000);
}
