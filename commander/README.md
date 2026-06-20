# SnakeCloud Commander

Single-operator admin panel for the SnakeCloud platform — remote control
(suspend / grace / resume) and business ledger (clients, subscriptions, invoices,
usage, quotas, request logs) for subscription-based REST API deployments.

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
`loadData()` fetches clients, deployments, subscriptions, invoices, quotas, plans, packages,
the activity feed and operator settings in parallel from the API; the registry is populated by
provisioning (`POST /provisioning`) and the panel's CRUD. There is no demo dataset in
production (`src/store/fixtures.ts` is used only to seed unit/component tests).

All mutations are **optimistic with reconcile**: each action mutates the store locally and
fires the matching API call; on success it reconciles with the server entity (authoritative),
on failure it surfaces a toast and resyncs via `loadData()`. Per-deployment state actions use a
sequence guard to tolerate out-of-order responses; newly-created entities reconcile by readable
handle (clients by `slug`, invoices by `number`, plans by `slug`) until the server assigns the
real id.

The domain types in `src/store/types.ts` mirror the backend `commander_*` tables
(`commander_packages`, `commander_quota_metrics`, `commander_usage_rollups`,
`commander_request_logs`, …); the deployment's runtime state arrives nested under
`deployment.package` (the source of truth). The HTTP client lives in `src/lib/api.ts` (fetch
wrapper, Bearer token, `ApiError`, 401 handler). A `Commander` deploy can serve `commander/dist`
directly.

## Auth

Operator login issues a Sanctum Bearer token (stored in `localStorage`), validated on boot via
`/auth/me`; any 401 from the API drops the session. The UI gates actions with `useCan()` (names
match `CommanderDatabaseSeeder`), but the backend stays authoritative — every controller calls
`requirePermission` and returns 403. A tenant token does not exist in the commander DB, so it
cannot authenticate here (DB isolation).

## Architecture

```
src/
├── app/            UI shell — always-dark Header, SideNav (9 items), Breadcrumbs
├── providers/      Theme (g100/g10, persisted) · Toast · Tick (1s clock) · Actions (modal orchestration)
├── store/          zustand store (API-backed, optimistic) · auth store · types (= commander_* tables)
│                   · transitions (state machine) · fixtures (tests only)
├── lib/api.ts      fetch wrapper — Bearer auth, ApiError, 401 handler, typed endpoints
├── components/     reusable primitives — DataTableShell, Drawer, DetailPanel, InlineEdit,
│                   StatusTag/AmberTag/MethodTag, GraceCountdown, QuotaBar, KpiCluster,
│                   CopyableCode, CommandPalette, ChartCard, modals/
├── pages/          Dashboard · Clients · Packages (hub + detail tabs: Overview/Plans/
│                   Deployments/Usage/Logs/Settings) · Subscriptions · Invoices · Usage ·
│                   Quotas · RequestLogs · Settings · Login (Packages absorbs Deployments+Catalog)
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
