# Fase 6 — Charts de plataforma reales

## Contexto (por qué)

El Dashboard y la pestaña Usage del panel del operador muestran dos gráficas agregadas de toda la
flota: "Requests per hour" (barras apiladas por despliegue, últimas 48 horas) y "Error rate"
(porcentaje 4xx+5xx). En la fase 5 ya cableamos la pestaña Usage *por-despliegue* contra los rollups
reales de cada despliegue (`core_usage_rollups`, vía `GET /deployments/{id}/usage`). Pero las gráficas
*de plataforma* son un problema distinto: agregan sobre **todos** los despliegues a la vez.

Y ahí estaba el conflicto de la flota mixta. La base de datos del CMS contiene dos tipos de
despliegues conviviendo: por un lado los del seed de demostración (los 15 que siembra
`CmsDatabaseSeeder`, con `base_url` apuntando a subdominios ficticios como
`https://bluepeak.snakecloud.io/api/v1` que no existen), y por otro los despliegues vivos reales que
`scripts/subscription.sh` aprovisiona y auto-registra (ver `06-fase-4-demo-multideploy.md`). Si la
gráfica de plataforma intentara hacer N llamadas en vivo a `GET /control/usage` de cada despliegue
para agregar, los del seed darían error de conexión o 502, y la gráfica se quedaría en blanco aunque
hubiera un despliegue vivo perfectamente sano.

La solución de esta fase es una **caché local por-despliegue por-hora** dentro de la BD del CMS: la
tabla `cms_usage_snapshots`. Se siembra de fábrica con una curva diurna determinista para los 15
despliegues de demo (así el dashboard nunca está vacío al primer arranque), y cada vez que el operador
sincroniza un despliegue vivo, el `sync` **sobrescribe** las horas de ese despliegue con los datos
reales que vienen de su `/control/usage`. Las gráficas leen siempre de esta caché local con una sola
consulta SQL, nunca tocan los despliegues remotos. Resultado: la gráfica siempre muestra algo, los
datos falsos del seed se reemplazan progresivamente por datos reales según se va sincronizando cada
despliegue, y no hay forma de que un subdominio ficticio rompa la pantalla.

## Qué se construyó

- **Tabla `cms_usage_snapshots`** (migración `2026_06_15_000007_create_cms_usage_snapshots_table.php`):
  una fila por `deployment_id` + hora, con `request_count` y `error_count`. Es la caché local que
  alimenta las gráficas de plataforma.
- **`UsageSnapshotModel`** (`Packages\Cms\Models\UsageSnapshotModel`): el modelo Eloquent sobre esa
  tabla, con clave primaria de tipo string no autoincremental.
- **`CmsDatabaseSeeder::seedUsageSnapshots()`**: siembra 48 snapshots horarios por despliegue (curva
  diurna determinista derivada de `requests_24h`), de modo que el dashboard sale poblado de fábrica.
- **`CmsDeploymentSyncService::applySnapshots()`**: el paso del `sync` que hace *upsert* de los
  snapshots reales del despliegue (agregando sus rollups por hora) sobre las filas seed.
- **Endpoint `GET /usage/platform`** (`CmsUsageController::platform`): lee y devuelve los snapshots de
  la caché local en un rango horario, con una sola consulta.
- **Transformer `snapshotsToPlatformSeries()`** (`cms/src/lib/chartData.ts`): convierte la lista plana
  de snapshots en las series que esperan las gráficas Carbon (barras apiladas top-4 + "other", y línea
  de error %).
- **Dashboard y Usage cableados** (`cms/src/pages/Dashboard.tsx`, `cms/src/pages/Usage.tsx`):
  consumen `GET /usage/platform` vía `platformUsage()` y renderizan ambas gráficas desde datos reales,
  con auto-refresco de 30s en el Dashboard.

## Decisiones clave (y por qué)

**Caché local en lugar de agregación en vivo.** La razón de fondo es la flota mixta descrita arriba.
Si agregáramos consultando `/control/usage` de cada despliegue al pintar la gráfica, un solo
despliegue del seed con URL ficticia (o cualquier despliegue real que esté caído) bastaría para tirar
la pantalla completa o introducir latencia de N llamadas de red en serie. La caché desacopla el render
de la disponibilidad de los despliegues: la gráfica lee de una tabla local y siempre responde rápido.

**Una fila por despliegue+periodo+hora, con clave compuesta como string.** La clave primaria es
`"${deployment_id}:${period}:${epoch}"` (por ejemplo `bluepeak:hour:1718398800`). Esto hace que el
upsert sea idempotente: sincronizar el mismo despliegue dos veces sobre la misma hora reescribe la
fila en vez de duplicarla, y un despliegue real nunca pisa las horas de otro. El campo `period`
admite `hour` o `day`, aunque por ahora solo poblamos `hour`.

**Seed de fábrica con curva diurna determinista.** Queríamos que la primera vez que un operador abre
el panel ya vea gráficas con forma realista, no un placeholder vacío. El seed deriva el volumen base
de `requests_24h` de cada despliegue, le aplica una onda senoidal por hora del día y un ruido
determinista (vía `crc32`), de modo que la forma es estable entre ejecuciones del seeder pero no
plana.

**El `sync` sobrescribe, no acumula.** Como la clave es determinista por hora, cuando se sincroniza un
despliegue vivo su `applySnapshots()` hace `updateOrCreate` sobre exactamente las mismas claves que
sembró el seeder para ese despliegue. Así los datos falsos de ese despliegue se reemplazan por los
reales hora a hora, mientras los despliegues que aún no se han sincronizado conservan su curva seed.
Es exactamente lo que resuelve el problema de la flota mixta: la gráfica converge hacia datos reales
sin dejar de mostrar algo en ningún momento.

**El transformer vive en el SPA, no en el backend.** El endpoint devuelve los snapshots crudos y el
cliente (`snapshotsToPlatformSeries`) hace el top-4 + "other" y el cálculo de error %. Mantiene el
backend tonto (un simple `SELECT` ordenado) y reutiliza las mismas opciones de gráfica Carbon
(`stackedBarOptions`, `areaOptions`) que ya existían, sin tocar `chartOptions.ts`.

## Cómo funciona

### El esquema de la caché

La tabla `cms_usage_snapshots` (migración
`src/packages/Cms/database/migrations/2026_06_15_000007_create_cms_usage_snapshots_table.php`):

```php
Schema::create('cms_usage_snapshots', function (Blueprint $table) {
    $table->string('id', 96)->primary(); // "${deployment_id}:${period}:${epoch}"
    $table->string('deployment_id', 64);
    $table->string('period', 8); // hour|day
    $table->timestamp('period_start');
    $table->unsignedBigInteger('request_count')->default(0);
    $table->unsignedBigInteger('error_count')->default(0);
    $table->timestamps();

    $table->index('deployment_id');
    $table->index(['period', 'period_start']);
});
```

El índice compuesto `['period', 'period_start']` es justo el que aprovecha la consulta del endpoint
(filtra por `period` y por `period_start >= since`).

### El seed de fábrica

`CmsDatabaseSeeder::seedUsageSnapshots()` (en
`src/packages/Cms/database/seeders/CmsDatabaseSeeder.php`) se invoca al final de `run()`, después de
sembrar los despliegues. Genera 48 horas hacia atrás desde el inicio de la hora actual, una fila por
despliegue y hora:

```php
$hourStart = (clone $now)->startOfHour();

foreach (DeploymentModel::all() as $d) {
    $base = $d->requests_24h / 24;
    $errRate = min(0.08, max(0.004, (float) $d->quota_health * 0.03));

    for ($h = 0; $h < 48; $h++) {
        $bucket = (clone $hourStart)->subHours(47 - $h);
        $hour = (int) $bucket->format('G');
        $wave = 1 + 0.4 * sin(($hour / 24) * M_PI * 2 - M_PI / 2);
        $noise = (abs(crc32($d->id.':'.$h)) % 1000) / 1000.0; // determinista 0..1
        $req = (int) max(0, round($base * $wave * (0.85 + $noise * 0.3)));
        $err = (int) round($req * $errRate * (0.6 + $noise * 0.8));

        UsageSnapshotModel::updateOrCreate(['id' => $d->id.':hour:'.$bucket->timestamp], [
            'deployment_id' => $d->id,
            'period' => 'hour',
            'period_start' => $bucket,
            'request_count' => $req,
            'error_count' => min($req, $err),
        ]);
    }
}
```

Nótese que el volumen base sale de `requests_24h / 24` y la tasa de error escala con
`quota_health` (los despliegues por encima de cuota muestran 4xx/5xx más elevados). El `error_count`
nunca supera el `request_count` (`min($req, $err)`).

### El upsert del sync (datos reales)

Cuando el operador sincroniza un despliegue —ya sea on-demand con
`POST /deployments/{id}/sync` (`CmsDeploymentsController::sync`) o por el comando programado
`cms:sync-deploys`— se ejecuta `CmsDeploymentSyncService::sync()`. Ese método pide el estado y la
usage en vivo y llama a `applySnapshots()`:

```php
$usage = $this->control->getUsage($deployment, [
    'period' => 'hour',
    'from' => CarbonImmutable::now()->subDays(2)->toIso8601String(),
    'per_page' => 1000,
]);
// ...
$this->applySnapshots($deployment, $usage);
```

`applySnapshots()` agrupa los rollups del despliegue por `period_start`, suma `request_count` y
`error_count` por hora, y hace `updateOrCreate` con la **misma clave determinista** que usó el seeder:

```php
foreach ($byBucket as $ps => $agg) {
    $bucket = CarbonImmutable::parse($ps);
    UsageSnapshotModel::updateOrCreate(['id' => $deployment->id . ':hour:' . $bucket->timestamp], [
        'deployment_id' => $deployment->id,
        'period' => 'hour',
        'period_start' => $bucket,
        'request_count' => $agg['requests'],
        'error_count' => $agg['errors'],
    ]);
}
```

Como la clave coincide hora a hora, las filas seed de ese despliegue quedan reemplazadas por los datos
reales. Los rollups vienen de `getUsage()`, que llama a `GET /control/usage` del despliegue (cuyo
backend agrega `core_request_logs` en `core_usage_rollups` solo en buckets de hora/día cerrados).

### El endpoint de lectura

`CmsUsageController::platform()` (en
`src/packages/Cms/app/Http/Controllers/CmsUsageController.php`) sirve `GET /usage/platform`. Exige el
permiso `index deployments`, valida `period` (`hour|day`, por defecto `hour`) y `hours` (1..720, por
defecto 48), y devuelve los snapshots ordenados por `period_start`:

```php
$period = $data['period'] ?? 'hour';
$since = CarbonImmutable::now()->subHours((int) ($data['hours'] ?? 48));

$rows = UsageSnapshotModel::query()
    ->where('period', $period)
    ->where('period_start', '>=', $since)
    ->orderBy('period_start')
    ->get(['deployment_id', 'period_start', 'request_count', 'error_count']);

return response()->json(['data' => $rows]);
```

La ruta está registrada en `src/packages/Cms/routes/api.php`, dentro del grupo `auth:sanctum`:

```php
Route::get('usage/platform', [CmsUsageController::class, 'platform']);
```

### El transformer del SPA

`snapshotsToPlatformSeries()` (en `cms/src/lib/chartData.ts`) recibe la lista plana de snapshots y
produce dos series. Primero elige los **top-4 despliegues** por total de requests y pliega el resto en
un grupo `'other'`. Después, por cada bucket horario, emite un punto por cada despliegue del top-4 más
el `'other'` para la gráfica de barras apiladas, y un punto de error % (`errors / total * 100`) para
la gráfica de área:

```ts
export function snapshotsToPlatformSeries(snapshots: UsageSnapshot[]): PlatformSeries {
  const totals = new Map<string, number>()
  for (const s of snapshots) totals.set(s.deployment_id, (totals.get(s.deployment_id) ?? 0) + s.request_count)
  const top = [...totals.entries()].sort((a, b) => b[1] - a[1]).slice(0, 4).map(([id]) => id)
  // ... agrupa por period_start, separa top-4 vs other, calcula error % por bucket
  return { requests: { data, groups }, errorRate }
}
```

El tipo `UsageSnapshot` (en `cms/src/store/types.ts`) refleja exactamente lo que devuelve el endpoint:
`deployment_id`, `period_start` (ISO del bucket horario), `request_count`, `error_count`.

### El cableado de las pantallas

Ambas páginas consumen el mismo endpoint vía `platformUsage()` (en `cms/src/lib/api.ts`), que pega a
`/usage/platform?period=hour&hours=48`:

```ts
export function platformUsage(
  params: { period?: 'hour' | 'day'; hours?: number } = {},
): Promise<{ data: UsageSnapshot[] }> { /* ... GET /usage/platform ... */ }
```

En `cms/src/pages/Dashboard.tsx`, un `useEffect` carga la serie al montar y la refresca cada 30s
(coherente con la etiqueta "auto-refresh 30s"). Un detalle importante: ante un **error de refresco**
conserva la última serie buena en lugar de poner la gráfica en blanco —

```ts
.catch((e: unknown) => {
  // Keep the last good series on a refresh error so the chart doesn't blank out.
  if (!cancelled) setPlatform((p) => ({ loading: false, series: p.series, error: ... }))
})
```

Las dos gráficas (`StackedBarChart` para "Requests per hour", `AreaChart` para "Error rate") se pintan
solo si `hasChart` (cargado, sin error y con datos); en caso contrario muestran un `ChartPlaceholder`
con estado de carga o error. `cms/src/pages/Usage.tsx` hace el mismo cableado pero sin el intervalo de
auto-refresco (carga una vez al montar).

## Verificación

- **Migración + seed:** ejecutar las migraciones y `CmsDatabaseSeeder`; comprobar que
  `cms_usage_snapshots` tiene 48 filas por despliegue (15 despliegues × 48 = 720 filas) y que
  `GET /usage/platform` responde con datos. Las gráficas del Dashboard y de Usage aparecen pobladas al
  primer arranque, sin haber sincronizado nada.
- **Sobrescritura con datos reales (prueba en vivo):** aprovisionar un despliegue real con
  `scripts/subscription.sh` (que lo auto-registra en el CMS), generar tráfico contra su API,
  cerrar el bucket horario y forzar la rotación de rollups en el despliegue, y luego sincronizar desde
  el CMS — bien con `POST /deployments/{id}/sync`, bien con
  `php artisan cms:sync-deploys --id=<deployment_id>` (`API_PACKAGE=Cms`, instancia en
  `https://localhost:8443`). Tras el sync, verificar que las filas de `cms_usage_snapshots` de ese
  despliegue ya reflejan los conteos reales y que la gráfica de plataforma ha cambiado de forma para
  ese despliegue mientras los demás siguen con su curva seed.
- **Robustez de la flota mixta:** con despliegues del seed (URLs ficticias) coexistiendo con el
  despliegue vivo, la gráfica de plataforma siempre renderiza, porque lee de la caché local y nunca
  llama a los `/control/usage` remotos.
- **Auto-sync programado:** `CmsServiceProvider` registra `cms:sync-deploys` cada hora
  (`->hourly()->withoutOverlapping()`); el comando salta los despliegues inalcanzables (los del seed)
  sin fallar, y refresca los snapshots de los vivos.

## Gotchas

- **Las gráficas NO hacen N llamadas en vivo.** Es deliberado: leen exclusivamente de la caché local
  `cms_usage_snapshots`. Un despliegue caído o con URL ficticia no afecta a la gráfica de plataforma;
  solo deja de actualizarse esa porción de la caché hasta el próximo sync exitoso.
- **El upsert depende de claves de hora idénticas.** Tanto el seeder como `applySnapshots()` usan
  `"${id}:hour:${epoch}"` con el timestamp del **inicio de hora**. Si el despliegue emitiera buckets
  con un `period_start` desalineado respecto al `startOfHour()` del seed, generaría filas paralelas en
  vez de sobrescribir las seed. En la práctica ambos lados normalizan al inicio de hora, así que
  cuadran.
- **La hora en curso no aparece en los datos reales.** `core:usage:rollup` solo agrega buckets de hora
  cerrada, de modo que la última hora del despliegue vivo no tendrá snapshot real hasta que cierre y se
  vuelva a sincronizar. Mientras tanto, esa hora conserva su valor seed (o queda a cero si ya fue
  sobrescrita por un sync anterior).
- **El refresco de 30s del Dashboard conserva la última serie buena.** Si una recarga periódica falla,
  la gráfica NO se vacía: se mantiene la última serie correcta y se registra el error. La pestaña Usage
  no tiene este intervalo (carga una sola vez), así que ahí un fallo inicial sí muestra el placeholder
  de error.
- **El permiso del endpoint es `index deployments`, no uno propio de usage.** Cualquier operador que
  pueda listar despliegues puede leer la usage agregada de la plataforma.

## Ficheros tocados

- `src/packages/Cms/database/migrations/2026_06_15_000007_create_cms_usage_snapshots_table.php` —
  tabla `cms_usage_snapshots` (caché local por-despliegue por-hora).
- `src/packages/Cms/app/Models/UsageSnapshotModel.php` — modelo Eloquent de la caché.
- `src/packages/Cms/database/seeders/CmsDatabaseSeeder.php` — `seedUsageSnapshots()` (48 horas
  deterministas por despliegue; dashboard poblado de fábrica).
- `src/packages/Cms/app/Services/CmsDeploymentSyncService.php` — `applySnapshots()` (upsert de los
  snapshots reales durante el `sync`).
- `src/packages/Cms/app/Http/Controllers/CmsUsageController.php` — `platform()` (lectura agregada de la
  caché).
- `src/packages/Cms/routes/api.php` — ruta `GET usage/platform`.
- `src/packages/Cms/app/Console/Commands/CmsSyncDeploysCommand.php` y
  `src/packages/Cms/app/Providers/CmsServiceProvider.php` — comando `cms:sync-deploys` y su
  programación horaria, que también refrescan los snapshots de los despliegues vivos.
- `cms/src/lib/chartData.ts` — transformer `snapshotsToPlatformSeries()` y tipo `PlatformSeries`.
- `cms/src/lib/api.ts` — cliente `platformUsage()`.
- `cms/src/store/types.ts` — tipo `UsageSnapshot`.
- `cms/src/pages/Dashboard.tsx` y `cms/src/pages/Usage.tsx` — gráficas de plataforma cableadas a
  `GET /usage/platform`.
