# SnakeCloud Commander

Single-operator admin panel for the SnakeCloud platform — remote control
(suspend / grace / resume) and business ledger (clients, subscriptions, invoices,
usage, quotas, request logs) for subscription-based REST API packages.

Standalone **Vite + React + TypeScript** SPA built with **IBM Carbon Design System
v11** (`@carbon/react`) and `@carbon/charts-react`, **wired to the live Commander REST
API** (`src/packages/Commander`). In production it is served at `commander.<BASE_DOMAIN>`.

> Design system reference (rules, pages, flows): [`../design/README.md`](../design/README.md).

## Run

```bash
cd commander
npm install
npm run dev      # http://localhost:5174 — proxies /api/v1 to the backend (see vite.config.ts)
npm run build    # tsc -b && vite build (code-split vendor chunks)
npm run preview  # serve the production build locally
```

In dev the SPA calls a same-origin `/api/v1` that Vite proxies to the backend (no CORS, no
cert prompt). For a production build set `VITE_API_BASE` to the deploy origin
(e.g. `https://commander.<host>/api/v1`).

## Test

```bash
npm test         # vitest run (unit + component, jsdom)
npm run test:watch
```

Covers `lib/time` + `lib/format`, the zustand store mutations (suspend/grace/resume,
mark-paid, quota edits), and components (StatusTag, QuotaBar, InlineEdit, DataTableShell).

## Build & serve (Docker / static)

The build emits a static `dist/` (SPA). Serve it behind any static host with an
SPA fallback to `index.html`. A multi-stage `Dockerfile` (node build → nginx) is
included:

```bash
docker build -t snakecloud-commander ./commander
docker run -p 8080:80 snakecloud-commander   # http://localhost:8080
```

`nginx.conf` does `try_files $uri /index.html` for client-side routing and caches the
content-hashed `/assets/` immutably. (A future `Commander` deploy can serve `dist/` directly.)

## Stack

- React 19 · React Router 7 · TypeScript 5.9 · Vite 7
- `@carbon/react` 1.109 · `@carbon/styles` · `@carbon/icons-react` · `@carbon/charts-react` 1.27
- `zustand` for the in-memory store · self-hosted IBM Plex via `@ibm/plex`

## Data

The app runs on the **live Commander REST API**. The zustand store starts empty and
`loadData()` fetches clients, packages (via `packagesApi.list()`), subscriptions, invoices,
quotas, plans, the activity feed and operator settings in parallel from the API; the registry is
populated by provisioning (`POST /provisioning`) and the panel's CRUD. There is no demo dataset in
production (`src/store/fixtures.ts` is used only to seed unit/component tests).

All mutations are **optimistic with reconcile**: each action mutates the store locally and
fires the matching API call; on success it reconciles with the server entity (authoritative),
on failure it surfaces a toast and resyncs via `loadData()`. Per-package state actions (keyed by
package name) use a sequence guard to tolerate out-of-order responses; newly-created entities
reconcile by readable handle (clients by `slug`, invoices by `number`, plans by `slug`) until the
server assigns the real id.

The domain types in `src/store/types.ts` mirror the backend `commander_*` tables
(`commander_packages`, `commander_quotas`, `commander_usage_rollups`,
`commander_request_logs`, …); the store holds `packages: Package[]` — one unified row per package
(catalog: label/description/listing_status + the operational columns when provisioned). A package is
live when `provisioned_at != null`; there is no separate registry type nor a nested instance object.
The HTTP client lives in
`src/lib/api.ts` (fetch wrapper, Bearer token, `ApiError`, 401 handler). A `Commander` deploy can
serve `commander/dist` directly.

## Commander flow

Commander is the control plane for the centralized Laravel app. The SPA does not create code or
deploy containers; it operates on package types that already exist in `src/packages/*` and asks the
backend to provision, suspend, resume and de-provision runtime instances.

1. Package catalog: `GET /packages` syncs package types from disk into the single
   `commander_packages` table (catalog-only rows until provisioned) and enriches each row with
   `on_disk` and a plan count.
2. Plan setup: the operator creates `commander_plans` rows for a deployed package type. A plan
   stores the package name plus default quota limits.
3. Provisioning: `POST /provisioning` receives a plan and either an existing client or a new client
   object. The backend creates/resolves `commander_clients`, creates the live instance directly in
   `commander_packages` (identity + client link + active subscription pointer + runtime state),
   creates the `commander_subscriptions` row, seeds the package's quota rows in `commander_quotas`
   (the single quota table — edited by the panel and enforced by the backend), then creates the
   tenant database and runs `core:migrate --package=<Package>` + `core:seed --package=<Package>`.
4. Runtime requests: package subdomains resolve through `commander_packages`. Request metering
   writes `commander_request_logs`, rollups aggregate into `commander_usage_rollups`, and
   `commander:refresh` recomputes each package's cached KPIs (requests_24h, quota_health, last_seen).
   The platform chart reads `commander_usage_rollups` aggregated on the fly (no snapshot cache).
5. State control: suspend, grace and resume actions update the authoritative status columns in
   `commander_packages` through `CorePackageStateService` and write a feed row in
   `commander_activity_events`.
6. Quota enforcement: `CommanderQuotaMeasurerService` measures and updates `commander_quotas`
   directly; when a measured value exceeds a limit with `on_exceed=suspend`, it changes the package
   state and records an activity event. The panel edits the same rows, so a limit/policy change takes
   effect on enforcement immediately (no mirror to propagate to).
7. De-provisioning: deleting a package removes the package's derived rows and drops the
   tenant database. Most derived rows are protected by real foreign keys with cascades; request logs
   are intentionally deleted explicitly because `commander_request_logs` stays write-optimized and
   has no FK.

## Commander tables

All tables below live in the commander database. `commander_packages` is the single source of truth
for packages: one row per package type (catalog: label/description/listing_status, synced from disk)
that also carries the live 1:1 instance columns — identity, runtime state, client/subscription links
and cached KPIs — once provisioned (`provisioned_at != null`). There is no separate registry table.

| Table | Purpose | Key relationships |
|-------|---------|-------------------|
| `commander_plans` | Commercial plans for provisionable package types, including default quota limits. | `package` is a package name string. |
| `commander_clients` | Customer/account ledger: contact, billing and status fields. | Protected by `RESTRICT` from packages, subscriptions and invoices. |
| `commander_packages` | The single package table: one row per package type with catalog metadata (`label`, `description`, `listing_status`, synced from disk) plus the live 1:1 instance columns when provisioned — identity (`subdomain`, tenant `database`), runtime state (status, reason/message, grace timestamps, changed_by/at), client/subscription links, cached KPIs (requests_24h, quota_health, last_seen) and `provisioned_at`. De-provision is soft (nulls the operational columns, keeps the row + billing history). | FK to `commander_clients` (`RESTRICT`). `subscription_id` is an uncoupled active pointer; `commander_subscriptions.package_id` is authoritative. Referenced by subscriptions, invoices, quotas, usage rollups/snapshots and activity events. |
| `commander_subscriptions` | Billing subscription for a package, with plan label, monthly price and billing status. | FK to client (`RESTRICT`) and package (`CASCADE`). |
| `commander_invoices` | Invoice ledger with readable invoice number, amount, due dates, status and enforcement marker. | FK to client (`RESTRICT`) and package (`CASCADE`). |
| `commander_quotas` | Single quota table per (package, metric): `limit_value`/`on_exceed`/`unit` edited by the panel; `current_value`/`measured_at`/`exceeded_at` measured and enforced by the backend. No separate metrics table. | FK to package (`CASCADE`), unique by `package_id + metric_key`. |
| `commander_request_logs` | Write-heavy raw request log: package id, route/path, status, duration, user/token, IP, bytes and memory. | `package_id` is indexed but deliberately not constrained. |
| `commander_usage_rollups` | Aggregated usage buckets by package, period, route, method and status class. Powers both the per-package Usage tab and the platform chart (`GET /usage/platform` sums by package+bucket on the fly — no cache table). | FK to package (`CASCADE`), unique bucket key for upserts; indexed by `(period, period_start)` for the platform query. |
| `commander_activity_events` | Operator and automatic activity feed: suspend, grace, resume, paid and quota events. | Nullable FKs to package and invoice (`CASCADE`). |
| `commander_settings` | Singleton operator settings: default grace period, operator contact and notification toggles. | Single row, no FK. |

## Auth

Operator login issues a Sanctum Bearer token (stored in `localStorage`), validated on boot via
`/auth/me`; any 401 from the API drops the session. The UI gates actions with `useCan()` (names
match `CommanderDatabaseSeeder`), but the backend stays authoritative — every controller calls
`requirePermission` and returns 403. A tenant token does not exist in the commander DB, so it
cannot authenticate here (DB isolation).

## Architecture

```
src/
├── app/            UI shell — always-dark Header, SideNav (7 items), Breadcrumbs
├── providers/      Theme (g100/g10, persisted) · Toast · Tick (1s clock) · Actions (modal orchestration)
├── store/          zustand store (API-backed, optimistic) · auth store · types (= commander_* tables)
│                   · transitions (state machine) · fixtures (tests only)
├── lib/api.ts      fetch wrapper — Bearer auth, ApiError, 401 handler, typed endpoints
├── components/     reusable primitives — DataTableShell, Drawer, DetailPanel, InlineEdit,
│                   StatusTag/AmberTag/MethodTag, GraceCountdown, QuotaBar, KpiCluster,
│                   CopyableCode, CommandPalette, ChartCard, modals/
├── pages/          Dashboard · Clients · Packages (hub + detail tabs: Overview/Plans/
│                   Quotas/Usage/Logs/Settings) · Invoices · Usage · RequestLogs ·
│                   Settings · Login (Packages absorbs Deployments+Catalog+Quotas+Subscriptions)
├── lib/            time · format · chart data + options
├── styles/         index.scss (Carbon config + import) · tokens.scss (custom tokens + utilities)
└── router.tsx      createBrowserRouter route table
```

### Key decisions

- **Theming**: the active theme is applied as a Carbon zone class (`cds--g100` /
  `cds--g10`) on `<body>`; the Header re-themes to `g100` via a nested `<Theme>` so it
  stays dark in both modes. Charts don't follow Carbon's theme — options carry an
  explicit `theme` rebuilt via `useMemo`.
- **Live updates without table churn**: a single 1s clock lives in `TickProvider`;
  only `GraceCountdown` / `RelativeTime` leaves subscribe to it, so tables don't
  re-render every second. Request logs are fetched on demand / polled (no realtime stream).
- **Optimistic + reconcile**: store actions mutate locally and fire the API call, reconciling
  with the authoritative server entity on success and resyncing via `loadData()` on failure.
- **Actions**: `ActionsProvider` owns the suspend/grace/resume/rotate/delete and
  overdue-enforcement modals and exposes openers via `useActions()`, reused by list
  rows, batch bar, detail header, state card and invoice flows.
