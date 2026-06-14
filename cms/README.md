# SnakeCloud CMS

Operator admin panel for the SnakeCloud API platform — the remote control
(suspend / grace / resume) and business ledger (clients, subscriptions,
invoices, usage, quotas, request logs) for subscription-based API deployments.

Built with **React + Vite + TypeScript** and **IBM Carbon v11** (`@carbon/react`,
`@carbon/charts`), faithfully recreating the high-fidelity design handoff in
`../docs/design/design_handoff_snakecloud_cms/` using real Carbon components and
theme tokens (not the prototype's hand-rolled CSS).

> Standalone SPA. It runs independently from the Laravel app in `../src`. The
> data layer is in-memory demo fixtures today; wire the store actions to the
> deployment `/api/v1/control/*` endpoints to go live.

## Run

```bash
cd cms
npm install
npm run dev        # http://localhost:5174
npm run build      # tsc --noEmit && vite build  → dist/
npm run typecheck  # tsc --noEmit
```

Requires Node 18+. IBM Plex fonts load from Google Fonts.

## What's implemented

- **UI Shell** — dark `Header` (g100 in *both* themes, like IBM Cloud) with ⌘K
  command palette (search deployments/clients/invoices), themed `SideNav`,
  `PROD`/connection chips, theme toggle.
- **Theme** — `g100` (dark, default) / `g10` (light), runtime-switchable via
  Carbon `<Theme>`, persisted to `localStorage`.
- **7 screens** — Dashboard, Deployments (list + detail with Overview/Usage/
  Logs/Settings tabs), Clients (list + quick-view drawer), Invoices (with the
  overdue enforcement flow), Quotas (inline-editable limits), Request Logs
  (live tail), plus Subscriptions / Usage / Settings built to the same system.
- **Status semantics** — green "Operational"/"Paid", custom warm-amber
  "Grace · 13h 42m" (live countdown) / "Pending", red "Suspended"/"Overdue".
- **Live behavior** — a simulated clock anchored at the design instant
  (2026-06-11 14:02:31 UTC) ticks in real time, so grace countdowns and
  relative timestamps stay consistent *and* update live. Request Logs prepends
  rows while "Live tail" is on (pauses on row selection).
- **DataTable** — toolbar search + filters, batch actions, row overflow menus,
  sorting, pagination, skeleton loading (`DataGrid<T>` over Carbon primitives).
- **Mutations** — every state change (suspend/grace/resume, mark paid/overdue,
  quota edits, secret rotation, delete) updates the in-memory store and fires a
  `ToastNotification` with a mono timestamp. Destructive/irreversible actions
  (delete, rotate secret) require typed confirmation. Suspend/Grace/Resume reuse
  the same modal components from list overflow, batch bar, detail header and the
  invoice follow-up modals.

## Architecture

```
src/
├── types.ts                  # domain model
├── lib/        time.ts (simulated clock + NowProvider), format.ts
├── data/       dataset.ts (one coherent fixture set), charts.ts (seeded series)
├── store/      DataStore.tsx (mutable state + mutators)
├── theme/      ThemeContext.tsx (g100/g10 + persistence)
├── notify/     ToastProvider.tsx
├── components/
│   ├── ui/     StatusTag, Bits, RelativeTime, LiveCountdown, QuotaBar,
│   │           Drawer, InlineEdit, CodeBits, DataGrid, Layout (+ barrel)
│   ├── modals/ StateChangeModals, OverdueModal, TypedConfirmModal, ModalHost
│   └── charts/ Charts.tsx (Carbon charts wrappers + legend)
├── shell/      AppHeader, AppSideNav, AppLayout, CommandPalette
└── screens/    Dashboard, Clients, Invoices, Quotas, RequestLogs,
                Subscriptions, Usage, Settings, deployments/*
```

Screens compose the kit and read live data from `useStore()`; static lookups
come from `data/dataset.ts`. The `ModalHost` centralizes every shared mutation
modal so each screen just calls `useModals().openSuspend(dep)` etc.
