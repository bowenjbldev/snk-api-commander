# Package 1:1 refactor handoff

## Estado actual

El backend de Commander quedo estructuralmente migrado a modelo 1:1:

- Eliminada la migracion `commander_deployments`.
- Eliminados `CommanderDeploymentModel` y `CommanderDeploymentsController`.
- `commander_packages` absorbe la entidad operativa:
  - `client_id`
  - `subscription_id`
  - `requests_24h`
  - `quota_health`
  - `last_seen`
  - estado operativo (`status`, `reason`, `message`, `grace_until`, `changed_by`, `changed_at`)
- `commander_subscriptions`, `commander_invoices`, `commander_quotas`, `commander_activity_events` y `commander_usage_snapshots` pasan de `deployment_id` a `package_id`.
- `CommanderPackagesController` concentra acciones y lecturas operativas:
  - `POST /packages/{package}/suspend`
  - `POST /packages/{package}/grace`
  - `POST /packages/{package}/resume`
  - `GET /packages/{package}/usage`
  - `GET /packages/{package}/logs`
  - `DELETE /packages/{package}`
- `POST /provisioning` crea directamente la fila viva de `commander_packages` y devuelve `{ package: ... }`.
- Permisos del seeder cambiados de `* deployments` a `* packages`, con custom:
  - `control packages`
  - `provision packages`

Importante: no usar `/instances` ni crear una entidad intermedia nueva. La entidad es package.

## Verificacion hecha

Comandos ya ejecutados:

```bash
rg -n "CommanderDeployment|commander_deployments|deployment_id|deployments|Deployments|Deployment" src/packages/Commander -g '!vendor'
rg -n "runtime|Runtime|has_runtime|findRuntime" src/packages/Commander -g '*.php'
php -l src/packages/Commander/app/Http/Controllers/CommanderPackagesController.php
php -l src/packages/Commander/app/Http/Controllers/CommanderProvisioningController.php
php -l src/packages/Commander/app/Console/Commands/CommanderRefreshCommand.php
php -l src/packages/Commander/app/Services/CommanderQuotaMeasurerService.php
php -l src/packages/Commander/app/Models/CommanderPackageModel.php
php -l src/packages/Commander/routes/api.php
```

Resultado: sin referencias backend restantes a deployments/deployment_id y sin errores de sintaxis en esos PHP.

No se ejecuto aun:

```bash
docker compose exec app php artisan core:migrate --fresh --force
docker compose exec app php artisan core:seed --force
docker compose exec app php artisan test
bash scripts/simulate.sh
```

## Pendiente SPA

La SPA aun esta a medio migrar. Se comenzo el contrato en:

- `commander/src/store/types.ts`
- `commander/src/lib/package.ts`

Pero todavia quedan muchas referencias a `Deployment`, `deployments`, `deployment_id` y `/deployments`.

Tareas pendientes:

1. Cambiar el store de `deployments` a packages provisionados.
   - Cargar desde `packagesApi.list()` usando `registered.package`.
   - Eliminar `api<Deployment[]>('/deployments')`.
   - Cambiar acciones a:
     - `/packages/${name}/suspend`
     - `/packages/${name}/grace`
     - `/packages/${name}/resume`
     - `DELETE /packages/${name}`
   - Cambiar invoices/subscriptions/quotas/activity/snapshots a `package_id`.

2. Actualizar componentes y paginas.
   - `StateCard`
   - `ActionsProvider`
   - `Dashboard`
   - `Usage`
   - `Clients`
   - `Invoices`
   - `RequestLogs`
   - `CommandPalette`
   - `InvoiceFormModal`
   - `OverdueEnforcementModal`
   - tabs de `PackageDetail`

3. Eliminar la tab `Deployments`.
   - Borrar `commander/src/pages/PackageDetail/DeploymentsTab.tsx`.
   - Quitar lazy import/ruta en `commander/src/router.tsx`.
   - Quitar tab en `PackageDetail.tsx`.
   - Mover el CTA de provisionado al empty state de `Overview`.

4. Actualizar helpers y chart data.
   - Usar `packageBaseUrl` / `packageFqdn`.
   - Cambiar `deploymentUsage` y `deploymentLogs` a `packageUsage(name)` y `packageLogs(name)`.
   - `platformLogs` debe filtrar por `package`, no por `deployment_id`.
   - `snapshotsToPlatformSeries` debe usar `package_id`.

5. Actualizar textos visibles.
   - Reemplazar "deployment" por "package" o "provisioned package" solo donde sea necesario.
   - Mantener "deploy/provision" como verbo si encaja, pero no como entidad principal.

6. Actualizar docs.
   - `commander/README.md`
   - `design/README.md`
   - Documentar que `commander_packages` es la entidad viva 1:1 y que no existe `commander_deployments`.

## Verificacion final pendiente

Despues de terminar la SPA:

```bash
cd commander
./node_modules/.bin/tsc -b
docker compose exec commander-web npm test
cd ..
docker compose exec app php artisan core:migrate --fresh --force
docker compose exec app php artisan core:seed --force
docker compose exec app php artisan test
bash scripts/simulate.sh
```

Nota: el `npm install` local falla por permisos en `commander/node_modules`; usar el `tsc` local existente y tests dentro del contenedor `commander-web`.
