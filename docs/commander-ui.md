# Commander UI

## Propósito

`commander/` es la SPA del operador de SnakeCloud. Controla clientes, productos, instancias,
planes, suscripciones, facturas, cuotas, uso y estado. No genera código ni despliega contenedores;
opera sobre la API real de `src/packages/Commander`.

En producción se sirve en `commander.<BASE_DOMAIN>`. En desarrollo, Vite usa una API
same-origin `/api/v1` y la proxifica al backend.

## Stack

- React 19, React Router 7, TypeScript 5.9 y Vite 7.
- IBM Carbon Design System v11 y `@carbon/ibm-products`.
- ECharts 6 para gráficos canvas.
- Zustand para datos y autenticación.
- IBM Plex Sans y Plex Mono self-hosted.
- Vitest, Testing Library y jsdom.

Comandos:

```bash
cd commander
npm install
npm run dev
npm test
npm run build
npm run lint
```

## Arquitectura del frontend

```text
src/
├── app/             shell, header, side nav, breadcrumbs y avisos
├── components/      primitivas reutilizables, tablas, gráficas, paneles y modales
├── hooks/           carga, first-load y focus trap
├── lib/api.ts       fetch tipado, Bearer, ApiError y handler de 401
├── lib/             formato, tiempo, charts y helpers de dominio
├── pages/           páginas y tabs de detalle
├── providers/       acciones, tema, reloj y toasts
├── store/           auth, datos, tipos, transiciones y fixtures de test
├── styles/          Carbon, tokens y estilos compartidos
└── router.tsx       tabla de rutas lazy
```

Dashboard se carga eager; el resto de páginas usa `React.lazy`. El shell contiene el fallback de
Suspense.

## Datos y autenticación

El store arranca vacío. `loadData()` obtiene en paralelo clientes, paquetes, suscripciones,
facturas, cuotas, planes, actividad, commits y settings. Los fixtures solo se usan en tests.

El login de operador devuelve un token Sanctum Bearer, guardado en `localStorage`. Al arrancar se
valida con `/auth/me`; cualquier `401` limpia la sesión. `useCan()` oculta acciones sin permiso,
pero es solo UX: cada controller vuelve a comprobar el permiso y el backend decide.

Las mutaciones son optimistas con reconciliación:

1. el store aplica el cambio local;
2. llama a la API;
3. si responde bien, sustituye con la entidad autoritativa;
4. si falla, muestra toast y recarga datos.

Las operaciones de instancia usan su id numérico y un sequence guard para ignorar respuestas
antiguas que lleguen fuera de orden. Entidades recién creadas se reconcilian por handle mientras
la BD asigna el id: cliente por `slug`, plan por `slug`, factura por `number`.

### Forma de Package

`Package` refleja una fila de `commander_packages`:

- catálogo: `name`, `label`, `description`, `listing_status`, `on_disk`, `plan_count`;
- pendiente: `client_id` presente y `provisioned_at` nulo;
- viva: `provisioned_at` presente, cliente, suscripción, estado y KPIs;
- de-provisionada: conserva la fila pero vuelve a columnas operativas nulas.

No existe `Deployment`, `subdomain` en la instancia ni un objeto anidado separado. El host se
deriva de `package.client.slug`; el producto del path es `package.slug`.

## Rutas y páginas

Navegación: Dashboard, Clients, Packages, Invoices, Usage, Request Logs y Settings.

| Ruta | Función |
|---|---|
| `/dashboard` | KPIs, requests, error rate, frecuencia de código y actividad reciente |
| `/clients` | clientes con MRR, tráfico, cuota y riesgo |
| `/clients/:id` | ficha editable, instancias y facturas del cliente |
| `/packages` | catálogo, pending y flota viva; CTA **Provision instance** |
| `/packages/:id/overview` | metadatos, estado, cliente, plan, URL y cuotas |
| `/packages/:id/plans` | planes del tipo lógico de producto |
| `/packages/:id/quotas` | uso, límite y política de la instancia |
| `/packages/:id/usage` | rollups, latencia, errores y transferencia |
| `/packages/:id/logs` | logs de request de la instancia |
| `/packages/:id/insights` | heatmap Git, code frequency y commits |
| `/packages/:id/settings` | acciones/configuración contextual |
| `/invoices` | ledger y enforcement por impago |
| `/usage` | agregados de toda la plataforma |
| `/request-logs` | explorador read-only |
| `/settings` | perfil y preferencias del operador |

`ProvisionModal` recibe un plan ya creado, cliente nuevo o existente y las credenciales del admin
tenant. La API responde `202`; la UI representa como pending una fila con cliente pero sin
`provisioned_at`. Commander no crea una carpeta de producto.

## Flujos UI

### Estado

Transiciones válidas: active a grace/suspended, grace a active/suspended y suspended a active.

- Suspend pide motivo y mensaje client-facing, pero no confirmación tipeada porque es reversible.
- Grace usa deadline explícito o duración por defecto y muestra countdown vivo.
- Resume devuelve a active.
- De-provision exige escribir el identificador porque destruye BD y storage.

### Facturas

- Marcar overdue abre opciones Keep running, Grace o Suspend.
- Marcar paid puede ofrecer Resume si el impago causó la suspensión.
- Billing y estado se muestran juntos, pero son campos distintos.

### Creación y edición

- Clientes y planes usan `CreateSidePanel` de Carbon.
- Facturas usan side panel desde cliente y modal desde la lista.
- Provisioning usa `ProvisionModal`.
- El detalle de cliente es un formulario editable in situ con Save flotante; Discard aparece solo
  cuando hay cambios.
- Las acciones primarias de colección van a la derecha, en `PageHeader` o al final del toolbar.

## Contrato visual

La dirección es enterprise sobria y de ingeniería:

- Carbon real y sus tokens antes que componentes o CSS propios.
- Esquinas rectas; solo tags, pills, dots y toggles son redondos.
- Bordes de 1 px entre capas.
- Color reservado para estado, enlaces, series y una acción primaria por pantalla.
- IBM Plex Mono para ids, slugs, emails, rutas, cifras y timestamps.
- Fechas relativas con fecha absoluta en tooltip.
- Desktop-first con tablas scrollables; los formularios pasan de dos a una columna bajo 600 px.

### Temas

Hay g100 oscuro —por defecto— y g10 claro, persistidos en `localStorage`. Se aplica la clase de
zona Carbon al `body`; el header permanece g100 en ambos. ECharts no hereda tokens y reconstruye
sus options con la paleta activa.

Semántica mínima:

| Estado | Color/Tag | Texto |
|---|---|---|
| active | verde | Operational |
| grace | ámbar custom | Grace + countdown |
| suspended | rojo | Suspended |
| paid | verde | Paid |
| pending | ámbar | Pending |
| overdue | rojo | Overdue |

En g100: background `#161616`, layer `#262626`, border `#393939`, text `#f4f4f4`, link
`#78a9ff`. En g10: background `#f4f4f4`, layer `#ffffff`, border `#e0e0e0`, text `#161616`,
link `#0f62fe`. La acción primaria usa IBM Blue 60 `#0f62fe` en ambos.

### Componentes y rendimiento

- `DataTableShell` unifica búsqueda, filtros, selección, paginación y skeletons.
- `Card`, `ChartCard`, `KpiCluster`, `QuotaBar`, `RelativeTime`, `Sparkline` y `StatusTag` son
  primitivas compartidas.
- Un solo `TickProvider` actualiza countdowns y tiempos relativos; las tablas no se rerenderizan
  cada segundo.
- Las minitendencias por fila son SVG/CSS, no una instancia ECharts por fila.
- Las cards de tablas comparten header, filas fijas, divisores y footer informativo.
- Las gráficas se construyen desde datos reales; no se inventa un delta si no existe ventana
  anterior.

### Accesibilidad

- contraste WCAG AA en ambos temas;
- focus visible y navegación por teclado;
- `aria-current` en navegación y `aria-sort` en cabeceras;
- `aria-live` para notificaciones;
- skeletons con la misma altura para evitar saltos;
- modales/paneles con gestión de foco;
- Command Palette accesible con `⌘K`.

## Correspondencia API

Todas las rutas salvo login requieren Sanctum y viven bajo
`https://commander.<BASE_DOMAIN>/api/v1`.

| Recurso | Endpoints principales |
|---|---|
| auth | `POST auth/login`, `GET auth/me`, `POST auth/logout` |
| CRUD | `clients`, `plans`, `users`, `subscriptions`, `invoices` |
| provisioning | `POST provisioning` |
| packages | `GET packages`, `GET/PATCH/DELETE packages/{id}` |
| estado/plan | `POST packages/{id}/suspend|grace|resume|change-plan` |
| instancia | `GET packages/{id}/usage|logs|insights` |
| cuotas | `GET quotas`, `GET quotas/{id}`, `PATCH quotas/{id}` |
| plataforma | `GET usage/platform`, `GET logs`, `GET commits`, `GET code-frequency` |
| actividad | `GET activity` |
| settings | `GET settings`, `PUT settings` |

No vuelvas a usar `{name}` en llamadas de instancia: `name = Kilkana` se comparte entre clientes;
el id es la identidad operativa.

## Build y despliegue

`npm run build` genera `commander/dist`. `commander/nginx.conf` aplica fallback SPA a
`index.html` y cache inmutable a assets con hash. El Dockerfile hace build Node y sirve estático
con nginx; el compose de desarrollo usa Vite con HMR.

Para producción, configura `VITE_API_BASE` si el frontend no comparte origen con Commander. El
backend sigue siendo la autoridad de CORS, auth y permisos.
