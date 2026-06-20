# SnakeCloud Commander — Sistema de diseño

> Documento único de diseño del **Commander** (panel de operador de SnakeCloud).
> Recoge las **reglas de diseño**, las **páginas implementadas** y los **flujos**.
> La implementación de producción vive en `commander/` (Vite + React + TypeScript +
> IBM Carbon v11), conectada a la API real del paquete `Commander` (`src/packages/Commander`).
>
> **Histórico:** este directorio contuvo el prototipo inicial (mockups `*.dc.html` +
> handoff). Ese prototipo ya está superado; la app real lo implementa y lo evoluciona.
> Aquí queda solo la especificación viva, alineada con lo que existe en `commander/`.

---

## 1. Qué es el Commander

Panel de operador **mono-usuario** para un desarrollador que vende despliegues de API REST
por suscripción. Es a la vez:

- **Mando a distancia** de cada instancia: `suspend` / `grace` / `resume`.
- **Libro de negocio**: clientes, suscripciones, facturas, uso, cuotas y request logs.

Corre en `commander.<BASE_DOMAIN>` sobre la BD `commander` (in-process con el backend: misma
app y misma BD que el registro `commander_*`). Un token de tenant no entra aquí (aislamiento por BD).

---

## 2. Stack y principios técnicos

- **React 19 · React Router 7 · TypeScript 5.9 · Vite 7**
- **IBM Carbon Design System v11**: `@carbon/react`, `@carbon/styles`, `@carbon/icons-react`
- **`@carbon/charts-react`** para gráficas (paleta categórica Carbon por defecto)
- **`zustand`** para el estado (store de datos + store de auth)
- **IBM Plex Sans / IBM Plex Mono** self-hosted vía `@ibm/plex`

Principios:

- **Componentes Carbon reales + tokens**, no CSS a mano. Donde el default de Carbon difiera
  ligeramente del diseño, gana el default de Carbon manteniendo estructura y semántica.
- **Desktop-first**, ancho de referencia 1440px, responsive hasta tablet (las tablas pueden
  hacer scroll horizontal).
- **Code-splitting**: el Dashboard es eager; el resto de rutas son `React.lazy`.

---

## 3. Arte / dirección visual

- Estética **enterprise sobria, de ingeniería**.
- **Esquinas rectas en todo el sistema.** El único radio existe en tags/pills (full round)
  y en dots/toggles redondos. Nada más lleva radio.
- **1px de borde** en cada frontera de capa (`border-subtle`).
- **UI monocromática**: el color aparece SOLO como semántica de estado (verde/ámbar/rojo),
  azul de enlace, la **única acción primaria por pantalla** (IBM Blue 60 `#0f62fe`) y las
  series de las gráficas.
- **Plex Mono** para identificadores (nombres de deployment, nº de factura, emails, IPs,
  secretos, rutas, versiones), toda celda numérica de tabla (alineada a la derecha, cifras
  tabulares), timestamps, etiquetas de eje y countdowns.
- **Fechas relativas** en todas partes (`3h ago`) con subrayado punteado → tooltip de hora
  absoluta al hover (`2026-06-11 14:02 UTC`).

---

## 4. Temas y tokens

Dos temas runtime-switchable, persistidos en `localStorage`, **g100 (oscuro) por defecto**.
Se aplican como zona Carbon (`cds--g100` / `cds--g10`) sobre `<body>`. El **Header siempre va
oscuro** en ambos temas (re-tematizado a `g100` con un `<Theme>` anidado, estilo IBM Cloud).
Las gráficas no siguen el tema de Carbon: las options llevan `theme` explícito reconstruido con
`useMemo`.

| Token | g100 (oscuro) | g10 (claro) |
|---|---|---|
| background | `#161616` | `#f4f4f4` |
| layer (cards, filas) | `#262626` | `#ffffff` |
| layer-accent / header de tabla | `#393939` | `#e0e0e0` |
| border-subtle | `#393939` | `#e0e0e0` |
| border-strong (inputs) | `#6f6f6f` | `#8d8d8d` |
| text-primary | `#f4f4f4` | `#161616` |
| text-secondary | `#c6c6c6` | `#525252` |
| text-helper | `#8d8d8d` | `#6f6f6f` |
| text-placeholder | `#6f6f6f` | `#a8a8a8` |
| link | `#78a9ff` | `#0f62fe` |
| primary action (ambos) | `#0f62fe` | `#0f62fe` |
| danger (ambos) | `#da1e28` | `#da1e28` |
| support-success | `#42be65` | `#24a148` |
| support-warning | `#f1c21b` | `#f1c21b` (texto warning en claro: `#8e6a00`) |
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

Header (ambos temas): bg `#161616`, borde `#393939`, texto `#f4f4f4`.

**Paleta categórica de gráficas** (orden Carbon): `#6929c4` púrpura, `#1192e8` cyan,
`#005d5d` teal, `#9f1853` magenta, `#fa4d56` rojo, `#8d8d8d` gris para "other"; 4xx `#d2a106`.

### Tipografía y espaciado

- Fuentes: **IBM Plex Sans** (UI: 400/500/600), **IBM Plex Mono** (400/500/600).
- Escala (productive): 10–12px labels/captions mono, 13px cuerpo de tabla, 14px UI/body,
  16px títulos de card (600), 18px títulos de drawer (600), 20px títulos de modal (400),
  24px títulos de página (400), 28px valores KPI (400), 36px countdown (mono).
- Espaciado Carbon base 8px (4/8/12/16/24/32). Padding de columna de contenido 24–32px.
- Alturas de fila: tabla 48px (logs 34px, quotas 52px), toolbar 48px, batch bar 40px,
  footer de modal 56px, botones 40px (compactos 32px).
- Radio: 0 en todo salvo tags/pills (full round) y dots/toggles (50%).

---

## 5. Semántica de estado (transversal)

| Estado | Tag | Etiqueta |
|---|---|---|
| `active` | green | "Operational" |
| `grace` | amber (custom) | "Grace · 13h 42m" — **countdown vivo** al deadline |
| `suspended` | red | "Suspended" — los usuarios de esa API reciben HTTP 402 |
| Billing `paid` | green | "Paid" |
| Billing `pending` | amber | "Pending" |
| Billing `overdue` | red | "Overdue" + retraso relativo en la columna Due |

> Carbon no tiene tag amarillo: `grace`/`pending` usan un **tag ámbar custom** (`AmberTag`).
> Métodos HTTP: GET azul, POST verde (`MethodTag`).

---

## 6. Mapa de componentes (Carbon)

| Pieza | Componente |
|---|---|
| Barra superior | `Header`, `HeaderName`, `HeaderGlobalBar`, `HeaderGlobalAction` |
| Navegación lateral | `SideNav`, `SideNavItems`, `SideNavLink` |
| Tablas | `DataTable` + `TableToolbar(Search)`, `TableBatchActions`, `TableSelectRow`, `Pagination` (envueltas en `DataTableShell`) |
| Filtros de toolbar | `Dropdown` / `MultiSelect` inline |
| Status pills | `Tag` (`green`/`red`) + `AmberTag` custom para grace/pending |
| Modales danger / follow-up | `Modal` (danger), `ComposedModal` (3 botones) |
| Quick view de cliente | `Drawer` custom (424px) — Carbon no tiene drawer |
| Barras de cuota | `QuotaBar` (barra 4–6px) / `ProgressBar` |
| Listas clave/valor | `StructuredList` |
| base_url / secret | `CodeSnippet` inline + copiar (`CopyableCode`), eye-toggle para revelar |
| Tabs de detalle | `Tabs` (line) |
| Notificaciones | `ToastNotification` (success/error), `InlineNotification` (warning) |
| Gráficas | `@carbon/charts-react`: StackedBar / Line / Area (envueltas en `ChartCard`) |
| Skeletons | `DataTableSkeleton`, `SkeletonText` |
| Edición inline de cuota | `InlineEdit` custom: valor con subrayado dashed → `TextInput` con ✓ / ✕ |

Componentes reutilizables propios (`commander/src/components/`): `DataTableShell`, `Drawer`,
`InlineEdit`, `StatusTag`, `AmberTag`, `MethodTag`, `GraceCountdown`, `QuotaBar`, `KpiCluster`,
`CopyableCode`, `ChartCard`, `EmptyState`, `StateCard`, `PageHeader`, `RelativeTime`,
`FullScreenLoading`, `CommandPalette`.

Modales (`components/modals/`): `TypedConfirmModal`, `StateChangeModal`, `ProvisionModal`,
`OverdueEnforcementModal`, `ClientFormModal`, `InvoiceFormModal`, `PlanFormModal`.

---

## 7. Shell global (UI)

- **Header** (Carbon `Header`, 48px, siempre g100):
  - Wordmark `SnakeCloud` (Plex Sans 600) + `CMS`/`Commander` (400, `#c6c6c6`), 14px.
  - Búsqueda global → **Command Palette** (`⌘K`) sobre packages/clients/invoices.
  - Cluster derecho: chip de entorno `PROD` (Plex Mono 10px), estado de conexión (dot verde
    + "Connected"), toggle de tema, divisor 1px, avatar (inicial del operador).
- **SideNav** (Carbon `SideNav`, fija, 256px, tematizada): los **9 ítems** en orden de diseño
  (ver §8). Activo: borde izquierdo 4px IBM Blue 60, fondo de capa, peso 600. Footer con la
  versión `SnakeCloud Commander v1.14.2` (Plex Mono).
- **Breadcrumbs** (Carbon `Breadcrumb`) bajo el header, solo en páginas de detalle:
  `Packages / shop`.
- **Tick global**: un único reloj de 1s (`TickProvider`); solo `GraceCountdown` y `RelativeTime`
  se suscriben, así las tablas no re-renderizan cada segundo.

---

## 8. Páginas implementadas

Navegación (orden de `navConfig.ts`): **Dashboard · Clients · Packages · Subscriptions ·
Invoices · Usage · Quotas · Request Logs · Settings**.

> **Cambio de arquitectura de información respecto al prototipo:** el hub **Packages** fusiona
> lo que antes eran *Deployments* + *Catalog*. Las rutas antiguas `/deployments`,
> `/deployments/:name` y `/catalog` **redirigen a `/packages`** (back-compat).

### Login (fuera del nav)
Pantalla de acceso del operador. Email + password → token Sanctum (Bearer) guardado en
`localStorage`. Errores de credenciales en `InlineNotification`.

### Dashboard
- Title row + subline de fecha; a la derecha `updated … · auto-refresh` (mono, helper).
- **Fila de KPIs** (`KpiCluster`): tiles en grid de gap 1px (el gap muestra el borde). Valores
  con color semántico (In grace ámbar, Suspended rojo) y deltas (verde/rojo). Las dos KPIs
  reales (requests 24h y MRR) se computan de los datos en vivo; deltas/error-rate sintéticos.
- **Attention required**: lista bordeada con severidad (QUOTA OVER / GRACE ENDS / OVERDUE /
  GRACE) + enlace a la entidad + deep-link a la derecha.
- **Charts** (grid 2fr/1fr): "Requests per hour" (stacked bar por deployment) + "Error rate"
  (línea roja + área).
- **Recent activity**: lista con dot de estado + frase con enlaces mono + timestamp relativo.

### Clients (lista + drawer)
- Tabla: Name (link) · Company · Email (mono) · Deployments (count) · Status tag · Created.
  Toolbar con search + filtro de estado. Primaria **Create client**.
- Click de fila → **Drawer** (424px): contacto (email/phone/notes), bloque de alerta con borde
  rojo si hay factura overdue, mini-lista de deployments, atajo "New deployment for this client"
  (pre-rellena el cliente en el provisioning), footer Edit / Open full record.
- Alta/edición vía `ClientFormModal`.

### Packages (hub central) + detalle con tabs
- **Lista de packages** (`RegisteredPackage`): el registro de tipos de negocio, sincronizado
  desde disco (`packages/*`) y enriquecido por la API con `on_disk`, conteo de deployments y de
  planes. Metadatos editables (label/description/status).
- **`/packages/:name`** → `PackageDetail` con `Tabs` (line) y `<Outlet context>`:
  - **Overview** — identidad + estado del deployment (State card con countdown de grace),
    details (StructuredList: cliente, package, suscripción, created, last seen, `base_url`),
    control secret (snippet enmascarado + rotar), cuotas (gauges).
  - **Plans** — catálogo de planes del package (precio + límites de cuota por defecto). CRUD
    vía `PlanFormModal`.
  - **Deployments** — la(s) instancia(s) del package: estado, acciones suspend/grace/resume,
    quota health, last seen.
  - **Usage** — KPIs + gráficas 2×2 (requests, error rate, latency avg/p95, transfer) desde
    `GET /deployments/{id}/usage` (rollups reales). Export CSV.
  - **Logs** — request logs reales del package (`GET /deployments/{id}/logs`), tabla densa.
  - **Settings** — configuración del package/instancia.

### Subscriptions
Lista de suscripciones (cliente, deployment, plan, precio mensual, billing status, auto-suspend).

### Invoices (+ flujo de enforcement)
- Subline destaca el total rojo de overdue. Tabla: Number (mono link) · Client · Deployment ·
  Amount · Issued · Due (overdue en rojo con retraso; late-pending en ámbar) · Status · ⋮.
  Filas overdue muestran el estado de enforcement (`→ grace` / `→ suspended`).
- Overflow: Open / Edit / Mark as paid / Mark as overdue… / Delete.
- Alta/edición vía `InvoiceFormModal`. Ver flujo de enforcement en §10.

### Usage (plataforma)
Vista agregada de toda la flota desde `GET /usage/platform` (snapshot cache local por
deployment/hora): stacked bar de requests + error rate.

### Quotas (inline editable)
- Subline documenta el comportamiento; pills resumen `N OVER` (rojo) + `N ≥ 80%` (ámbar).
- Tabla (52px): Deployment · Metric (`requests_day`, `disk_db_mb`, `disk_storage_mb`,
  `users_total`) · Current usage (barra 200px + valor + % — OVER en rojo con pill `OVER 124%`) ·
  **Limit** (editable inline; vacío = "Unlimited") · On exceed (select inline `none`/`warn`/
  `suspend`) · Exceeded since (relativo rojo o `—`).
- `InlineEdit`: valor → field con borde inferior 2px Blue 60 + ✓/✕; Enter/✓ commit, Esc/✕
  cancela; toast al guardar.

### Request Logs (explorer, read-only)
- Header: "raw request log · read-only". (Sin stream en tiempo real: se hace fetch on demand /
  poll, no websocket.)
- Toolbar: search de ruta, filtros Deployment/Method, rango de tiempo, chips de clase de estado
  `2xx 3xx 4xx 5xx`.
- Tabla densa (34px, todo Plex Mono 11.5px): time (UTC ms) · deployment · method (GET/POST) ·
  route · status color-coded (2xx verde, 3xx gris, 4xx ámbar, 5xx rojo) · ms · user · bytes.
- Fila seleccionada → **panel de detalle** (derecha) con todos los campos + body de respuesta
  (JSON) + bloque de contexto enlazando a la causa (cuota, etc.).

### Settings
Perfil del operador + preferencias de plataforma (singleton, server-persistido):
`default_grace_hours` (alimenta el grace real), `operator_name`, `operator_email`,
`notify_on_suspend`, `notify_on_overdue`, tema.

### NotFound
404 dentro del shell para rutas desconocidas.

---

## 9. Estado y datos (producción)

- **Sin datos demo.** El store de `zustand` arranca vacío; `loadData()` carga en paralelo
  clients, deployments, subscriptions, invoices, quotas, plans, packages, activity y settings
  desde la API. El registro se puebla por provisioning y el CRUD del panel.
- **Tipos = tablas reales.** `commander/src/store/types.ts` espeja `commander_*`
  (`commander_packages`, `commander_quota_metrics`, `commander_usage_rollups`,
  `commander_request_logs`, …). El estado runtime del deployment llega **anidado** en
  `deployment.package` (fuente de verdad, sin columnas planas duplicadas).
- **Mutaciones optimistas + reconcile.** Cada acción aplica el cambio local al instante y dispara
  la llamada API; al éxito reconcilia con la entidad del servidor (autoritativo); al fallo,
  muestra toast y **re-sincroniza** con `loadData()`. Las acciones de estado por deployment usan
  un **seq guard** para tolerar respuestas fuera de orden.
- **Reconcile por handle legible** cuando el id real lo asigna el servidor: clientes por `slug`,
  facturas por `number` (`INV-YYYY-NNNN`), planes por `slug` (id temporal negativo mientras tanto).
- **Auth:** store separado (`auth.ts`). Bearer token en `localStorage`; en boot valida vía
  `/auth/me`; cualquier 401 de la API limpia la sesión. **Gating de permisos** en UI con
  `useCan()` (los nombres coinciden con `CommanderDatabaseSeeder`); el backend sigue siendo
  autoritativo (cada controller hace `requirePermission` → 403).

---

## 10. Flujos (reglas de UX no negociables)

### Máquina de estados de un deployment
Transiciones: `active→grace`, `active→suspended`, `grace→suspended` (deadline o manual),
`grace→active`, `suspended→active`. **Cada transición escribe un evento en el activity feed.**
Las acciones suspend/grace/resume escriben in-process la fila `commander_packages`
(`POST /deployments/{id}/{suspend|grace|resume}`).

- **Suspend** (`StateChangeModal` danger): reason requerido (`payment_overdue`/`abuse`/`manual`)
  + mensaje client-facing (se devuelve en el 402). **No** requiere confirmación tipeada (es
  reversible). La API deja de servir → los usuarios reciben **HTTP 402**.
- **Grace**: mismo modal + datetime picker con default **+default_grace_hours** (Settings, 72h).
  Countdown vivo en tag de tabla y en la State card del detalle.
- **Resume**: vuelve a `active`.
- Disponible desde: overflow de fila, batch bar, header de detalle, State card y los follow-up de
  facturas — **todos reutilizan los mismos modales**.

### Provisioning (client-first, un paso, in-process)
`ProvisionModal` → `POST /provisioning` con `plan_id` + cliente (nuevo o existente) +
subdominio opcional. El servidor crea cliente + suscripción + **instancia viva** (crea la BD,
migra, siembra y activa el package) atómicamente; al éxito el panel hace `loadData()`. Sin paso
de host.

### Facturación y enforcement
- **Mark as paid**: si el deployment estaba `suspended` por `payment_overdue`, el follow-up de
  éxito ofrece **Resume deployment**.
- **Mark as overdue**: dispara `OverdueEnforcementModal` (3 botones): **Keep running**
  (secundaria) / **Grace 72h** (primaria, aplica el deadline por defecto) / **Suspend now**
  (danger). La elección escribe el `enforcement` de la factura y el estado del deployment.

### Acciones destructivas / irreversibles
Borrar entidad y **rotar secreto** requieren **confirmación tipeada** (`TypedConfirmModal`:
escribe el nombre para habilitar el botón danger).

### Reglas globales
- **Toda mutación** lleva estado optimista/loading + `ToastNotification` (éxito **y** fallo con
  enlace de reintento).
- **Las tablas nunca saltan al cargar**: skeleton rows con la misma altura que las reales.
- **Los countdowns de grace tictean en vivo** (tag en tablas, countdown gigante en detalle).
- **Fechas relativas** con absoluta al hover.
- **Accesibilidad**: contraste WCAG AA en ambos temas, focus ring visible (Carbon 2px Blue 60),
  navegación completa por teclado, `aria-sort` en cabeceras ordenables, `aria-live` para toasts.
- **Command Palette** con `⌘K` sobre packages/clients/invoices.

---

## 11. Correspondencia con la API (backend `Commander`)

Rutas reales (`src/packages/Commander/routes/api.php`, todas bajo `auth:sanctum` salvo login):

| Recurso | Endpoint |
|---|---|
| Auth | `POST auth/login` (throttle) · `GET auth/me` · `POST auth/logout` |
| Clients / Plans / Users | `apiResource clients`, `plans`, `users` |
| Provisioning | `POST provisioning` (client-first, un paso) |
| Estado de deployment | `POST deployments/{id}/{suspend\|grace\|resume}` |
| Uso / logs por deployment | `GET deployments/{id}/usage` · `GET deployments/{id}/logs` |
| Deployments / Subscriptions / Invoices | `apiResource` |
| Quotas | `apiResource quotas` (index/show/update) |
| Activity | `apiResource activity` (index/show) |
| Usage plataforma | `GET usage/platform` |
| Logs plataforma | `GET logs` (filtros deployment/method/status) |
| Packages (registro) | `GET packages` · `GET packages/{package}` · `PATCH packages/{package}` |
| Settings (singleton) | `GET settings` · `PUT settings` |

Cliente HTTP del panel: `commander/src/lib/api.ts` (fetch wrapper, Bearer, `ApiError`,
handler de 401). En dev el SPA llama a `/api/v1` (proxy de Vite → backend, sin CORS); en
producción se fija `VITE_API_BASE` al origen del deploy.
