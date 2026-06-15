# Fase 5 — Charts de uso reales por despliegue

## Contexto (por qué)

Hasta la Fase 4 la pestaña **Usage** del detalle de un despliegue era una mentira bonita. Dibujaba
cuatro gráficas (peticiones, tasa de error, latencia, transferencia), una tira de KPIs y una tabla de
"top endpoints", pero todo salía de generadores sintéticos en `cms/src/lib/chartData.ts`:
funciones como `usageRequests`, `usageErrorRate`, `usageLatency` y `usageTransfer` que toman el
despliegue y fabrican una serie con un PRNG sembrado (`mulberry32` + `seedFrom`). El resultado es
estable entre renders y se ve plausible, pero no tiene ninguna relación con el tráfico real del
despliegue. Para un panel de operador que cobra por uso, enseñar números inventados en la pestaña que
literalmente se llama "Usage" es inaceptable.

El despliegue ya sabe medirse. Cada instancia del paquete **Core** registra peticiones en
`core_request_logs` y las agrega en `core_usage_rollups` mediante el comando `core:usage:rollup`. Y el
contrato `/control` ya define un endpoint de solo lectura para servir esos rollups firmados:
`GET /control/usage`. Lo único que faltaba era el cable: un endpoint en el CMS que haga de proxy
firmado hacia ese `/control/usage`, un transformador que convierta esas filas crudas en las series que
los charts de Carbon ya saben dibujar, y cablear `UsageTab.tsx` para que pida datos de verdad con sus
estados de carga, vacío y error.

Esta fase es deliberadamente acotada: **no** toca el modelo de datos del CMS ni las opciones de los
charts (`chartOptions.ts`). Sustituye la fuente de datos de una sola pestaña por la real. La
agregación de plataforma (Dashboard) y su caché viven en la Fase 6; aquí solo nos importa el uso
**por despliegue**.

## Qué se construyó

1. **Endpoint proxy `GET /deployments/{id}/usage`** en el paquete Cms. Es un proxy firmado HMAC hacia
   el `GET /control/usage` del despliegue. Devuelve los `core_usage_rollups` del despliegue tal cual.

2. **Transformador `rollupsToUsageSeries`** en `cms/src/lib/chartData.ts`. Toma el array de
   `UsageRollup[]` que devuelve el endpoint y produce un objeto `UsageSeries` con las cuatro series
   de puntos (`requests`, `errorRate`, `latency`, `transfer`), los KPIs agregados y los `topEndpoints`.
   Emite exactamente los mismos nombres de grupo (`'requests'`, `'4xx'`, `'5xx'`, `'avg'`, `'p95'`,
   `'in'`, `'out'`) que las opciones de chart ya esperaban, así `chartOptions.ts` queda intacto.

3. **`UsageTab.tsx` cableado** con estados `loading` / `empty` / `error`. Pide los datos en montaje y
   cada vez que se cambia la granularidad (hora/día), y reacciona a los tres desenlaces posibles de la
   llamada.

4. **Tests unitarios** del transformador en `cms/src/lib/chartData.test.ts` (bucketing por
   `period_start`, cálculo de error %, latencia ponderada, transferencia en MB, KPIs y top endpoints,
   más el caso de array vacío).

## Decisiones clave (y por qué)

**El CMS no agrega: solo proxea y reenvía.** El endpoint del operador no recalcula nada. El despliegue
ya hizo el trabajo de agregación con `core:usage:rollup`; el CMS solo firma la petición, la reenvía y
devuelve el JSON. Toda la transformación a forma de chart ocurre en el cliente
(`rollupsToUsageSeries`), donde es barata, testeable sin red y se ejecuta justo antes de pintar. Esto
mantiene el endpoint del CMS trivial y deja la lógica de presentación donde vive el resto de la
presentación.

**Permiso de lectura, no de control.** `suspend`, `grace`, `resume`, `sync` y `rotateSecret` exigen el
permiso dedicado `control deployments` (constante `CmsDeploymentsController::CONTROL_PERMISSION`).
`usage` es de solo lectura, así que se guarda con `$this->showPermission` (`'show deployments'`): si
puedes ver el despliegue, puedes ver su uso. No tiene sentido exigir permiso de operador para mirar
gráficas.

**El transformador habla el dialecto de los charts existentes.** En lugar de inventar nuevos nombres
de grupo y reescribir `chartOptions.ts`, `rollupsToUsageSeries` produce los mismos `group` que ya
usaban las funciones sintéticas (`usageErrorRate` emitía `'4xx'`/`'5xx'`, `usageLatency` emitía
`'avg'`/`'p95'`, etc.). Resultado: las gráficas y sus colores no cambian; solo cambian los puntos. La
sustitución de datos falsos por reales es invisible para `chartOptions.ts`.

**Reconstrucción óptima por bucket.** El despliegue agrega por `(period_start, route, method,
status_class)`, así que para una misma hora hay varias filas. El transformador las agrupa por
`period_start` y reconstruye cada métrica como toca:
- **Peticiones**: suma simple de `request_count`.
- **Tasa de error 4xx/5xx**: peticiones de esa clase ÷ total del bucket × 100 (porcentaje, no conteo).
- **Latencia media**: media **ponderada** por `request_count`, no media de medias —
  `Σ(avg_duration_ms · request_count) / Σ(request_count)`. Promediar los `avg` de filas con conteos
  dispares daría un número equivocado.
- **p95**: `max` de los `p95_duration_ms` del bucket. Es una aproximación honesta (no se puede
  recomponer un percentil exacto desde percentiles parciales), y conservadora.
- **Transferencia**: `total_request_bytes` / `total_response_bytes` divididos por `MB` (1024·1024)
  para mostrar megabytes.

**Las series salen ordenadas cronológicamente.** Se itera `[...buckets.keys()].sort()`. Como las
claves son ISO 8601 (`period_start`), el orden lexicográfico coincide con el cronológico, así que los
puntos quedan en el tiempo correcto sin parsear fechas para ordenar.

## Cómo funciona

### El endpoint proxy (servidor)

`CmsDeploymentsController::usage` (en
`src/packages/Cms/app/Http/Controllers/CmsDeploymentsController.php`):

```php
/** Proxy the deploy's real usage rollups (core_usage_rollups) for the Usage-tab charts. */
public function usage(Request $request, $id = null): JsonResponse
{
    if ($response = $this->requirePermission($this->showPermission)) {
        return $response;
    }

    if (($deployment = $this->findModel($id)) === null) {
        return response()->json(['error' => 'Record not found'], 404);
    }

    $data = $request->validate([
        'period'   => 'nullable|in:hour,day',
        'from'     => 'nullable|date',
        'to'       => 'nullable|date',
        'per_page' => 'nullable|integer|min:1|max:1000',
    ]);

    $query = array_filter([
        'period'   => $data['period'] ?? 'hour',
        'from'     => $data['from'] ?? null,
        'to'       => $data['to'] ?? null,
        'per_page' => $data['per_page'] ?? 1000,
    ], static fn ($v) => $v !== null);

    try {
        $usage = $this->control->getUsage($deployment, $query);
    } catch (CmsControlException $e) {
        return $this->controlError($e);
    }

    return response()->json($usage);
}
```

La ruta se declara **antes** del `apiResource` de deployments para que `{id}/usage` no la capture el
`show`:

```php
// src/packages/Cms/routes/api.php
Route::get('deployments/{id}/usage', [CmsDeploymentsController::class, 'usage']);
Route::apiResource('deployments', CmsDeploymentsController::class);
```

La firma HMAC la pone `CmsControlClient::getUsage`, que delega en el `send()` privado del cliente:

```php
public function getUsage(DeploymentModel $deployment, array $query = []): array
{
    return $this->send($deployment, 'GET', '/control/usage', null, $query);
}
```

Los parámetros (`period`, `from`, `to`, `per_page`) viajan en la **query string**, **no** se firman: el
`send()` construye el path firmado con `parse_url($url, PHP_URL_PATH)` (sin query) para que cuadre con
el `$request->path()` del receptor. Un error de operador `CmsControlException` se traduce a un
`502 Bad Gateway` con `error` / `detail` / `remote_status` vía `controlError()`.

### El transformador (cliente)

`rollupsToUsageSeries(rollups: UsageRollup[]): UsageSeries` en `cms/src/lib/chartData.ts`. El tipo de
entrada `UsageRollup` (de `cms/src/store/types.ts`) es una fila agregada:

```ts
export interface UsageRollup {
  period: 'hour' | 'day'
  period_start: string // ISO bucket start
  route: string
  method: HttpMethod
  status_class: '2xx' | '3xx' | '4xx' | '5xx'
  request_count: number
  error_count: number
  avg_duration_ms: number
  p95_duration_ms: number
  total_request_bytes: number
  total_response_bytes: number
  unique_users: number
}
```

El núcleo del bucketing por `period_start` y la reconstrucción de cada métrica:

```ts
for (const key of [...buckets.keys()].sort()) {
  const rows = buckets.get(key)!
  const date = new Date(key)
  const total = sum(rows, (r) => r.request_count)
  const c4 = sum(rows.filter((r) => r.status_class === '4xx'), (r) => r.request_count)
  const c5 = sum(rows.filter((r) => r.status_class === '5xx'), (r) => r.request_count)
  const weightedAvg = total > 0 ? sum(rows, (r) => r.avg_duration_ms * r.request_count) / total : 0
  const p95 = rows.reduce((m, r) => Math.max(m, r.p95_duration_ms), 0)

  requests.push({ group: 'requests', date, value: total })
  errorRate.push({ group: '4xx', date, value: total > 0 ? (c4 / total) * 100 : 0 })
  errorRate.push({ group: '5xx', date, value: total > 0 ? (c5 / total) * 100 : 0 })
  latency.push({ group: 'avg', date, value: Math.round(weightedAvg) })
  latency.push({ group: 'p95', date, value: p95 })
  transfer.push({ group: 'in', date, value: sum(rows, (r) => r.total_request_bytes) / MB })
  transfer.push({ group: 'out', date, value: sum(rows, (r) => r.total_response_bytes) / MB })
}
```

Los KPIs son agregados globales sobre **todas** las filas (no por bucket): `totalRequests`,
`errorRate` (0..1, como `totalErrors / totalRequests`), `avgLatencyMs` ponderada, `p95Ms` (máximo
global), `bytesIn`/`bytesOut` y `uniqueUsers` (máximo de `unique_users`, ya que es un conteo distinto
no sumable entre buckets sin doble conteo).

Los `topEndpoints` se construyen agrupando por `` `${method} ${route}` ``, sumando peticiones y
errores, tomando el `max` de p95, ordenando por peticiones descendente y quedándose con los 10
primeros. La ruta vacía se etiqueta `'(unmatched)'`.

### La pestaña (UI)

`UsageTab.tsx` mantiene un estado `UsageLoad` con `{ loading, series, error }`. Un `useEffect`
dependiente de `[dep.id, granularity, period]` dispara la carga en montaje y en cada toggle de
granularidad. Calcula la ventana (`48 h` para hora, `30 d` para día), arma el `from` en ISO y llama al
helper de API:

```ts
const windowMs = granularity === 0 ? 48 * 3_600_000 : 30 * 86_400_000
const from = new Date(Date.now() - windowMs).toISOString()
deploymentUsage(dep.id, { period, from })
  .then((res) => {
    if (!cancelled) setUsage({ loading: false, series: rollupsToUsageSeries(res.data ?? []), error: null })
  })
  .catch((e: unknown) => {
    if (!cancelled) setUsage({ loading: false, series: null, error: e instanceof Error ? e.message : 'Failed to load usage' })
  })
```

El flag `cancelled` (cleanup del efecto) evita actualizar estado tras desmontar o tras un cambio
rápido de granularidad — sin él, una respuesta lenta de la petición anterior podría pisar a la nueva.

El helper `deploymentUsage` (en `cms/src/lib/api.ts`) solo monta la query string y pega contra el
endpoint:

```ts
export function deploymentUsage(
  id: string,
  params: { period?: 'hour' | 'day'; from?: string; to?: string } = {},
): Promise<{ data: UsageRollup[] }> {
  const qs = new URLSearchParams()
  if (params.period) qs.set('period', params.period)
  if (params.from) qs.set('from', params.from)
  if (params.to) qs.set('to', params.to)
  const q = qs.toString()
  return api<{ data: UsageRollup[] }>(`/deployments/${id}/usage${q ? `?${q}` : ''}`)
}
```

Los tres estados de render son explícitos y excluyentes:
- **`loading`** → un `<Loading withOverlay={false} description="Loading usage…" />` centrado.
- **`error`** → un `EmptyState` "Usage unavailable" con el mensaje del fallo
  (`Could not reach the deploy's control plane: …`). Es el caso del `502` del proxy o un despliegue
  caído.
- **vacío** (`hasData` falso, es decir `series.requests.length === 0`) → un `EmptyState` "No usage
  data yet" que explica que el despliegue es nuevo o no tuvo tráfico en la ventana, y que el metering
  agrega por hora.
- **con datos** → la rejilla 2×2 de charts, la tabla de top endpoints y el botón de exportar CSV
  habilitado.

La tira de KPIs se pinta siempre, pero usa un helper `dash()` que muestra `—` mientras no haya datos,
así no parpadea con ceros durante la carga.

## Verificación

**Tests unitarios del transformador** (`cms/src/lib/chartData.test.ts`, ejecutables con `vitest`).
Cubren los invariantes que importan:

```ts
const rollups: UsageRollup[] = [
  row({ period_start: A, status_class: '2xx', request_count: 80, avg_duration_ms: 100, p95_duration_ms: 200, total_request_bytes: MB, total_response_bytes: 2 * MB }),
  row({ period_start: A, status_class: '4xx', request_count: 20, error_count: 20, avg_duration_ms: 300, p95_duration_ms: 500, total_request_bytes: MB }),
  row({ period_start: B, status_class: '5xx', request_count: 10, error_count: 10, avg_duration_ms: 50, p95_duration_ms: 90 }),
]
const s = rollupsToUsageSeries(rollups)

expect(s.requests.map((p) => p.value)).toEqual([100, 10])          // bucketing cronológico
expect(s.errorRate.find((p) => p.group === '4xx')?.value).toBe(20) // 20/100 = 20 %
expect(s.latency.find((p) => p.group === 'avg')?.value).toBe(140)  // (80·100 + 20·300)/100 = 140 ponderada
expect(s.latency.find((p) => p.group === 'p95')?.value).toBe(500)  // max(200, 500)
expect(s.transfer.find((p) => p.group === 'in')?.value).toBe(2)    // 2 MB ÷ 1 MB
expect(s.kpis.totalRequests).toBe(110)
expect(s.kpis.errorRate).toBeCloseTo(30 / 110, 5)
```

Hay además un test para el array vacío (`requests: []`, `kpis.totalRequests: 0`, `topEndpoints: []`)
que respalda el estado "No usage data yet" de la UI, y otro para el orden y agregación de
`topEndpoints` por `route+method`.

**Prueba en vivo.** Con un despliegue real aprovisionado por `scripts/subscription.sh` y registrado en
el CMS (ver `06-fase-4-demo-multideploy.md`):

1. Generar tráfico contra el `/api/v1` del despliegue para poblar `core_request_logs`.
2. Cerrar buckets ejecutando el rollup **dentro del contenedor del despliegue**:
   `php artisan core:usage:rollup --period=both` (comando `CoreUsageRollupCommand`).
3. Abrir el detalle del despliegue en el CMS → pestaña **Usage**. El navegador hace
   `GET /deployments/{id}/usage?period=hour&from=…`, el CMS firma y proxea hacia `GET /control/usage`,
   y las cuatro gráficas, los KPIs y la tabla muestran números **reales** en vez de los sintéticos.
4. Alternar el switch **Hour / Day** y comprobar que la ventana y el `period` cambian y la serie se
   recarga.
5. Para el estado de error, apagar el despliegue (o romper su secreto) y recargar: el proxy responde
   `502` y la pestaña muestra el `EmptyState` "Usage unavailable".

## Gotchas

- **`core:usage:rollup` solo cierra buckets completos: la hora en curso queda fuera.**
  `CoreUsageRollupService` agrega `core_request_logs` únicamente en buckets de hora/día **cerrados**.
  Las peticiones de la hora actual aún no aparecen en `core_usage_rollups` y, por tanto, **no** llegan
  a la pestaña Usage hasta que esa hora termina y el rollup vuelve a correr. No es un bug: es por qué
  un despliegue con tráfico recién generado puede seguir mostrando "No usage data yet" si todo el
  tráfico cae dentro de la hora viva. Para verlo de inmediato en una prueba, genera tráfico y espera al
  cambio de hora, o usa datos de horas anteriores.

- **El orden de las rutas importa.** `GET deployments/{id}/usage` se declara antes del
  `apiResource('deployments', …)`. Si fuera al revés, el comodín `{deployment}` del recurso capturaría
  `usage` como un id y devolvería un 404 de "no encontrado".

- **La query no se firma; el path sí.** Los parámetros `period`/`from`/`to`/`per_page` viajan en la
  URL y quedan fuera de la firma HMAC (el `send()` firma `parse_url(..., PHP_URL_PATH)`). Si alguna
  vez se movieran al body, habría que ajustar la firma para que cuadre con el receptor.

- **p95 reconstruido es un máximo, no un percentil exacto.** Al combinar buckets agregados tomamos el
  `max` de los p95 parciales. Es lo correcto que se puede hacer sin las muestras crudas, pero ten
  presente que es una cota superior, no el p95 verdadero del agregado.

- **Latencia: media ponderada, nunca media de medias.** Repetido aquí porque es el error fácil:
  promediar los `avg_duration_ms` de filas con `request_count` muy distinto sesga el resultado. El
  transformador pondera por conteo tanto por bucket como en el KPI global.

- **Config namespaced del paquete.** El cliente lee `config('cms.cms.control', …)` (el patrón
  `paquete.fichero`), no `config('cms.control')`. Es el mismo gotcha del control plane descrito en
  `04-fase-2-proxy-control.md`.

## Ficheros tocados

- `src/packages/Cms/app/Http/Controllers/CmsDeploymentsController.php` — método `usage()` (proxy
  firmado a `/control/usage`, guardado por `show deployments`).
- `src/packages/Cms/routes/api.php` — ruta `GET deployments/{id}/usage` declarada antes del
  `apiResource`.
- `src/packages/Cms/app/Services/CmsControlClient.php` — `getUsage()` (envoltorio firmado de
  `GET /control/usage`; la query no se firma).
- `cms/src/lib/chartData.ts` — `rollupsToUsageSeries()` + interfaz `UsageSeries`.
- `cms/src/store/types.ts` — interfaz `UsageRollup`.
- `cms/src/lib/api.ts` — helper `deploymentUsage()`.
- `cms/src/pages/DeploymentDetail/UsageTab.tsx` — cableado a datos reales con estados
  loading / empty / error.
- `cms/src/lib/chartData.test.ts` — tests del transformador.

Para el contrato `/control` y la firma HMAC, ver `02-plano-de-control.md` y `04-fase-2-proxy-control.md`.
Para el aprovisionamiento de despliegues reales usados en la prueba en vivo, ver
`06-fase-4-demo-multideploy.md`. La agregación de plataforma del Dashboard y su caché de snapshots se
documentan en la Fase 6.
