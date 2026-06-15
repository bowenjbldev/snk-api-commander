# Fase 3 — Cablear el SPA al API real

## Contexto (por qué)

Hasta la Fase 2 teníamos dos mitades funcionando por separado. Por un lado, el paquete
Laravel `Commander` exponía su propia base de datos y su API de operador (`/clients`,
`/deployments`, `/subscriptions`, `/invoices`, `/quotas`, `/activity`) más el proxy
firmado `/control` hacia cada despliegue. Por otro lado, la SPA de React con IBM Carbon
era una maqueta completa —las nueve secciones, las tablas, los modales, los charts— pero
alimentada con **fixtures**: datos falsos generados en memoria por `buildFixtures()`. El
panel se veía real, pero no controlaba nada.

El objetivo de esta fase es **convertir esa maqueta en una aplicación de verdad**: que el
panel se autentique contra el backend, cargue el registro real de despliegues y, cuando el
operador suspende, pone en gracia, reactiva, rota un secreto o edita una cuota, esa acción
viaje al servidor y —cuando corresponde— acabe llamando a la API `/control` del despliegue
remoto.

El reto de diseño no era técnico sino de **alcance**. La SPA ya tenía unos 75 ficheros
TypeScript/TSX (75 archivos bajo `commander/src`), y las acciones del store (`suspend`, `grace`,
`resume`, `editQuotaLimit`, etc.) se invocaban desde decenas de páginas, modales y
proveedores con una **firma síncrona**: por ejemplo `s.suspend(d.id, {...})` en
`ActionsProvider.tsx`. Si hubiéramos convertido todas esas acciones en `async` para esperar
la respuesta del servidor, habríamos tenido que tocar todos esos puntos de llamada,
reescribir modales para mostrar spinners y propagar `await` por todo el árbol. La decisión
clave de la fase —el patrón **optimista + `persist()` en segundo plano**— existe
precisamente para evitar ese coste.

## Qué se construyó

La fase añade y conecta cinco piezas, todas en `commander/src`:

1. **`lib/api.ts`** — un cliente `fetch` fino: arma la URL, adjunta el token Bearer,
   parsea el JSON, normaliza los errores en una clase `ApiError` y, sobre todo, convierte
   cualquier `401` en una señal de logout global.
2. **`store/auth.ts`** — un store de Zustand independiente para la sesión: `bootstrap()`
   (validar el token guardado al arrancar), `login()`, `logout()` y el estado
   `loading | anon | authed`.
3. **`pages/Login.tsx`** — la pantalla de acceso con Carbon (`Form`, `TextInput`,
   `PasswordInput`, `Button`, `InlineNotification`).
4. **`App.tsx`** — el **gate de autenticación** que decide qué se monta (spinner, login o
   la app) y la **compuerta de carga de datos** que evita que el router parpadee con
   fixtures antes de que llegue la respuesta real.
5. **`store/store.ts`** — el store de datos del panel: añade `loadData()` (que reemplaza
   los fixtures por las seis colecciones reales) y reescribe todas las acciones de mutación
   con el patrón optimista + `persist()`.

Además, la fase se apoya en una pieza que ya existía y que es la que hace que todo esto sea
cómodo en desarrollo: el **proxy de Vite** en `vite.config.ts`.

## Decisiones clave (y por qué)

### Optimista + `persist()` en segundo plano, para no tocar ~62 ficheros

Las acciones del store mantienen su **firma síncrona original**. Cuando el operador pulsa
"Suspender", la acción:

1. **muta el estado local de inmediato** (la UI cambia al instante, sin esperar a la red);
2. lanza la llamada al API **en segundo plano** mediante el helper `persist()`;
3. al recibir `2xx` con una entidad, **reconcilia** reemplazando la copia local por la del
   servidor (el servidor es la autoridad);
4. si la llamada **falla**, registra el error en `actionError` (que el `ActionsProvider`
   convierte en un toast) y **re-sincroniza** llamando a `loadData()` para deshacer la
   mutación optimista.

El helper vive dentro de `useStore` y es exactamente:

```ts
const fail = (message: string) => set({ actionError: { message, at: Date.now() } })

const persist = <T,>(p: Promise<T>, reconcile?: (entity: T) => void): void => {
  p.then((entity) => {
    if (reconcile && entity && typeof entity === 'object' && 'id' in (entity as object)) {
      reconcile(entity)
    }
  }).catch((e) => {
    fail(e instanceof Error ? e.message : 'Request failed')
    void get().loadData()
  })
}
```

Como `persist()` **no devuelve la promesa**, las acciones que lo usan pueden seguir siendo
síncronas y los ~62 ficheros que las consumen no cambian. Solo `rotateSecret` rompe el
patrón (ver más abajo), por una razón legítima.

### `rotateSecret` es la excepción async

La rotación de secreto **no puede ser optimista**: el secreto nuevo lo **genera el
servidor** (el despliegue lo acuña y lo acepta firmando con el actual), así que el cliente
no tiene nada que pintar de forma anticipada. Por eso `rotateSecret` sí es `async`, espera
la respuesta y devuelve el sufijo del nuevo secreto para el toast:

```ts
rotateSecret: async (id) => {
  try {
    const dep = await api<Deployment>(`/deployments/${id}/rotate-secret`, { method: 'POST' })
    if (dep && typeof dep === 'object' && 'id' in dep) replaceDep(dep)
    return dep?.control_secret_suffix ?? null
  } catch (e) {
    fail(e instanceof Error ? e.message : 'Secret rotation failed')
    return null
  }
}
```

El único punto de llamada que respeta esto es el modal de rotación en
`ActionsProvider.tsx`, que sí hace `await useStore.getState().rotateSecret(...)` y muestra
`New secret ends …${suffix}`.

### El `401` desencadena logout global, sin acoplar el cliente al store

`lib/api.ts` no conoce el store de auth (evitamos una dependencia circular). En su lugar
expone un registro de callback: `setUnauthorizedHandler(fn)`. Ante cualquier `401`, el
cliente invoca ese handler y lanza un `ApiError(401, 'Session expired — please sign in
again.')`. Es `store/auth.ts` quien, al cargarse, registra el handler para tirar el token y
mover el estado a `anon`:

```ts
setUnauthorizedHandler(() => {
  setToken(null)
  useAuth.setState({ status: 'anon', user: null })
})
```

Así, si el token caduca a media sesión, **cualquier** petición que reciba 401 deja la app
en la pantalla de login sin código repetido en cada llamada.

### Dos stores separados: sesión y datos

`useAuth` (sesión) y `useStore` (registro/datos) son deliberadamente independientes. La
sesión tiene su propio ciclo de vida (`loading → anon/authed`) y se valida una sola vez al
arrancar; los datos solo tienen sentido **después** de autenticarse. Mantenerlos separados
permite que `App.tsx` use el estado de la sesión como compuerta para decidir si siquiera
montar la maquinaria que carga datos.

### El token vive en `localStorage`, la base de la URL es configurable

`lib/api.ts` guarda el token bajo la clave `commander_token` en `localStorage` y resuelve la base
del API así:

```ts
const BASE = (import.meta.env.VITE_API_BASE as string | undefined) ?? '/api/v1'
const TOKEN_KEY = 'commander_token'
```

En desarrollo no se define `VITE_API_BASE`, así que la SPA llama a un `/api/v1` del **mismo
origen** y el proxy de Vite lo reenvía al backend. En un build de producción se fija
`VITE_API_BASE` al origen del despliegue (p. ej. `https://commander.host/api/v1`).

## Cómo funciona

### El gate de autenticación (`App.tsx`)

El árbol es `ThemeProvider → ToastProvider → AuthGate`. `AuthGate` lee `status` de `useAuth`
y, al montar, ejecuta `bootstrap()` una vez:

- `status === 'loading'` → `<FullScreenLoading label="Signing in…" />`
- `status === 'anon'` → `<Login />`
- `status === 'authed'` → `<AuthedApp />`

`bootstrap()` mira si hay token guardado: si no lo hay, va directo a `anon`; si lo hay,
valida con `authApi.me()` y pasa a `authed` con el usuario; si `me()` falla, descarta el
token y cae a `anon`.

```ts
bootstrap: async () => {
  if (!getToken()) { set({ status: 'anon' }); return }
  try {
    const { user } = await authApi.me()
    set({ status: 'authed', user })
  } catch {
    setToken(null)
    set({ status: 'anon', user: null })
  }
}
```

### La compuerta de carga de datos (`AuthedApp`)

Solo cuando la sesión es `authed` se monta `AuthedApp`, que dispara `loadData()` una vez y
**no monta el router** hasta que `loaded === true`. Esto es lo que impide que el panel
parpadee mostrando los fixtures iniciales antes de que llegue la respuesta real del API:

```tsx
return (
  <TickProvider>
    <ActionsProvider>
      {loaded ? <RouterProvider router={router} /> : <FullScreenLoading label="Loading workspace…" />}
    </ActionsProvider>
  </TickProvider>
)
```

### El login (`pages/Login.tsx`)

Formulario Carbon con `email`/`password`. Al enviar, llama a `login(email, password)` del
store de auth; si lanza, el `error` del store se renderiza en un `InlineNotification`
("Sign-in failed"). El campo de email viene precargado con `admin@snake.com` y la pista de
demo es `admin@snake.com` / `secret1234`. `login()` guarda el token con `setToken(token)` y
pasa el estado a `authed`.

### La carga inicial de datos (`loadData`)

`loadData()` pide las seis colecciones **en paralelo** con `Promise.all` y reemplaza los
fixtures. Además recomputa dos KPIs reales del dashboard a partir de los datos vivos
(`requests_24h` agregado y `mrr` sumando suscripciones que no estén `overdue`); el resto de
charts/deltas siguen siendo sintéticos por ahora. Al terminar, marca `loaded: true`:

```ts
const [clients, deployments, subscriptions, invoices, quotas, activityFeed] = await Promise.all([
  api<Client[]>('/clients'),
  api<Deployment[]>('/deployments'),
  api<Subscription[]>('/subscriptions'),
  api<Invoice[]>('/invoices'),
  api<QuotaMetric[]>('/quotas'),
  api<ActivityEvent[]>('/activity'),
])
```

### El cliente `fetch` (`lib/api.ts`)

La función `api<T>(path, opts)` construye `fetch(`${BASE}${path}`, …)` con cabeceras
`Accept: application/json`, `Content-Type: application/json` (solo si hay cuerpo) y
`Authorization: Bearer <token>` (solo si hay token). Tras la respuesta:

- `401` → invoca `onUnauthorized?.()` y lanza `ApiError(401, …)`.
- Parsea el texto a JSON (o `null` si está vacío).
- Si no es `ok`, extrae el detalle del cuerpo en el orden `error → detail → message` y lanza
  `ApiError(status, detalle)`.

Sobre `api()` se montan los helpers tipados: `authApi.login/me/logout`, y para fases
posteriores `deploymentUsage()` (usa `/deployments/:id/usage`) y `platformUsage()` (usa
`/usage/platform`).

### El recorrido completo de una acción optimista

Tomando "Suspender" como ejemplo de extremo a extremo:

1. El operador confirma en el `StateChangeModal`; `ActionsProvider` llama
   `s.suspend(d.id, { reason, message })` **sin `await`**.
2. `suspend` muta el despliegue local a estado `suspended` y antepone un evento de actividad
   `suspend`. La UI cambia al instante.
3. `persist()` lanza `POST /deployments/:id/suspend` con `{ reason, message }`.
4. El backend `Commander` actualiza su registro y, por debajo, firma y llama a
   `POST /control/state` del despliegue remoto (mecanismo de la Fase 2). El despliegue
   pasa a devolver `402` en sus rutas no exentas.
5. En `2xx`, `persist()` reconcilia con `replaceDep(d)` (la versión del servidor manda).
6. Si algo falla (servidor rechaza o despliegue inalcanzable), se fija `actionError`, el
   `ActionsProvider` muestra el toast "Action could not be saved" y se llama `loadData()`
   para revertir la vista al estado real.

## Verificación

- **Login y bootstrap:** arrancar la SPA en `:5174`, abrir el panel, comprobar que aparece
  la pantalla de login (`status === 'anon'`), entrar con `admin@snake.com` / `secret1234` y
  ver que `localStorage` queda con la clave `commander_token`. Recargar la página: `bootstrap()`
  valida el token con `/auth/me` y entra directo sin volver a pedir credenciales.
- **Carga de datos real:** tras el login, el panel ya **no** muestra fixtures; las tablas de
  despliegues/clientes/facturas reflejan lo que hay en la BD del `Commander`. Mientras
  `loaded === false` se ve "Loading workspace…".
- **Acción optimista + persistencia:** suspender un despliegue → la fila cambia al instante;
  en la pestaña de red se ve `POST /deployments/:id/suspend`; al volver `2xx` la fila se
  reconcilia con la respuesta del servidor.
- **Re-sincronización en fallo:** forzar un error del backend (apagar el despliegue remoto o
  devolver 5xx) y comprobar que aparece el toast "Action could not be saved" y que la fila
  vuelve a su estado real tras el `loadData()` automático.
- **401 → logout:** invalidar/borrar el token a mano y disparar cualquier acción; la app
  debe caer a la pantalla de login sin recargar.
- **Prueba en vivo (preludio de la Fase 4):** rotar el secreto de un despliegue real desde el
  modal y comprobar el toast "Control secret rotated · New secret ends …<suffix>", lo que
  confirma que la llamada `POST /deployments/:id/rotate-secret` llegó al despliegue y que el
  sufijo devuelto es el del **nuevo** secreto.

## Gotchas

- **No esperes `persist()`.** Es deliberadamente "fire-and-forget": no devuelve la promesa.
  Toda acción de mutación (salvo `rotateSecret`) sigue siendo síncrona; meter un `await` en
  el punto de llamada no sirve de nada y rompe la asunción de las ~62 llamadas existentes.
- **`rotateSecret` es la única async.** Si añades un punto de llamada nuevo, recuerda hacer
  `await` y usar el sufijo devuelto; no es optimista porque el secreto lo genera el servidor.
- **El cliente `fetch` no importa el store de auth.** El acoplamiento se hace al revés, con
  `setUnauthorizedHandler` desde `store/auth.ts`, para evitar dependencias circulares.
  Cualquier `401`, en cualquier llamada, dispara el logout global.
- **El `Content-Type` solo se manda si hay cuerpo.** `api()` distingue por
  `opts.body !== undefined`; un `GET` no lleva `Content-Type` ni `body`.
- **El parseo de errores busca `error → detail → message` en ese orden.** Si el backend
  cambia la forma del cuerpo de error, el mensaje del toast puede degradar a
  `Request failed (<status>)`.
- **La compuerta `loaded` es lo que evita el parpadeo de fixtures.** El store nace con
  `initial = buildFixtures(Date.now())`; sin la compuerta de `AuthedApp` el router montaría
  por un instante con datos falsos antes de que `loadData()` los reemplace.
- **En dev no definas `VITE_API_BASE`.** Déjalo en el `/api/v1` del mismo origen para que el
  proxy de Vite haga su trabajo; definirlo te saca del proxy y reintroduce CORS y el aviso
  del certificado self-signed.

## Proxy de Vite (transporte en desarrollo)

La comodidad de "mismo origen" en dev la da el proxy de `vite.config.ts`. La SPA corre en el
puerto `5174` y todo lo que empiece por `/api` se reenvía al backend HTTPS local con el
certificado self-signed aceptado:

```ts
server: {
  port: 5174,
  // Same-origin /api → backend HTTPS (self-signed). Avoids CORS + cert prompts in dev.
  proxy: {
    '/api': { target: 'https://localhost:8443', changeOrigin: true, secure: false },
  },
}
```

`changeOrigin: true` reescribe la cabecera `Host` para el backend y `secure: false` hace que
el proxy no valide el certificado self-signed. El resultado: en desarrollo **no hay CORS ni
avisos de certificado**, porque para el navegador todo es mismo origen (`:5174`). La
configuración de CORS de producción (`CORS_ALLOWED_ORIGINS`) se aborda en una fase posterior
de hardening.

## Ficheros tocados

- `commander/src/lib/api.ts` — cliente `fetch`, token Bearer en `localStorage` (`commander_token`),
  `ApiError`, handler global de `401` (`setUnauthorizedHandler`), y los helpers `authApi`,
  `deploymentUsage`, `platformUsage`.
- `commander/src/store/auth.ts` — store de sesión Zustand: `bootstrap`, `login`, `logout`, estado
  `loading | anon | authed`, y registro del handler de `401`.
- `commander/src/pages/Login.tsx` — pantalla de acceso con Carbon que invoca `login()`.
- `commander/src/App.tsx` — `AuthGate` (gate de sesión) y `AuthedApp` (compuerta de carga de datos
  con `loaded`).
- `commander/src/store/store.ts` — `loadData()` (reemplaza fixtures por las seis colecciones reales
  y recomputa KPIs), helper `persist()`, reconciliadores (`replaceDep`, `upsertDep`,
  `replaceInvoice`, `replaceClient`, `upsertClient`) y todas las acciones de mutación con el
  patrón optimista; `rotateSecret` como única acción async.
- `commander/vite.config.ts` — proxy de desarrollo `/api → https://localhost:8443`
  (`changeOrigin`, `secure: false`) en el puerto `5174`.
- `commander/src/providers/ActionsProvider.tsx` — consumidor del patrón: convierte `actionError`
  del store en un toast y es el único sitio que hace `await rotateSecret(...)`.
