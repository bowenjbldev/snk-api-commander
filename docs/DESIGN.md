# DESIGN — Prompt maestro para la IA de diseño del CMS

Genera el diseño del CMS (panel tipo Heroku) pegando en tu IA de diseño
(v0, Lovable, Figma Make, Galileo, etc.):

1. El **PROMPT MAESTRO** completo (seccion 1).
2. **UN solo bloque** de sistema de diseño (seccion 2): Carbon, Fluent 2,
   Material 3 o Ant Design.

El prompt va en ingles porque las IAs de diseño rinden notablemente mejor;
puedes traducirlo si tu herramienta prefiere español. Los datos provienen de
`docs/CONTROL-PLANE.md` (fuente de verdad del modelo).

---

## 1. PROMPT MAESTRO

```text
You are designing "SnakeCloud CMS", a single-operator admin panel (one
developer managing their own SaaS platform — NOT a multi-user product) to
operate subscription-based API deployments, in the spirit of the Heroku or
Vercel dashboards: calm, dense with data, instantly scannable, zero fluff.

CONTEXT
The operator sells access to REST APIs. Each customer gets an isolated
"deployment" on its own subdomain with its own database. Every deployment
reports usage metrics and can be remotely suspended (HTTP 402 for its users),
put in grace period, or resumed. The CMS is the remote control and the
business ledger for all of this.

GLOBAL LAYOUT
- Fixed left side navigation with icons + labels:
  Dashboard, Clients, Deployments, Subscriptions, Invoices, Usage, Quotas,
  Request Logs, Settings.
- Top bar: global search (cmd+k), environment indicator, connection status,
  operator avatar menu. Breadcrumbs under the top bar on detail pages.
- Light and dark theme (dark is the default).
- Desktop-first (1440px reference), gracefully responsive down to tablet.

UNIVERSAL CRUD PATTERN (applies to every entity page)
Each model is a page with the same anatomy:
- Header: page title, record count, primary "Create" button, filter chips.
- Data table: column sorting, per-column filters, text search, pagination
  (25/50/100), row selection for bulk actions, column visibility toggle.
- Row click opens a detail view (right-side drawer for quick view, full page
  for Deployments). Create/Edit use forms with inline validation, required
  markers, and field-level help text.
- Delete always requires a typed confirmation ("type the name to confirm").
- Empty states with illustration + primary action; skeleton loaders; toast
  notifications for every mutation (success and failure).

STATUS SEMANTICS (used everywhere as badges/pills)
- active   -> green   ("Operational")
- grace    -> amber   ("Grace period", always shows a countdown to deadline)
- suspended-> red     ("Suspended", users of that API receive HTTP 402)
Billing: paid -> green, pending -> amber, overdue -> red.

PAGES

1) DASHBOARD
- KPI cards row: Active deployments, In grace, Suspended, Requests (24h),
  Error rate (24h), MRR.
- "Attention required" table: quota breaches (OVER), grace periods expiring
  soon, overdue invoices — each row deep-links to the affected record.
- Two compact charts: total requests per hour (last 48h, stacked by
  deployment) and error rate trend.
- Recent activity feed (state changes: who/what/when, e.g. "deployment
  acme-shop suspended — payment_overdue").

2) CLIENTS — CRUD
Fields: name, email, company, phone, status (active/inactive), notes.
Table columns: name, company, email, #deployments, status, created.
Detail drawer: contact info + table of their deployments with status badges
and a shortcut to create a new deployment for this client.

3) DEPLOYMENTS — CRUD + critical actions (the heart of the product)
Fields: client (select), package name, base_url, control_secret (masked,
copy-to-clipboard, "rotate" button with confirmation), notes.
Table columns: name/subdomain, client, package, state badge, requests (24h),
quota health (mini progress), last seen, actions menu.
Row actions and detail-page actions:
- Suspend: modal asking reason (select: payment_overdue, abuse, manual) and
  a client-facing message textarea; danger-styled confirm.
- Grace: same modal + deadline date-time picker (default +72h).
- Resume: one-click with confirm.
Detail PAGE (not drawer) with tabs:
- Overview: state card (status, reason, message, grace countdown, changed_by),
  quota gauges, base_url, secret management.
- Usage: the charts described in page 6, scoped to this deployment.
- Logs: the explorer described in page 8, scoped to this deployment.
- Settings: edit fields, danger zone (delete deployment).

4) SUBSCRIPTIONS — CRUD
Fields: client, deployment, plan name, price, billing cycle
(monthly/yearly), next renewal date, billing status (paid/pending/overdue),
auto-suspend toggle ("suspend deployment when overdue").
Table columns: client, deployment, plan, price, cycle, next renewal,
billing status badge.

5) INVOICES — CRUD
Fields: subscription, amount, issued date, due date, status
(paid/pending/overdue), notes.
Table columns: number, client, deployment, amount, due date, status badge.
Action "Mark as overdue" triggers a follow-up modal: "Suspend the linked
deployment now? [Suspend / Grace 72h / Keep running]".
Action "Mark as paid" offers "Resume deployment" when it was suspended for
payment_overdue.

6) USAGE — analytics (read-only)
Deployment selector + period selector (hour/day) + date range.
Charts (time series): requests, error rate (4xx/5xx split), avg and p95
latency (ms), unique users, bytes in/out.
"Top endpoints" table: route, method, requests, error %, p95 — sortable.
Everything exportable as CSV.

7) QUOTAS — per deployment (inline editable)
Table: metric (disk_db_mb, disk_storage_mb, requests_day, users_total),
current value with progress bar against limit, limit (inline editable,
empty = unlimited), on_exceed policy (none/warn/suspend select), exceeded
since. Progress bars turn amber at 80% and red over 100% with an OVER badge.

8) REQUEST LOGS — explorer (read-only)
Filterable table over raw request logs: time, method, route, status (color
coded), duration ms, user id, IP, bytes. Filters: deployment, date range,
method, status class, route search. Row click -> detail panel with all
fields. Live "tail" toggle.

9) SETTINGS
Operator profile, API tokens for the CMS itself (create/revoke), default
grace hours, notification preferences, theme.

DATA MODEL REFERENCE (for tables/forms)
- client: name, email, company, phone, status, notes, created_at
- deployment: client_id, package, base_url, control_secret(masked),
  state{status active|grace|suspended, reason, message, grace_until,
  changed_by}, last_seen, created_at
- subscription: client_id, deployment_id, plan, price, cycle,
  next_renewal_at, billing_status, auto_suspend
- invoice: subscription_id, number, amount, issued_at, due_at, status, notes
- usage rollup: period(hour|day), period_start, route, method, status_class,
  request_count, error_count, avg_duration_ms, p95_duration_ms,
  total_request_bytes, total_response_bytes, unique_users
- quota: metric_key, current_value, limit_value(nullable), on_exceed,
  exceeded_at
- request log: occurred_at, method, route, path, status, duration_ms,
  user_id, token_id, ip, request_bytes, response_bytes

NON-NEGOTIABLE UX RULES
- Destructive/irreversible actions: typed confirmation.
- Every async action: optimistic or loading state + toast.
- Tables never jump while loading (skeleton rows).
- All states accessible: WCAG AA contrast, focus rings, keyboard navigation.
- Numbers right-aligned, tabular figures; dates relative with absolute on
  hover ("3h ago" -> tooltip "2026-06-11 14:02 UTC").

DELIVER
High-fidelity screens for: Dashboard, Deployments list, Deployment detail
(Overview + Usage tabs), Suspend modal, Clients list with drawer, Invoices
with the overdue flow, Quotas page, Request Logs explorer, and dark theme
variants of Dashboard and Deployment detail.
```

---

## 2. BLOQUES DE SISTEMA DE DISEÑO

Añade al final del prompt maestro **uno** de estos bloques.

### 2.1 Carbon Design System (IBM)

```text
DESIGN SYSTEM: IBM Carbon Design System (v11), React implementation
@carbon/react, charts with @carbon/charts.

- Typography: IBM Plex Sans (UI) and IBM Plex Mono (ids, secrets, logs,
  numeric table cells). Type scale: productive set.
- Layout: Carbon UI Shell (fixed SideNav + Header with HeaderGlobalActions
  for search and avatar). 2x Grid, 16px gutters.
- Components: DataTable with toolbar (search, filters as Dropdown/MultiSelect,
  batch actions bar), Pagination, Tag for status badges (green/teal for
  active, warm amber for grace, red for suspended), Modal (danger variant for
  suspend/delete), Tearsheet for create/edit forms, SideNav, Breadcrumb,
  InlineNotification + ToastNotification, ProgressBar for quotas,
  StructuredList in detail views, CodeSnippet for secrets/base_url.
- Charts: Carbon Charts (line, stacked bar) with the default Carbon palette.
- Character: sober, engineering-grade enterprise. Sharp corners (no border
  radius), strong 1px borders on layer boundaries, generous data density,
  Gray 100 theme for dark mode (g100) and White theme for light (g10 panels).
- Color: monochromatic UI; color is reserved for status semantics and chart
  series. Primary action: IBM Blue 60.
```

### 2.2 Fluent UI 2 (Microsoft)

```text
DESIGN SYSTEM: Microsoft Fluent 2, React implementation
@fluentui/react-components (v9).

- Typography: Segoe UI Variable (text styles ramp: Caption -> Display).
  Monospace (Cascadia Code) for ids, secrets and log values.
- Layout: NavDrawer (left, inline, expandable) + custom top bar using
  Toolbar; content on subtle acrylic-like layered surfaces.
- Components: DataGrid (sortable, selectable, resizable columns) inside a
  Card, TableCellLayout with PresenceBadge-style status dots, Badge for
  states (success/warning/danger appearance), Dialog (modalType="alert" for
  suspend/delete), Drawer for quick detail and forms, TabList for deployment
  detail tabs, Field+Input/Combobox/DatePicker forms, Toast (Toaster),
  ProgressBar for quotas, SearchBox in the top bar, Menu for row actions.
- Charts: @fluentui/react-charting (line, stacked bar, donut).
- Character: soft and luminous; 4px corner radius on controls, 8px on cards,
  subtle elevation (shadow2/shadow4), brand color as accent only. webLight /
  webDark themes via FluentProvider; brand ramp customized to a teal-blue.
- Motion: quick fades and 150ms slide for drawers (Fluent motion tokens).
```

### 2.3 Material Design 3 (Google)

```text
DESIGN SYSTEM: Material Design 3 (Material You), React implementation MUI v6
(@mui/material + @mui/x-data-grid + @mui/x-charts) themed to M3 tokens.

- Typography: Roboto (or Roboto Flex), M3 type scale (Display/Headline/
  Title/Body/Label). Roboto Mono for ids, secrets, logs and numbers.
- Layout: Navigation Drawer (standard, with active indicator pill) +
  small top app bar; content in tonal surface containers
  (surface-container-low/high) instead of hard borders.
- Components: DataGrid with toolbar (quick filter, column menu, density
  toggle), Chips for filters, AssistChip/Badge for states using M3 semantic
  containers (tertiary-green for active, secondary-amber for grace,
  error-container for suspended), Dialogs (basic + full-screen for create/
  edit on small widths), Bottom-right extended FAB as the global "Create"
  on list pages, Tabs (primary) in deployment detail, Snackbars for toasts,
  LinearProgress for quotas, OutlinedTextField forms with supporting text.
- Charts: @mui/x-charts line/bar with M3 palette.
- Character: rounded (12-16px on cards, 8px controls), tonal elevation over
  shadows, dynamic color optional — seed color a deep violet; light and dark
  color schemes generated from the same seed.
```

### 2.4 Ant Design

```text
DESIGN SYSTEM: Ant Design v5 (+ Ant Design Pro patterns), React
implementation antd with ProComponents (ProLayout, ProTable, ProForm);
charts with Ant Design Charts (AntV).

- Typography: system font stack (antd default); ui monospace for ids,
  secrets, logs, numeric cells.
- Layout: ProLayout (collapsible side menu with icons, breadcrumbs built
  in, fixed header with global search and avatar dropdown).
- Components: ProTable everywhere (toolbar with search/filters/column
  settings/density built in, batch action bar), Tag for status badges
  (success/warning/error presets), Modal.confirm with danger button for
  suspend/delete, Drawer for quick detail and ProForm create/edit,
  Descriptions for detail metadata, Statistic cards for the dashboard KPIs,
  Progress (with steps coloring) for quotas, Tabs in deployment detail,
  notification/message for toasts, RangePicker for date filters.
- Charts: AntV (Line, Column stacked, Area) with antd v5 token palette.
- Character: the densest and most "admin" of all: 6px radius, compact table
  size, abundant inline actions. Theme via ConfigProvider tokens — primary
  color customized (e.g. #1677ff -> emerald), darkAlgorithm for dark mode.
```

---

## 3. Notas para el implementador (futuro package Cms)

- La UI final consumira el `/api/v1` del deploy con `API_PACKAGE=Cms`; ese
  package guardara el registro (clients, deployments con secret cifrado,
  subscriptions, invoices) y hara de proxy de acciones: Suspend/Grace/Resume
  llaman a `POST {base_url}/api/v1/control/state` firmado (contrato en
  `docs/CONTROL-PLANE.md` §7).
- Las paginas Usage/Logs/Quotas leen snapshots locales refrescados desde
  `GET /control/state|usage` de cada deploy (cachear en `usage_snapshots`;
  los logs crudos remotos pueden quedar para una fase 2 via un futuro
  `GET /control/logs`).
- El countdown de grace usa `grace_until` (ISO-8601) del estado.
- "MRR" y billing son datos propios del Cms (no existen en los deploys).
