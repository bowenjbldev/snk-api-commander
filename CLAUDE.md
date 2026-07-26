# snk-api-commander — backend

API modular sobre Laravel 13, multi-tenant por base de datos. **Un solo despliegue sirve
todos los clientes y paquetes.** La arquitectura completa (resolución, provisioning,
control plane, despliegue) está en [`README.md`](README.md) — léelo antes de tocar Core,
Commander, rutas o provisioning.

## Modelo mental

- **Cliente = subdominio · paquete = primer segmento del path · BD propia por par.**
  `snk.snake.test/api/v1/kilkana` → paquete `Snk/Kilkana`, BD `snk_kilkana`.
- `CoreResolvePackageFromHostMiddleware` conmuta la conexión default **en cada request**
  (cliente por `Host`, slug por argumento de middleware). El paquete activo no se elige en
  boot: en boot se cargan todos.
- **Nivel 1 de `packages/` = SOLO framework** (`Core`, `Commander`). Cualquier otra carpeta
  de nivel 1 es un paraguas de cliente y sus hijos son paquetes.
- **El único cliente que existe hoy es `Snk`, con un paquete: `src/packages/Snk/Kilkana`.**
  `Acme/Shop` e `Initech/Crm` del README son **ejemplos ilustrativos, no código real** — no
  los busques ni los tomes como referencia de estado.
- Clases con **prefijo cliente+paquete (N2)**: `SnkKilkanaPostsController`,
  `SnkKilkanaDatabaseSeeder`. Namespace `Packages\Snk\Kilkana\`. Framework: `Core*`,
  `Commander*`.
- Toda la lógica de negocio vive en `packages/`, nunca en `app/`.
- Solo Core escribe claves globales de Laravel. Los configs de paquete van namespaced
  (`config('kilkana.archivo.key')`).

## Reglas de BD (sagradas, del usuario)

1. **PK entera autoincremental siempre** (`$table->id()`); las claves de negocio van en
   columnas `UNIQUE`.
2. **Prefijo del paquete lógico en snake_case**: `kilkana_posts`, `commander_packages`. No
   lleva el cliente — la BD ya aísla por par.
3. **Orden de columnas**: PK → **todas** las FKs → columnas de negocio por relevancia →
   `timestamps()` **siempre al final**.

La BD `commander` **no se recrea**: una migración que añada columnas ahí va guardada por
`hasColumn`.

## Comandos

Todo vía Docker (servicios: `app` · `scheduler` · `provisioner` · `nginx` · `db` ·
`commander-web`). El `provisioner` es el único con permisos DDL.

```bash
docker compose exec -T provisioner php artisan core:migrate --client=snk --package=Kilkana [--fresh]
docker compose exec -T provisioner php artisan core:seed    --client=snk --package=Kilkana
docker compose exec -T app php artisan test                  # tests (sqlite, sin MariaDB)
docker compose exec -T db sh -c 'mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" snk_kilkana -e "…"'
```

**Regla de los flags**: `--client` + `--package` juntos ⟹ tenant (BD `{cliente}_{paquete}`);
sin ninguno ⟹ commander. Nunca uno solo.

Credenciales sembradas: `admin@snake.com` / `secret1234` (una por BD) · lector demo
`reader@snk.test` / `password`.

## Gotchas que ya mordieron

- **`Hash::make` en un seeder = doble hash** → `Invalid credentials`. `CoreUserModel` castea
  `password => 'hashed'`: pasa la password **en claro**.
- El modelo de usuarios es `Packages\Core\Models\CoreUserModel`, **no** `App\Models\User`.
- El binario de MariaDB es **`mariadb`**, no `mysql`.
- **`core:migrate --fresh` vacía layouts y settings de Kilkana** → portada pública en blanco.
  El seeder es un snapshot (`database/seeders/data/*.json`), así que `core:seed` los repone.
- El servicio `scheduler` corre `schedule:work` de verdad (`kilkana:publish-scheduled` cada
  minuto + los `commander:*`). Un cambio en un comando programado se prueba ahí, no a mano.

## Verificar

`docker compose exec -T app php artisan test` en verde antes de dar por hecho un cambio.

## Docs

[`README.md`](README.md) backend · [`commander/README.md`](commander/README.md) SPA React +
Carbon del panel · [`design/README.md`](design/README.md) sistema de diseño ·
[`../AGENTS.md`](../AGENTS.md) mapa cross-proyecto con `kilkana.app`.
