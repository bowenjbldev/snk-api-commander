---
name: kilkana-style
description: Cómo se escribe la línea en snk-api-commander. Úsala SIEMPRE al crear o editar PHP de este repositorio —controladores, modelos, servicios, enums, migraciones, seeders, comandos, tests— y al redactar el commit. Lleva el contrato de CoreBaseApiController, la nomenclatura N2, el enum como fuente única, la forma de una migración y las trampas ya pagadas.
---

# Cómo se escribe la línea aquí

Backend Laravel 13 y control plane de un SaaS multi-tenant. Una app sirve a todos: el cliente es el
subdominio, el producto es el segmento tras `/api/v1`, y cada par `(cliente, producto)` tiene su
base propia. El producto compartido vive en `src/packages/<Paquete>`, el overlay opcional en
`src/packages/<Cliente>/<Paquete>`.

Esta skill **no repite la arquitectura**: eso es de [`AGENTS.md`](../../../AGENTS.md) y de
[`docs/`](../../../docs/README.md), que son la fuente única. Aquí está lo que no está escrito en
ningún sitio: **la forma de la línea**.

## Lo que no se negocia

- **La lógica vive en `src/packages`, no en `src/app`.** Config propia siempre namespaced.
- **Prefijo N2 en toda clase**: `CoreBaseApiController`, `CommanderInstanceModel`,
  `SnkKilkanaPostsController`. El namespace no lo lleva (`Packages\Kilkana\Services`). Única
  excepción sin `Snk`: `KilkanaServiceProvider`, porque el discovery exige `{ClassBase}ServiceProvider`.
- **Una lista cerrada vive en un enum y de ahí salen la columna y la regla `in:`.** Dos copias de
  una lista es como una acaba aceptando lo que la otra no sabe escribir.
- **Tres reglas de BD**: PK entera autoincremental siempre; prefijo del paquete lógico
  (`kilkana_*`); orden PK → FKs → negocio por relevancia → timestamps al final.
- **Una migración ya aplicada no se reescribe.** Se crea una forward migration.
- **Valida nombres de host, BD y usuario en toda frontera.** Nada de interpolar entrada sin validar
  en DDL.
- **El worker `provisioner` es el único con DDL.** El usuario runtime solo tiene CRUD.
- **Comenta el porqué de lo no obvio**, en español, en presente. Nunca lo que el código ya dice,
  nunca la historia, nunca una arquitectura futura como si existiera.

## Qué leer antes de tocar

La tabla vive en [`AGENTS.md`](../../../AGENTS.md) y no se copia aquí. En corto: Core/routing →
`docs/architecture.md`; provisioning/cuotas → `docs/flows.md`; migraciones Commander →
`docs/commander-model.md`; Kilkana → `docs/business.md` y `docs/database/kilkana/README.md`;
comandos/Docker/deploy → `docs/operations.md`.

## El orden de trabajo

1. `git branch --show-current` y `git status --short`. Preserva cambios ajenos.
2. **Lee entero el código afectado y todos sus callers antes de editar.** El arreglo va en la capa
   que concentra el comportamiento, no en la puerta desde la que se reportó.
3. Cambio mínimo. Si la regla la necesitan dos puertas, sube al enum o a un método estático del
   servicio — no se copia.
4. Prueba que **falle sin el cambio** cuando haya lógica no trivial.
5. Puertas proporcionales (abajo).
6. Actualiza el documento canónico si cambia arquitectura, contrato, esquema, flujo u operación.

## Las puertas

```bash
docker compose exec -T app php artisan test
cd src && vendor/bin/pint --test && composer audit
```

Los tests corren SQLite: **no validan permisos DDL, MariaDB, collation ni `information_schema`**.
Eso se prueba contra MariaDB. Reporta test, build y lint por separado: uno verde no implica los
demás.

## Las dos referencias

| Abre… | Cuando… |
|---|---|
| [`references/php-laravel.md`](references/php-laravel.md) | Escribas o edites cualquier `.php`: controlador, modelo, servicio, enum, migración, seeder, comando o test |
| [`references/commits-y-docs.md`](references/commits-y-docs.md) | Redactes el commit, o tengas que decidir dónde va un documento nuevo |
