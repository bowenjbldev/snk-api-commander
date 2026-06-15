# SnakeCloud CMS

Single-operator admin panel for the SnakeCloud platform — remote control
(suspend / grace / resume) and business ledger (clients, subscriptions, invoices,
usage, quotas, request logs) for subscription-based REST API deployments.

Standalone **Vite + React + TypeScript** SPA built with **IBM Carbon Design System
v11** (`@carbon/react`) and `@carbon/charts-react`, faithfully recreating the design
handoff in `../docs/design/design_handoff_snakecloud_cms/`.

## Run

```bash
cd cms
npm install
npm run dev      # http://localhost:5174
npm run build    # tsc -b && vite build (code-split vendor chunks)
npm run preview  # serve the production build locally
```

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
docker build -t snakecloud-cms ./cms
docker run -p 8080:80 snakecloud-cms   # http://localhost:8080
```

`nginx.conf` does `try_files $uri /index.html` for client-side routing and caches the
content-hashed `/assets/` immutably. (A future `Cms` deploy can serve `dist/` directly.)

## Stack

- React 19 · React Router 7 · TypeScript 5.9 · Vite 7
- `@carbon/react` 1.109 · `@carbon/styles` · `@carbon/icons-react` · `@carbon/charts-react` 1.27
- `zustand` for the in-memory store · self-hosted IBM Plex via `@ibm/plex`

## Data

The app runs on a **coherent in-memory demo dataset** (`src/store/fixtures.ts`):
8 clients, 15 deployments, 8 invoices, quotas, request logs and an activity feed,
built relative to app-load time so grace countdowns tick live and relative ages
stay correct. All mutations (suspend/grace/resume, inline quota edits, invoice
mark-paid/overdue enforcement, secret rotation, CRUD) update the store in place.

There is **no backend wiring** yet. The domain types in `src/store/types.ts` mirror
the deploy-side `core_*` tables (see `../docs/CONTROL-PLANE.md`) so a future swap
from fixtures to the signed `/control` HMAC API is a data-source change, not a model
change. A future `Cms` deploy can serve `cms/dist` without moving this source.

## Architecture

```
src/
├── app/            UI shell — always-dark Header, SideNav (9 items), Breadcrumbs
├── providers/      Theme (g100/g10, persisted) · Toast · Tick (1s clock) · Actions (modal orchestration)
├── store/          zustand store · types · transitions (state machine) · fixtures · live-tail generator
├── components/     reusable primitives — DataTableShell, Drawer, DetailPanel, InlineEdit,
│                   StatusTag/AmberTag/MethodTag, GraceCountdown, QuotaBar, KpiCluster,
│                   CopyableCode, CommandPalette, ChartCard, modals/
├── pages/          Dashboard · Clients · Deployments (+ detail tabs) · Subscriptions ·
│                   Invoices · Usage · Quotas · RequestLogs · Settings
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
  re-render every second. The request-log live tail uses a plain capped table.
- **Actions**: `ActionsProvider` owns the suspend/grace/resume/rotate/delete and
  overdue-enforcement modals and exposes openers via `useActions()`, reused by list
  rows, batch bar, detail header, state card and invoice flows.
```
