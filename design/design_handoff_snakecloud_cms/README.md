# Handoff: SnakeCloud CMS — Operator Admin Panel (IBM Carbon v11)

## Overview
SnakeCloud CMS is a **single-operator admin panel** for a developer who sells subscription-based REST API deployments. Each customer gets an isolated "deployment" on its own subdomain; the CMS is the remote control (suspend / grace / resume) and the business ledger (clients, subscriptions, invoices, usage, quotas, request logs).

This package documents the high-fidelity screen designs for: Dashboard, Deployments list, Deployment detail (Overview + Usage tabs), Suspend modal, Clients list with quick-view drawer, Invoices with the overdue enforcement flow, Quotas, Request Logs explorer, plus component variations — all in dark (Gray 100) and light (White/G10) themes.

## About the Design Files
The files in this bundle are **design references created in HTML** (Design Component prototypes). They show intended look and behavior — they are **not production code to copy directly**.

Your task is to **recreate these designs in the target codebase's environment**. The intended stack (per the original design brief) is:

- **React** with **`@carbon/react`** (IBM Carbon Design System v11)
- **`@carbon/charts-react`** for charts (default Carbon categorical palette)
- IBM Plex Sans + IBM Plex Mono fonts

If the codebase already exists, follow its established patterns (routing, data layer, state). If not, a Vite + React + TypeScript app with `@carbon/react` is the appropriate starting point.

**Important:** the prototypes hand-recreate Carbon's visuals with inline styles. In the real implementation you should use the **actual Carbon components** (mapping table below) and Carbon's theme tokens (`g100` / `g10` via `<Theme>` / `@use '@carbon/styles'`), NOT the hand-rolled CSS. Where the prototype deviates intentionally (noted below), preserve the deviation.

## Fidelity
**High-fidelity.** Colors, typography, spacing, table density, status semantics, copy and flows are final design intent. Recreate pixel-faithfully **using Carbon components and tokens** — when a Carbon component's default differs slightly from the mock (e.g. exact paddings), prefer the Carbon default and keep the structure/semantics.

## Global Layout (UI Shell)
- **Header** (Carbon `Header`, 48px, always g100/dark in BOTH themes — like IBM Cloud):
  - Wordmark: `SnakeCloud` (Plex Sans 600) + `CMS` (400, #c6c6c6). 14px.
  - Global search (`HeaderGlobalAction` / expandable search, 420px field look): placeholder "Search deployments, clients, invoices…", `⌘K` keyboard hint in a 1px #525252 bordered chip (Plex Mono 10px).
  - Right cluster: `PROD` environment chip (Plex Mono 10px, letter-spacing 1.2px, 1px #6f6f6f border), connection status (7px green dot #42be65 + "Connected" 12px #c6c6c6), theme toggle icon (sun), 1px divider, avatar (28px circle, bg #393939, border #6f6f6f, initial "R").
- **SideNav** (Carbon `SideNav`, fixed, 256px, themed — white in g10, #161616 in g100):
  - Items (icon 16px + label 14px, 32px row height): Dashboard, Clients, Deployments, Subscriptions, Invoices, Usage, Quotas, Request Logs, Settings.
  - Active item: 4px IBM Blue 60 (#0f62fe) left border, layer background, weight 600. Use `@carbon/icons-react` (Dashboard, User, Stack/Deploy, Renew, Receipt, ChartBar, Meter, Catalog, Settings).
  - Footer: divider + `snakecloud-cms v1.14.2` (Plex Mono 11px, helper color).
- **Breadcrumbs** (Carbon `Breadcrumb`) under the header on detail pages only: `Deployments / kestrel-track`.
- **Content area**: 1440px reference width; content column padding 24–32px. Desktop-first, responsive down to tablet (tables may horizontally scroll).
- **Themes**: g100 (default) and g10, runtime-switchable. In the prototype this is done with CSS custom properties on `body` — in production use Carbon `<Theme theme="g100|g10">` and persist the choice.

## Character / Art Direction
- Sober, engineering-grade enterprise. **Sharp corners everywhere** — the ONLY border radius in the system is on Tags/pills (fully rounded, Carbon default) and tiny round status dots/toggles.
- Strong 1px borders on every layer boundary (`border-subtle`).
- **Monochromatic UI**: color appears ONLY as status semantics (green/amber/red), link blue, the single primary action per screen (IBM Blue 60 #0f62fe), and chart series.
- Plex Mono for: identifiers (deployment names, invoice numbers, emails, IPs, secrets, routes, versions), all numeric table cells (right-aligned), timestamps, axis labels, countdowns.
- Numbers right-aligned with tabular figures. Relative dates ("3h ago") carry a dotted underline indicating an absolute-time tooltip on hover (`2026-06-11 14:02 UTC`).

## Component Mapping (prototype → @carbon/react)
| In the mock | Use |
|---|---|
| Top bar | `Header`, `HeaderName`, `HeaderGlobalBar`, `HeaderGlobalAction` |
| Side nav | `SideNav`, `SideNavItems`, `SideNavLink` |
| Data tables | `DataTable` + `TableToolbar`, `TableToolbarSearch`, `TableBatchActions`, `TableSelectRow`, `Pagination` |
| Toolbar filter dropdowns ("State: All ▾") | `Dropdown` / `MultiSelect` (inline in toolbar) |
| Status pills | `Tag` — green (`type="green"`) for active/paid, red (`type="red"`) for suspended/overdue; **grace/pending uses a custom warm-amber tag** (see tokens — Carbon has no yellow tag; keep the custom one) |
| Suspend / overdue modals | `Modal` with `danger` primary button; `ComposedModal` for the 3-button overdue follow-up |
| Client quick view | right-side panel — implement as a custom 424px drawer (Carbon has no drawer; keep the mock's structure) |
| Quota bars | `ProgressBar` (or custom 4–6px bars as mocked) |
| Detail key/value lists | `StructuredList` |
| base_url / secret fields | `CodeSnippet` (inline) + copy button, eye toggle for reveal |
| Tabs on detail page | `Tabs` (line variant) |
| Notifications | `ToastNotification` (success/error), `InlineNotification` (warning context blocks) |
| Charts | `@carbon/charts-react`: StackedBarChart (requests/hour), LineChart/AreaChart (error rate, latency, transfer) |
| Skeletons | `DataTableSkeleton`, `SkeletonText` |
| Inline-editable quota limit | custom: dashed-underline value → click swaps to `TextInput` with ✓ / ✕ confirm |

## Status Semantics (used everywhere)
| State | Tag | Label |
|---|---|---|
| `active` | green | "Operational" |
| `grace` | warm amber | "Grace · 13h 42m" — **always shows live countdown to deadline** |
| `suspended` | red | "Suspended" — end users of that API receive HTTP 402 |
| Billing `paid` | green | "Paid" |
| Billing `pending` | amber | "Pending" |
| Billing `overdue` | red | "Overdue" + relative lateness in the Due column |

## Screens

### 01 — Dashboard
- **Title row**: "Dashboard" (24px / 400), date subline; right: `updated 14:02:31 UTC · auto-refresh 30s` (Plex Mono 11px, helper).
- **KPI row**: 6 equal tiles in a 1px-gap grid (gap shows the border color — Carbon tile cluster). Each: 12px label, 28px/400 value, 12px helper/delta. Semantically colored values: "In grace" amber, "Suspended" red. Deltas: `▲ 12% vs prior 24h` green, error-rate delta red. Values: Active 11 (of 15), Grace 2, Suspended 2, Requests 1.66M, Error rate 1.8%, MRR $3,420.
- **Attention required** (section label + red count tag "4"): bordered list, 48px rows: severity pill (Plex Mono 10px uppercase: QUOTA OVER red / GRACE ENDS amber / OVERDUE red / GRACE amber) + entity link (mono, link color) + description + right deep-link ("Review quota →", "Open deployment →", "Open invoice →"). Each row deep-links to the affected record.
- **Charts row** (grid 2fr/1fr):
  - "Requests per hour — last 48h, stacked by deployment": stacked bar, 48 bars, series = top 4 deployments + "other". Legend swatches (9px squares + Plex Mono 11px).
  - "Error rate — 4xx + 5xx": red line + 12% opacity area fill, annotation "peak 6.8% — helios-meter 429 burst".
- **Recent activity**: bordered list, 44px rows: 8px status dot (red=suspend, amber=grace, green=paid) + sentence with mono entity links + right relative timestamp (dotted underline).

### 02 — Deployments (list) — the heart of the product
- Title + count subline ("15 deployments · 11 operational · 2 in grace · 2 suspended"); primary button **Create deployment** (Blue 60, 40px, text-left with trailing `+`).
- Toolbar (48px, layer bg): search, then `State / Client / Package` dropdown filters separated by 1px borders, column-visibility icon button.
- **Batch actions bar** (when rows selected): full-width Blue 60 bar, 40px: "2 items selected" + Suspend / Grace… / Resume / | / Cancel.
- Table columns: `[checkbox] Deployment (name mono link + subdomain mono 11px helper) · Client · Package (mono 12) · State (tag) · Req (24h) (mono right) · Quota health (56px mini-bar + % — amber ≥80%, red + "OVER" mono pill >100%) · Last seen (relative, dotted) · ⋮ overflow`.
- Row height 48px; selected rows get `--selected` bg + checked checkbox. Sorted by Deployment ↑ (arrow in header).
- Overflow menu (shown open on helios-meter): Open deployment / Copy base_url / — / Suspend… / Move to grace… / — / Delete… (red). Menu: layer bg, 1px border, shadow, 36px items.
- Pagination footer (Carbon `Pagination`): items per page 25/50/100, "1–15 of 15 items".
- Row click → **detail page** (full page, not drawer).

### 03 — Deployment detail — Overview tab (kestrel-track, in grace)
- Breadcrumb `Deployments / kestrel-track`.
- Title row: name (24px Plex Mono) + amber tag "Grace period · 13h 42m"; subline client · package · base_url. Right actions: **Suspend now** (danger-tertiary: red 1px outline), **Resume deployment** (primary), ⋮ ghost icon button.
- Tabs (line): Overview (active) · Usage · Logs · Settings.
- **State card** (layer bg, 1px border, 3px amber LEFT border — Carbon warning-notification pattern): warning triangle icon, heading "Grace period — payment overdue", 3-col field grid (Reason `payment_overdue` mono / Deadline `Jun 12, 2026 09:00 UTC` / Changed by `operator · 14h ago`), full-width "Client-facing message" (quoted text returned with the HTTP 402), action row (Resume now primary 32px, Extend grace… tertiary, Suspend now danger-tertiary). Right side, divided by 1px border: "Suspends in" + **giant mono countdown `13:42:10`** (36px, amber) + "users will receive HTTP 402". Countdown ticks live in production.
- **Details card** (StructuredList): Client (link), Package (mono), Subscription (link "Tracking Pro · $180/mo"), Created, Last seen; below, labeled `base_url` CodeSnippet with copy icon.
- **Control secret card**: explanation copy, masked snippet `sk_ctl_••••••••••••••••••••3f2a` with eye + copy icons, `last rotated 41d ago` (mono 11px), **Rotate secret…** tertiary button + helper "Rotating immediately invalidates the previous secret. Requires typed confirmation."
- **Quotas card**: 4-col grid of gauges: mono metric name + % right, 6px progress bar, `current / limit` mono 11px below. `users_total` unlimited → gray bar + "no limit". "Edit limits →" link top-right.

### 04 — Deployment detail — Usage tab
- Same header; right action **Export CSV** (tertiary outline with `↓`). Everything on this tab is exportable as CSV.
- Controls: segmented `Hour | Day` (Hour active = inverted fill), date-range field `Jun 04 → Jun 11, 2026` (mono) with calendar icon, caption "UTC · rollup per hour".
- KPI strip: 6 tiles (22px values): Requests (7d) 138.4k · Error rate 1.7% · Avg latency 129ms · p95 472ms · Unique users 1,209 · Bytes 2.1GB/7.8GB.
- Charts 2×2 (each in a bordered layer card, title + legend top-right): Requests (purple #6929c4, area) · Error rate (4xx #d2a106, 5xx #fa4d56) · Latency ms (avg #1192e8, p95 #9f1853 **dashed**) · Transfer MB/h (in #005d5d, out #6929c4).
- **Top endpoints** table: Route (mono) · Method (mini method tag: GET blue, POST green) · Requests ↓ (sorted) · Error % · p95. Anomalous row (`/v1/routes/optimize` 4.1% / 1,204ms) highlighted amber.

### 05 — Suspend modal (danger)
Carbon `Modal` danger variant, 560px, over a page overlay:
- Header: mono label `kestrel-track.snakecloud.io`, title "Suspend deployment `kestrel-track`?" (20px/400), ✕ close.
- Body copy: "The API stops serving immediately. All users of this deployment will receive **HTTP 402 Payment Required** until it is resumed."
- Context warning block (3px amber left border): "This deployment is in grace with 13h 42m remaining. Suspending now skips the deadline."
- Form: **Reason** (required `*`) — Carbon `Select`: `payment_overdue | abuse | manual`, helper "Stored on the state record and shown in the activity feed."; **Client-facing message** — `TextArea`, helper "Returned in the 402 response body.", char counter `142/280` (mono).
- Footer: Carbon stacked 50/50 buttons, 56px, text-left: **Cancel** (secondary) / **Suspend deployment** (danger #da1e28).
- Suspend does NOT require typed confirmation (it's reversible); **Delete and secret rotation DO** ("type the name to confirm").
- Grace modal = same modal + a deadline datetime picker defaulting to **+72h** (operator-configurable default in Settings).

### 06 — Clients (list + quick-view drawer)
- List table: Name (link) · Company · Email (mono) · Deployments (count, mono right) · Status tag (Active green / Inactive gray) · Created (relative-ish, dotted). 8 rows. Toolbar: search + Status filter. Primary: Create client.
- Row click opens **right drawer** (424px, full content height, layer bg, 1px left border + left shadow):
  - Header: client name (18px/600) + status tag, subline "client since Mar 2024 · Acme Retail GmbH", ✕.
  - CONTACT section (uppercase 12px label): Email / Phone / Notes key-value rows.
  - Red-left-border alert block when relevant: "INV-2026-0139 · $290.00 is 9 days overdue — acme-shop auto-suspended."
  - DEPLOYMENTS · 2: mini-list (name mono link + package, status tag right).
  - **New deployment for this client** (tertiary, `+`) — shortcut that pre-fills the client in the create form.
  - Footer: 50/50 buttons — Edit client (secondary) / Open full record (primary).

### 07 — Invoices + overdue enforcement flow
- Header subline calls out the red total: "8 invoices · **2 overdue ($470.00)** · 3 pending · 3 paid".
- Table: Number (mono link) ↓ · Client · Deployment (mono) · Amount (mono right) · Issued · Due (overdue rows show red `Jun 02 · 9d overdue`; late-but-pending shows amber `Jun 09 · 2d late`) · Status tag · ⋮.
- Overdue rows also show a tiny mono annotation of the enforcement state: `→ grace` (amber) or `→ suspended` (red).
- Overflow menu: Open invoice / Edit… / — / Mark as paid / **Mark as overdue…** / — / Delete… (red).
- **"Mark as overdue" follow-up modal** (640px): label `INV-2026-0145 · Helios Energy · $520.00`, title "Invoice marked as overdue", body "The linked deployment helios-meter is still operational. Auto-suspend is off for this subscription — choose what happens to the deployment now." + info block "Grace applies the default +72h deadline → Jun 14, 14:02 UTC. Configurable in Settings." Footer: 3 equal 56px buttons: **Keep running** (secondary) / **Grace 72h** (primary blue) / **Suspend now** (danger).
- **"Mark as paid"** on an invoice whose deployment was suspended for `payment_overdue` → offer "Resume deployment" in a success follow-up.

### 08 — Quotas (inline editable)
- Subline documents behavior: "Limits are inline-editable · empty = unlimited · bars turn amber at 80%, red with an OVER badge past 100%". Header right: summary pills `1 OVER` (red) + `3 ≥ 80%` (amber).
- Toolbar: search, Deployment/Metric filters, "Only breaches" toggle.
- Table (52px rows): Deployment (mono link) · Metric (mono: `requests_day`, `disk_db_mb`, `disk_storage_mb`, `users_total`) · Current usage (200px bar + mono value + % — OVER rows show full red bar + `OVER 124%` pill) · **Limit** (mono value with dashed underline = editable affordance; unlimited shows placeholder-colored "Unlimited") · On exceed (inline select: `none` gray / `warn` amber / `suspend` red) · Exceeded since (mono red relative time, or `—`).
- **Inline edit state** (mocked on one row): value swaps to a field (layer bg, 2px Blue 60 bottom border, blinking caret) + 24px ✓ (blue, white check) and ✕ (1px outline) buttons. Enter/✓ commits, Esc/✕ cancels; toast on save.

### 09 — Request Logs (explorer, read-only)
- Header: title + "raw request log · retention 30 days · read-only". Right: **Live tail** — pulsing green dot, label, green toggle (ON), mono caption `streaming · 41 req/min`. When ON, new rows prepend in real time.
- Toolbar: route search, Deployment / Method dropdowns, time range (`last 15 min`, mono), status-class filter chips `2xx 3xx 4xx 5xx` (mono tags, toggleable).
- Log table (dense: 34px rows, ALL Plex Mono 11.5px): time (UTC, ms precision, helper color) · deployment · method (GET blue / POST green) · route (ellipsized) · **status color-coded** (2xx green, 3xx helper-gray, 4xx amber, 5xx red) · ms (right; slow+failed red) · user · bytes (right).
- Selected row: `--selected` bg + 3px inset Blue 60 left edge.
- **Detail panel** (340px, right, bordered): header "Request detail" + status tag, key-value rows for ALL fields (occurred_at, deployment, method · route, duration_ms, user_id, token_id, ip, request_bytes, response_bytes), response-body code block (JSON, e.g. `{"error":"quota_exceeded","metric":"requests_day","retry_after":34520}`), context block linking to the cause: "Rejected by quota requests_day (policy: warn). View quota →".

### 10 — Component variations (pick before implementing)
Three alternative treatments each for KPI cards (A1 plain tile — used / A2 tile+sparkline / A3 dense stat list), status tags (B1 Carbon filled — used / B2 outline+dot / B3 dot+text for max density), detail tabs (C1 line — used / C2 contained / C3 line+live counts). Defaults used across the screens: **A1, B1, C1**. Also: toast specs (success/error, with mono timestamp), skeleton table rows (no layout jump), empty state (geometric illustration + copy + primary action).

## Interactions & Behavior (non-negotiable UX rules)
- **Destructive/irreversible actions** (delete entity, rotate secret): typed confirmation — "type the name to confirm" enables the danger button.
- **Every mutation**: optimistic or loading state + ToastNotification (success AND failure with retry link).
- **Tables never jump while loading**: skeleton rows with the same heights as real rows.
- **Grace countdowns tick live** (tag in tables, big countdown on detail, dashboard "next deadline").
- Relative dates everywhere with absolute on hover: `3h ago` → tooltip `2026-06-11 14:02 UTC`.
- Suspend/Grace/Resume available from: list row overflow menu, batch bar, detail header, state card, and invoice follow-up modals — all reuse the same modal components.
- Marking an invoice overdue triggers the enforcement decision modal; marking paid offers resume if the deployment was suspended for `payment_overdue`.
- Accessibility: WCAG AA contrast in both themes, visible focus rings (Carbon default 2px Blue 60), full keyboard navigation, `aria-sort` on sortable headers, `aria-live` for toasts and the live tail.
- Global search opens with ⌘K (command palette over deployments/clients/invoices).

## State Management
- `theme: 'g100' | 'g10'` — persisted (localStorage), default g100.
- Deployment `state`: `{ status: 'active'|'grace'|'suspended', reason: 'payment_overdue'|'abuse'|'manual', message: string, grace_until: ISO|null, changed_by: string, changed_at: ISO }`. Transitions: active→grace, active→suspended, grace→suspended (deadline or manual), grace→active, suspended→active. Every transition writes an activity-feed event.
- Table state per page: sort, filters, search, page/pageSize, selection.
- Live tail: websocket/poll subscription, pause on toggle off or on row selection.
- Data fetching: list endpoints per entity, usage rollups by (deployment, period, range), quota evaluation server-side.

## Design Tokens
Fonts: `IBM Plex Sans` (UI: 400/500/600), `IBM Plex Mono` (400/500/600). Productive type scale used: 10–12px labels/mono captions, 13px table body, 14px UI/body, 16px card headings (600), 18px drawer titles (600), 20px modal titles (400), 24px page titles (400), 28px KPI values (400), 36px countdown (mono).

Spacing: Carbon 8px-based (4/8/12/16/24/32). Row heights: table 48px (logs 34px, quotas 52px), toolbar 48px, batch bar 40px, modal footer buttons 56px, buttons 40px (compact 32px). Radius: 0 everywhere except tags/pills (24px full) and dots/toggles (50%).

### Theme tokens (g100 dark — default / g10 light)
| Token | g100 | g10 |
|---|---|---|
| background | `#161616` | `#f4f4f4` |
| layer (cards, rows) | `#262626` | `#ffffff` |
| layer-accent / table header | `#393939` | `#e0e0e0` |
| border-subtle | `#393939` | `#e0e0e0` |
| border-strong (inputs) | `#6f6f6f` | `#8d8d8d` |
| text-primary | `#f4f4f4` | `#161616` |
| text-secondary | `#c6c6c6` | `#525252` |
| text-helper | `#8d8d8d` | `#6f6f6f` |
| text-placeholder | `#6f6f6f` | `#a8a8a8` |
| link | `#78a9ff` | `#0f62fe` |
| primary action (both) | `#0f62fe` | `#0f62fe` |
| danger (both buttons) | `#da1e28` | `#da1e28` |
| support-success | `#42be65` | `#24a148` |
| support-warning | `#f1c21b` | `#f1c21b` (warning **text** on light: `#8e6a00`) |
| support-error | `#fa4d56` | `#da1e28` |
| support-info | `#4589ff` | `#0043ce` |
| tag green bg/text | `#044317` / `#6fdc8c` | `#a7f0ba` / `#0e6027` |
| tag amber bg/text (custom) | `#5b4300` / `#f1c21b` | `#f8e6a0` / `#684e00` |
| tag red bg/text | `#750e13` / `#ff8389` | `#ffd7d9` / `#a2191f` |
| tag gray bg/text | `#525252` / `#f4f4f4` | `#e0e0e0` / `#393939` |
| tag blue (GET) bg/text | `#0043ce` / `#d0e2ff` | `#d0e2ff` / `#0043ce` |
| selected row | `#2c2c2c` | `#e0e0e0` |
| modal overlay | `rgba(10,10,10,.65)` | `rgba(22,22,22,.5)` |
| secondary button | `#6f6f6f` | `#393939` |
| chart grid | `#333333` | `#e6e6e6` |

Header (both themes): bg `#161616`, border `#393939`, text `#f4f4f4`.

Chart categorical palette (Carbon default order): `#6929c4` purple, `#1192e8` cyan, `#005d5d` teal, `#9f1853` magenta, `#fa4d56` red, `#8d8d8d` gray for "other"; 4xx `#d2a106`.

## Seed Data (coherent demo dataset)
The prototypes share one consistent dataset — reuse it for fixtures/tests. The narrative: **acme-shop** suspended 3h ago by auto-suspend over overdue INV-2026-0139 ($290, 9d); **kestrel-track** in grace (reason payment_overdue, INV-2026-0140, deadline Jun 12 09:00 UTC, 13h 42m left); **quill-cms** suspended for abuse 2d ago; **tetra-telemetry** in grace (manual, 61h 08m); **helios-meter** 124% over `requests_day` (1,240,332 / 1,000,000, policy warn, exceeded 3d, emitting 429s with `{"error":"quota_exceeded"}`). 8 clients, 15 deployments (full rows in the Deployments table of the HTML), 8 invoices, MRR $3,420, 1.66M requests/24h, 1.8% error rate. KPI cross-checks: Active 11 · Grace 2 · Suspended 2.

## Assets
- Fonts via Google Fonts (or self-hosted `@ibm/plex`): IBM Plex Sans 400/500/600, IBM Plex Mono 400/500/600.
- Icons: all icons in the mock are simple placeholder line glyphs — replace with **`@carbon/icons-react`** equivalents (search, settings, copy, view/eye, calendar, warning, etc.).
- No raster images.

## Files
- `SnakeCloud CMS Screens.dc.html` — the full gallery: all 10 sections, both modals, drawer, variations, theme tokens (in the `<style>` block) and chart data generators (in the logic class at the bottom).
- `SnakeHeader.dc.html` — top bar design reference.
- `SnakeNav.dc.html` — side navigation design reference (active-state logic + icon set).

Open the `.dc.html` files in a browser to view. The CSS custom properties defined on `body` / `body.light` in the main file are the canonical token values (also tabulated above).

## Not yet designed (build to the same system)
Subscriptions page, global Usage page, Settings (operator profile, CMS API tokens, default grace hours, notifications, theme), Create/Edit Tearsheet forms with inline validation, typed-confirmation delete modal. Follow the universal CRUD anatomy: header (title, count, Create button, filter chips) → toolbar table with sorting/filters/search/pagination/batch actions → drawer or detail page → tearsheet forms → typed-confirm deletes → toasts for every mutation.
