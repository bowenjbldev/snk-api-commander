# SnakeCloud Commander — Sistema de diseño

> Documento único de diseño del **Commander** (panel de operador de SnakeCloud).
> Recoge las **reglas de diseño**, las **páginas implementadas** y los **flujos**.
> La implementación de producción vive en `commander/` (Vite + React + TypeScript +
> IBM Carbon v11), conectada a la API real del paquete `Commander` (`src/packages/Commander`).
>
> **Histórico:** este directorio contuvo el prototipo inicial (mockups `*.dc.html` +
> handoff). Ese prototipo ya está superado; la app real lo implementa y lo evoluciona.
> Aquí queda solo la especificación viva, alineada con lo que existe en `commander/`.
>
> ⚠️ **Modelo backend actual (2026-06-24):** cliente-primero, paquetes anidados per-cliente
> (`packages/<Cliente>/<Paquete>`), subdominio = cliente + slug del paquete en el path, y
> `commander_packages` como instancia viva 1:1 por `(client_id, slug)` (sin `commander_deployments`).
> Ver [`../README.md`](../README.md). Las páginas/flujos aquí descritos ya están **alineados** a este
> modelo (la SPA se realineó el 2026-06-25; ver [`../commander/README.md`](../commander/README.md)).

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
- **ECharts 6** para gráficas canvas, con paleta basada en tokens Carbon
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
- **Enlaces** en toda la app: texto azul de enlace, **UPPERCASE** (tracking 0.3px) y
  subrayado **punteado** (offset 2px). Aplica a los enlaces propios y al componente `Link`
  de Carbon.
- **Plex Mono** para identificadores (nombres de package/subdominio, nº de factura, emails, IPs,
  secretos, rutas, versiones), toda celda numérica de tabla (alineada a la derecha, cifras
  tabulares), timestamps, etiquetas de eje y countdowns.
- **Fechas relativas** en todas partes (`3h ago`) → tooltip de hora absoluta al hover
  (`2026-06-11 14:02 UTC`). (Sin subrayado: se retiró para alinear el timestamp con las
  métricas mono del mismo estilo.)

---

## 4. Temas y tokens

Dos temas runtime-switchable, persistidos en `localStorage`, **g100 (oscuro) por defecto**.
Se aplican como zona Carbon (`cds--g100` / `cds--g10`) sobre `<body>`. El **Header siempre va
oscuro** en ambos temas (re-tematizado a `g100` con un `<Theme>` anidado, estilo IBM Cloud).
ECharts no hereda el tema de Carbon: las options se reconstruyen con la paleta activa mediante
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
  14px títulos de card (600, `.scns-card__title`), 18px títulos de side panel (600), 20px títulos de modal (400),
  24px títulos de página (400), 28px valores KPI (400), 36px countdown (mono).
- Espaciado Carbon base 8px (4/8/12/16/24/32). Padding de columna de contenido 24–32px.
- Alturas de fila: tabla 48px (logs 34px, quotas 52px dentro del detalle de package), toolbar 48px, batch bar 40px,
  footer de modal 56px, botones 40px (compactos 32px).
- Radio: 0 en todo salvo tags/pills (full round) y dots/toggles (50%).

### Formularios

- **Rejilla `.scns-form-grid` de 2 columnas fijas** (no auto-fit): `repeat(2, minmax(0,1fr))`,
  gap `16px 20px`, colapsa a 1 columna en <600px.
- **`TextArea` a fila completa.** Por norma general los multi-línea (notas/comentarios) **ocupan todo
  el ancho** de la rejilla (`grid-column: 1 / -1` vía `.scns-form-grid .cds--form-item:has(textarea)`);
  un textarea a media anchura lee apretado. Los inputs de una línea mantienen las 2 columnas.
- **Sin celdas vacías** entre los inputs de una línea: ordénalos para que las filas de 2 queden
  completas. Los **checkboxes/toggles van al final del form** (p. ej. el `Status` del cliente, tras
  Billing), no intercalados entre campos de texto.
- **Inputs sobre card/panel → token theme-aware `--scns-field`.** Carbon iguala el campo
  (`--cds-field`) al `--cds-layer` de la card, así que se funden. Usa `--scns-field` (clase scoped
  `.scns-client-form` / `.scns-create-flow`): en **oscuro** → `field-02` (más **claro**/elevado, el campo
  canónico de Carbon sobre layer-01); en **claro** → `--cds-background` (gris; el `field-02` claro
  sería blanco-sobre-blanco). Así los inputs leen como campos distintos en ambos temas siguiendo la
  capa de Carbon.
- **Edición in situ (no modal) donde la página ES el registro:** el form se renderiza siempre
  editable (inputs como vista). El guardado va en un **botón flotante** (`.scns-save-fab`, estilo FAB
  Material **con estética IBM**: cuadrado, IBM Blue, elevación sobria, abajo-derecha, fixed): **siempre
  visible** mientras haya permiso, **deshabilitado/neutro hasta que haya un cambio** y entonces pasa a
  primary activo; al guardar vuelve a neutro. **Cuando hay cambios, junto al Save aparece un botón
  `Discard` (secondary)** en el mismo FAB; sin cambios solo está el Save (deshabilitado). Sin permiso
  `update clients`, los inputs van `disabled` (solo lectura) y no se muestra el FAB. Resetea el estado del
  form al cambiar de entidad (`key={id}`). Los modales quedan para edición, confirmaciones y flujos
  compactos; las altas con muchos campos usan side panel o tearsheet.

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
| Side panel (create flows) | `CreateSidePanel` (`@carbon/ibm-products`, variante `slideIn`, sin overlay) |
| Barras de cuota | `QuotaBar` (barra 4–6px) / `ProgressBar` |
| Listas clave/valor | `StructuredList` |
| base_url | snippet inline + copiar (`CopyableCode`) |
| Tabs de detalle | `Tabs` (line) |
| Notificaciones | `ToastNotification` (success/error), `InlineNotification` (warning) |
| Gráficas | `EChart` (ECharts canvas: bar/line/heatmap, envuelto en `ChartCard`) |
| Skeletons | `DataTableSkeleton`, `SkeletonText` |
| Edición inline de cuota | `InlineEdit` custom: valor con subrayado dashed → `TextInput` con ✓ / ✕ |

Componentes reutilizables propios (`commander/src/components/`): `DataTableShell`, `Card`,
`ChartCard`, `EChart`, `CreatePanel`, `ClientCreatePanel`, `PlanCreatePanel`,
`PackageCreateTearsheet`, `InlineEdit`, `StatusTag`,
`AmberTag` / `MethodTag` (en `components/tags/`), `GraceCountdown`, `QuotaBar`, `KpiCluster`,
`CopyableCode`, `EmptyState`, `PageHeader`, `RelativeTime`, `Sparkline`, `StatusMixBar`,
`FullScreenLoading`, `CommandPalette`.

Modales (`components/modals/`, para confirmaciones, cambios de estado y edición):
`TypedConfirmModal`, `StateChangeModal`, `ChangePlanModal`, `ProvisionModal`,
`OverdueEnforcementModal`, `InvoiceFormModal`, `PlanFormModal`.

**Create flows según tamaño.** Clientes y planes usan el **side panel canónico de Carbon**
(`CreateSidePanel` de `@carbon/ibm-products`, envuelto en `CreatePanel`). La creación completa de un
package usa `PackageCreateTearsheet` (Client → Plan → Review). Las facturas usan side panel desde el
detalle de cliente y modal desde la lista; provisionar un package ya registrado usa `ProvisionModal`.

**Acción primaria de tabla → siempre a la DERECHA.** El botón de crear (Create/New) de una colección
nunca va a la izquierda intercalado entre filtros y search:
- **Páginas top-level con `PageHeader`** (Clients, Invoices…) → en el slot `primaryAction` del
  `PageHeader` (**arriba-derecha** de la página). El toolbar de la tabla queda solo para **filtros +
  search**.
- **Tablas sin `PageHeader`** (tabs de detalle, p. ej. Plans) → en el slot **`primaryAction` del
  `DataTableShell`**, que se renderiza **después del search** (lo más a la derecha). Orden del toolbar:
  `filtros → search → primaryAction`. (El slot `toolbarExtra` queda para controles auxiliares —
  dropdowns/refresh — a la izquierda del search.)
- Botón `kind="primary" size="lg"` (48px, encaja con la altura del toolbar). Carbon-canónico: la
  acción primaria es el elemento terminal del toolbar (flujo *filtra → busca → actúa*).

Convenciones del **side panel** (`components/CreatePanel.tsx` → `CreateSidePanel`):
- Variante **`slideIn`**: **sin overlay**; empuja el contenido de la página
  (`selectorPageContent="#main-content"`) a un lado, así la lista sigue visible y operable detrás.
- **Arranca bajo el header** (`inset-block-start: 3rem`, por defecto del componente).
- Es una superficie de **plano de contenido** → **sigue el tema activo** (no el g100 del header).
- **Header**: título del evento (p. ej. "Create client") + subtítulo opcional + `formTitle` (Carbon
  lo exige en create flows). **Footer**: acción primaria + `Cancel`.
- **Form a 1 columna**; inputs con fondo `--scns-field` (theme-aware, misma convención que los
  inputs sobre card, ver §3 Formularios; clase scoped `.scns-create-flow`).

**Tablas dentro de cards (`.scns-cardtable`).** Las cards "Recent …" del Dashboard (commits, requests,
invoices) comparten un mismo chrome de tabla: **header band de 36px**, **filas de 40px (Carbon `md`) de
altura FIJA** (no `min-height` — así toda fila mide igual en las tres tablas), divisores hairline e inset
de 16px. Las columnas se fijan por tabla vía `grid-template-columns` inline. El commit graph monta su
gutter SVG sobre ese mismo chrome. Cada tabla **cortada a un tope** (`slice`) — el listado completo vive
en su página (`/logs`, `/invoices`); una card con menos filas simplemente es **más baja** (sin relleno).

**Footer band (resumen).** Cada card lleva un **footer** (`.scns-cardtable__foot` / `.scns-chartcard .scns-card__footer`,
banda de 34px, texto 11px secondary, divisor arriba) que cierra la card y da un dato — nunca un "latest N"
pelado ni el conteo de registros de un chart (sería ruido); siempre algo que la tabla/forma no canta y los
KPIs no repiten:
- **tablas** → commits `latest N · M packages`; requests `latest N · over {span}` (cuánto cubre el tail =
  intensidad); invoices `N of M invoices` (señala truncado + total).
- **charts** → requests `peak {N} · {pkg} leads`; error rate `{N} 4xx · {N} 5xx` (absolutos tras el %);
  code frequency `+{adds} / −{dels} · net ±{N}`. Todo derivado de lo ya fetcheado, sin endpoint.

**Fila de cards → altura automática.** Las cards del *bottom row* del Dashboard (`.scns-dash-tables`,
grid `minmax(0,…)` + `.scns-dash-tables__col`) **crecen a su propio contenido** (`align-items: start`,
sin stretch): commits a la izquierda; requests sobre invoices a la derecha. Cada columna termina donde
termina su contenido (los bordes inferiores pueden quedar desparejos — preferido sobre estirar con hueco
vacío). El grid usa `minmax(0,…)` para que los items puedan encogerse bajo su contenido (clave para que
el canvas de ECharts se redimensione en resize en vez de clavar la columna ancha).

---

## 7. Shell global (UI)

El shell se construye con los componentes canónicos de **Carbon UI Shell** y se describe por
**reglas**, no por una lista fija de elementos (la composición concreta evoluciona):

- **Header** — masthead `Header` de 48px, **siempre oscuro (g100) en ambos temas** (patrón IBM
  Cloud, vía `<Theme>` anidado), separado del contenido por su borde inferior. El wordmark
  (`HeaderName`) enlaza al inicio. Las acciones viven en `HeaderGlobalBar` como **icon buttons**
  (`HeaderGlobalAction`) con su **tooltip embebido** —nunca tooltips *standalone* sobre el chrome,
  ni tooltip sobre información ya visible—. La búsqueda global abre la **Command Palette** (`⌘K`).
- **SideNav** — `SideNav` canónica de Carbon: persistente en pantallas anchas, colapsable (overlay)
  en estrechas; el ítem activo usa el estado *selected* propio de Carbon + `aria-current="page"`.
  Las secciones del operador y su orden viven en §8.
- **Breadcrumbs** (`Breadcrumb`) bajo el header, solo en páginas de detalle.
- **Tick global**: un único reloj de 1s (`TickProvider`) al que solo se suscriben los countdowns y
  los tiempos relativos, para no re-renderizar las tablas cada segundo.

> **Regla rectora del shell:** ante cualquier duda de estilo gana el **canon de Carbon** (componente
> + tokens) sobre una solución custom. Por capas: el **header** (shell/masthead) va oscuro en ambos
> temas; el resto —nav lateral, paneles y contenido— sigue el **tema activo**. Los bordes de las
> superficies custom usan un único token global de borde.

---

## 8. Páginas implementadas

Navegación (orden de `navConfig.ts`): **Dashboard · Clients · Packages · Invoices · Usage ·
Request Logs · Settings**.

> **Cambio de arquitectura de información respecto al prototipo:** el hub **Packages** fusiona
> lo que antes eran *Deployments* + *Catalog* y ahora absorbe **Quotas** y **Subscriptions**
> como contexto de cada package. No se mantienen rutas legacy en desarrollo.

### Login (fuera del nav)
Pantalla de acceso del operador. Email + password → token Sanctum (Bearer) guardado en
`localStorage`. Errores de credenciales en `InlineNotification`.

### Dashboard
- Title row + subline; a la derecha `updated … · auto-refresh 30s` (mono, helper, vía `TickProvider`).
- **Fila de KPIs** (`KpiCluster`, 6 tiles en grid de gap 1px — el gap muestra el borde): cada tile
  lleva **label en MAYÚSCULA tracked** (estilo label-01), **valor 32px** con cifras tabulares y un
  **icono Carbon** arriba-derecha (Active `CheckmarkOutline` verde, In grace `Time` ámbar, Suspended
  `PauseOutline`, Requests (24h) `Activity`, Error rate (24h) `Warning`, MRR `Money`). Valores con
  color semántico (grace ámbar, suspended rojo). Deltas vs 24h previas (▲/▼ verde/rojo) **solo si hay
  ventana previa** — nunca se fabrica una flecha. Las KPIs de magnitud con tendencia (Requests, Error
  rate, **MRR**) siguen el **range picker global**; MRR = MRR actual vs el de hace `range.days`
  (términos vigentes entonces), derivado de subscriptions sin endpoint.
- **Range picker global** (acción primaria arriba-derecha, `MenuButton` "Metrics range · …": 48h ·
  semana · mes · trimestre · año). Es **un único control** que maneja **los 3 charts + los KPIs con
  delta** —de ahí el label genérico "Metrics", no "Chart"—; **no** abarca las tablas de abajo (que son
  "latest", no metrics). Cada chart hace su **fetch independiente** sobre ese rango.
- **Charts** (grid 2fr/1fr) — el **subtitle de cada card es una descripción corta estática** (p. ej.
  "traffic stacked by package", "4xx · 5xx share of requests", "lines added / removed"); el rango
  **no** se repite ahí (vive en el picker). Las **legends van centradas debajo** del chart y el **eje X
  va oculto**.
  - Izquierda (principal): **"Requests"** — stacked bar por package; el chart **llena la altura del
    card** (flex, `ChartCard fill`) para alinear con la columna derecha sin hueco bajo la legend.
  - Derecha (apilados): **"Error rate"** — stacked area **4xx/5xx** (ámbar `#d2a106` / rojo `#fa4d56`),
    % de requests por clase; **"Code frequency"** — barras semanales additions (verde) / deletions (rojo).
  - Fuentes (platform-wide, en vivo): `GET /usage/platform` (`period`+`hours` derivados del rango;
    incluye `count_4xx`/`count_5xx`) para Requests + Error rate; `GET /code-frequency` (`days`) para
    Code frequency. Los counts llegan como string → se coercionan a `Number`.
- **Recent commits** (full-width, bajo los charts): tabla Carbon (mismo shape que el tab Insights del
  package + columna **Package**): Package (link) · SHA · Message · Author · When (relativo) · +/−
  (verde/rojo). Fuente `GET /commits` (commits recientes de toda la flota). La antigua fila de triage
  **"Needs attention"** se movió al **bell del header** (`NotificationsBell`) y ya no vive aquí.

### Clients (lista + detalle)
- **KPI cluster** (5 tiles, mismo patrón que Dashboard): Clients (total + active/inactive) ·
  Active (verde) · Packages provisionados · MRR (términos vigentes) · At risk (clientes con factura
  overdue, rojo). Todo derivado del store cargado — sin endpoints ni tablas nuevas.
- Tabla enriquecida (minicharts CSS/SVG ligeros — **sin instancias ECharts por fila** por el coste de
  re-layout). Columnas, ordenadas por relevancia:
  - **Client** — una sola línea: **tag de estado** del cliente (active/inactive) a la izquierda +
    nombre link en negrita. (El riesgo de impago se ve vía el KPI "At risk" y el filtro Billing.)
  - **MRR** — tag `cool-gray` `$x/mo` del término vigente excl. overdue, "—" si 0.
  - **Traffic · 24h** — `requests_24h` (suma de sus packages) + **sparkline** de la tendencia 48h
    (`Sparkline`, SVG; serie agregada de `GET /usage/platform` por `package_id → client_id`).
  - **Quota health** — peor `quota_health` entre sus packages como barra verde/ámbar/roja (reusa
    `QuotaBar`).
  - **Last seen** — `max(last_seen)` entre sus packages, relativo (`RelativeTime`), "never" si nunca.
  - (Email y Created viven en el detalle; el search sigue cubriendo email.)
- Toolbar: search (name + company + email) + filtros inline (patrón QuotasTab) **Status · Billing
  (At risk/Current) · Health (Any suspended/Any grace/All healthy)**, y **Country** solo si hay >1
  país distinto. Botón ghost **Clear filters** (icono Close) cuando algún filtro está activo.
  Primaria **Create client**.
- Click de fila (o del nombre) → **página dedicada** `/clients/:id` (`ClientDetail`), **todo en una
  página** (sin tabs): breadcrumbs (Clients / Nombre), header con nombre + status tag + "client since
  · company", **KPI cluster** (Packages · MRR · Requests 24h · Quota health), alerta de overdue si la
  hay, y un layout de **form + rail** (`.scns-client-grid`, colapsa a una columna en estrecho). Tanto
  el form como cada bloque del rail usan el componente compartido **`Card`** (`components/Card.tsx`):
  card con **header band** (mismo fondo que el body, separado por un hairline — `.scns-card__header`/
  `__body`) y título 16px bold dentro del header: **Details** · **Packages ·N** · **Invoices ·N**.
  (Sustituye al antiguo drawer + modal de edición.)
  - **KPI cluster** con iconos + tinte semántico (como Dashboard/Clients): Packages (`Box`) · MRR
    (`Money`) · Requests·24h (`Activity`) · Quota health (`MeterAlt`, icono y valor en verde/ámbar/
    rojo según el peor ratio).
  - **Izquierda (card "Details") — vista + edición a la vez (sin modal, sin botón "Editar"):** un
    **formulario siempre editable** (`ClientEditForm`, inputs Carbon en rejilla 2-col
    `.scns-form-grid` sin celdas vacías — ver §3 Formularios — con sub-labels internos Contact +
    Billing). Inputs con fondo `--scns-field` (theme-aware, ver §3) vía `.scns-client-form`.
    Guardado mediante el **botón flotante** `.scns-save-fab` (ver §3 Formularios). Validaciones:
    name/email requeridos, email/billing válidos, country ISO-2. Sin permiso `update clients` los
    inputs quedan `disabled` (solo lectura). Se re-inicializa al navegar entre clientes
    (`key={client.id}`).
  - **Rail derecho (`.scns-client-rail`):** dos cards apiladas — **Packages** e **Invoices** — con el
    **mismo chrome `.scns-cardtable` que las cards del Dashboard** (header band, filas 40px, divisores,
    `bodyPadding={0}`, footer de count vía `CardTableFooter`) + kebab "View all" en el header. Packages:
    Package (link a `/packages/:id`, fila clicable) · Plan · MRR · Status. Invoices: Invoice · Due ·
    Amount · Status (`BillingTag`).
  - La creación desde la lista usa `ClientCreatePanel`; la edición permanece in situ.
  - **Acciones:** el **`MenuButton` "Actions"** del header ofrece New invoice y Delete client (`stub`).
    New invoice abre `InvoiceFormModal` como side panel con el cliente prefijado y bloqueado. El **Details no lleva
    kebab**: el `Save` vive en el FAB y, con cambios, aparece a su lado un `Discard` (secondary). Las cards
    **Packages**/**Invoices** llevan un kebab "⋮" solo con **View all**. El header de card usa
    `align-items: center` para alinear el kebab con el título.
- Enlaces a cliente (CommandPalette, OverviewTab del package) apuntan a `/clients/:id`.
- Derivaciones por cliente (sin endpoints/tablas nuevas): MRR = `price_monthly` de subscriptions
  `ended_at == null` excl. `billing_status == 'overdue'` (idéntico al Dashboard); Traffic 24h = suma
  de `requests_24h`; status mix / worst `quota_health` / `max(last_seen)` recorriendo sus packages;
  sparkline 48h agregando los buckets de rollup de `/usage/platform`.
- Alta vía **`ClientCreatePanel`** (side panel, ver §6); edición in situ en `/clients/:id`.

### Packages (hub central) + detalle con tabs
- **Lista de packages** (`Package`): filas registradas en `commander_packages`, creadas por
  provisioning y conservadas como catálogo tras de-provisionar. La API no inserta desde disco: solo
  enriquece cada fila con `on_disk` (derivado de `packages/*/*`) y el conteo de planes. Cada fila lleva
  metadatos de catálogo y —si está provisionada— lo operativo de su instancia viva. El alta completa
  usa `PackageCreateTearsheet` (cliente, plan y revisión).
- **`/packages/:id`** → `PackageDetail` con `Tabs` (line) y `<Outlet context>` (la ruta direcciona
  por id numérico — patrón unificado con `/clients/:id` — pero internamente resuelve `pkg.name`, que
  es la clave de negocio que usan la API y el store):
  - **Overview** — formulario editable de metadatos de catálogo; si hay instancia viva, banner
    condicional para grace/suspended y rail con cliente, suscripción, fechas, `base_url` y resumen de
    cuotas. Si no hay instancia viva, el empty state muestra la **CTA de Provision**.
  - **Plans** — catálogo de planes del package (precio + límites de cuota por defecto). Alta vía
    `PlanCreatePanel`; edición vía `PlanFormModal`.
  - **Quotas** — límites reales/editables del package provisionado: `requests_day`,
    `disk_db_mb`, `disk_storage_mb`, `users_total`, uso actual, límite inline, política
    `none`/`warn`/`suspend` y fecha de exceso.
  - **Usage** — KPIs + gráficas 2×2 (requests, error rate, latency avg/p95, transfer) desde
    `GET /packages/{name}/usage` (rollups reales). Export CSV.
  - **Logs** — request logs reales del package (`GET /packages/{name}/logs`), tabla densa.
  - **Settings** — configuración del package/instancia.

### Invoices (+ flujo de enforcement)
- Subline destaca el total rojo de overdue. Tabla: Number (mono link) · Client · Package ·
  Amount · Issued · Due (overdue en rojo con retraso; late-pending en ámbar) · Status · ⋮.
  Filas overdue muestran el estado de enforcement (`→ grace` / `→ suspended`).
- Overflow: Open / Edit / Mark as paid / Mark as overdue… / Delete.
- Alta/edición vía `InvoiceFormModal`: modal desde la lista y side panel al crear desde el detalle
  de cliente. Ver flujo de enforcement en §10.

### Usage (plataforma)
Vista agregada de toda la flota desde `GET /usage/platform` (suma de `commander_usage_rollups`
por package/bucket en vivo, sin tabla de caché): stacked bar de requests + error rate.

### Request Logs (explorer, read-only)
- Fetch bajo demanda, sin websocket; botón Refresh y filtro opcional por package.
- Search local por package/método/ruta/status/IP.
- Tabla: time · package (oculto al filtrar uno) · method · route · status color-coded · ms · IP.

### Settings
Perfil del operador + preferencias de plataforma (singleton, persistido en servidor):
`default_grace_hours` (alimenta el grace real), `operator_name`, `operator_email`,
`notify_on_suspend`, `notify_on_overdue`. El tema se persiste aparte en `localStorage`.

### NotFound
404 dentro del shell para rutas desconocidas.

---

## 9. Estado y datos (producción)

- **Sin datos demo.** El store de `zustand` arranca vacío; `loadData()` carga en paralelo
  clients, subscriptions, invoices, quotas, plans, packages, activity y settings
  desde la API (los packages vía `packagesApi.list()`). El registro se puebla por provisioning y
  el CRUD del panel.
- **Tipos = tablas reales.** `commander/src/store/types.ts` espeja `commander_*`
  (`commander_packages`, `commander_quotas`, `commander_usage_rollups`,
  `commander_request_logs`, …). Cada registro es una fila `Package` de `commander_packages`, creada
  por provisioning: catálogo (label/description/listing_status) + operativo (slug/estado/KPIs/
  `client_id`). Está provisionado cuando **`provisioned_at != null`**; el de-provision conserva la
  fila sin sus columnas operativas. No hay objeto anidado, colección `deployments` ni registro aparte.
- **Mutaciones optimistas + reconcile.** Cada acción aplica el cambio local al instante y dispara
  la llamada API; al éxito reconcilia con la entidad del servidor (autoritativo); al fallo,
  muestra toast y **re-sincroniza** con `loadData()`. Las acciones de estado por package (keyed
  por nombre) usan un **seq guard** para tolerar respuestas fuera de orden.
- **Reconcile por handle legible** cuando el id real lo asigna el servidor: clientes por `slug`,
  facturas por `number` (`INV-YYYY-NNNN`), planes por `slug` (id temporal negativo mientras tanto).
- **Auth:** store separado (`auth.ts`). Bearer token en `localStorage`; en boot valida vía
  `/auth/me`; cualquier 401 de la API limpia la sesión. **Gating de permisos** en UI con
  `useCan()` (los nombres coinciden con `CommanderDatabaseSeeder`); el backend sigue siendo
  autoritativo (cada controller hace `requirePermission` → 403).

---

## 10. Flujos (reglas de UX no negociables)

### Máquina de estados de un package
Transiciones: `active→grace`, `active→suspended`, `grace→suspended` (deadline o manual),
`grace→active`, `suspended→active`. **Cada transición escribe un evento en el activity feed.**
Las acciones suspend/grace/resume escriben in-process la fila `commander_packages`
(`POST /packages/{name}/{suspend|grace|resume}`).

- **Suspend** (`StateChangeModal` danger): reason requerido (`payment_overdue`/`abuse`/`manual`)
  + mensaje client-facing (se devuelve en el 402). **No** requiere confirmación tipeada (es
  reversible). La API deja de servir → los usuarios reciben **HTTP 402**.
- **Grace**: mismo modal con duración basada en **default_grace_hours** (Settings, 72h).
  Countdown vivo en los `PackageStatusTag`.
- **Resume**: vuelve a `active`.
- Disponible desde el menú Actions del detalle y los follow-up de facturas; ambos reutilizan los
  mismos modales.

### Provisioning (client-first, un paso, in-process)
`PackageCreateTearsheet` crea primero el plan y luego llama a `POST /provisioning`; `ProvisionModal`
reutiliza el mismo endpoint para un package/plan ya registrado. El payload lleva `plan_id` + cliente
(nuevo o existente). El `slug` del cliente **ES su host** (no hay parámetro de subdominio aparte). El servidor crea cliente +
suscripción + **instancia viva** (crea la BD, migra, siembra y activa el package) atómicamente; al
éxito el panel hace `loadData()`. Sin paso de host.

### Facturación y enforcement
- **Mark as paid**: si el package estaba `suspended` por `payment_overdue`, el follow-up de
  éxito ofrece **Resume package**.
- **Mark as overdue**: dispara `OverdueEnforcementModal` (3 botones): **Keep running**
  (secundaria) / **Grace 72h** (primaria, aplica el deadline por defecto) / **Suspend now**
  (danger). La elección escribe el `enforcement` de la factura y el estado del package.

### Acciones destructivas / irreversibles
De-provisionar un package y borrar una factura requieren **confirmación tipeada**
(`TypedConfirmModal`: escribe el identificador para habilitar el botón danger).

### Reglas globales
- **Toda mutación** lleva estado optimista/loading + `ToastNotification`; al fallo se resincroniza
  el store con la API.
- **Las tablas nunca saltan al cargar**: skeleton rows con la misma altura que las reales.
- **Los countdowns de grace tictean en vivo** dentro de `PackageStatusTag`.
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
| Provisioning | `POST provisioning` (client-first, un paso) → `{ package }` |
| Estado de package | `POST packages/{name}/{suspend\|grace\|resume}` |
| Cambio de plan | `POST packages/{name}/change-plan` |
| Uso / logs por package | `GET packages/{name}/usage` · `GET packages/{name}/logs` |
| Insights por package | `GET packages/{name}/insights` |
| Subscriptions / Invoices | `apiResource` |
| Quotas | `apiResource quotas` (index/show/update) |
| Activity | `apiResource activity` (index/show) |
| Usage plataforma | `GET usage/platform` (`period`+`hours`; incluye `count_4xx`/`count_5xx`) |
| Logs plataforma | `GET logs` (filtros package/method/status) |
| Commits plataforma | `GET commits` (`limit`) — Dashboard "Recent commits" |
| Code frequency plataforma | `GET code-frequency` (`days`) — Dashboard "Code frequency" |
| Packages (registro) | `GET packages` · `GET\|PATCH\|DELETE packages/{name}` |
| Settings (singleton) | `GET settings` · `PUT settings` |

Cliente HTTP del panel: `commander/src/lib/api.ts` (fetch wrapper, Bearer, `ApiError`,
handler de 401). En dev el SPA llama a `/api/v1` (proxy de Vite → backend, sin CORS); en
producción se fija `VITE_API_BASE` al origen del deploy.
