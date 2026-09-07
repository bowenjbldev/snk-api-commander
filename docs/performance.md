# Rendimiento y capacidad

> Estado: implementado y medido. Las cifras son una referencia local, no una promesa de
> producción. El historial completo está en [Historial de rendimiento de 2026](history/performance-2026.md).

## Perfil vigente

La API HTTP corre sobre Laravel Octane 2 con FrankenPHP en worker mode. En el entorno local:

- doce workers por defecto, configurables con `OCTANE_WORKERS`;
- reciclaje cada 500 peticiones;
- límite de 6 CPU y 2 GiB para `app`;
- metering en lotes de veinte filas o cinco segundos;
- keepalive entre Nginx y FrankenPHP;
- OPcache con JIT tracing;
- una petición simultánea por worker.

PHP-FPM y Octane/Swoole permanecen como perfiles opt-in para repetir comparativas. La
conmutación tenant no admite corrutinas concurrentes dentro del mismo proceso.

## Resultado de referencia

La prueba editorial realista del 6 de septiembre de 2026 recorrió detalles, listados, filtros,
panel, mediateca, guardado de borradores y login sobre contenido real:

| Concurrencia | PHP-FPM | FrankenPHP | Mejora | p95 |
|---:|---:|---:|---:|---:|
| 8 | 109,5 req/s | 175,6 req/s | +60,4 % | 113,0 → 77,8 ms |
| 24 | 105,0 req/s | 171,4 req/s | +63,2 % | 307,3 → 201,5 ms |

Fueron 100.657 peticiones sin errores. La prueba utilizó un i7-7700K de cuatro núcleos físicos,
MariaDB 11.5.2 y un único tenant con contenido. No demuestra capacidad con cientos de tenants,
CDN, subidas de medios ni estabilidad durante horas.

## Coste del multitenant

En el proceso medido, resolver la instancia y abrir/cerrar su conexión añade aproximadamente
1,25 ms. Incluyendo estado y metering, el coste común queda alrededor de 1,43 ms.

Cada petición:

1. obtiene el cliente exclusivamente del `Host`;
2. obtiene la aplicación del segmento tras `/api/v1`;
3. consulta una instancia `ready` en Commander;
4. configura y abre la conexión tenant cuando se usa por primera vez;
5. restaura contexto, configuración y conexión en `finally`.

La conexión Commander puede sobrevivir en el worker. La conexión tenant se purga siempre; no se
mantiene un pool ilimitado por credencial.

## Cuellos de botella conocidos

- Lecturas ligeras se benefician especialmente de mantener Laravel cargado.
- Escrituras dependen más de MariaDB y los logins de Argon2id.
- A 100 req/s, registrar cada petición produce 8,64 millones de filas diarias; metering y rollups
  son el primer límite de almacenamiento.
- El rollup de p95 no debe conservar millones de duraciones en arrays PHP.
- Aumentar workers por encima del punto dulce local mejora poco el tráfico mixto porque la CPU y
  SQL ya dominan.
- El store de caché `database` añade demasiado coste al rate limit. Una segunda réplica debe
  compartir permisos y frenos mediante Redis o una alternativa medida.

## Herramientas de medida

### Capacidad general

```bash
bash scripts/simulate.sh smoke
bash scripts/simulate.sh load read cross-package 10 300
bash scripts/simulate.sh load write same-package 5 100
bash scripts/simulate.sh login-load 5 100
bash scripts/simulate.sh security
bash scripts/simulate.sh rate-limit
bash scripts/simulate.sh matrix
```

El simulador solo admite `APP_ENV=local` y dominios `.test`. Crea identidades efímeras y las
elimina al terminar; los request logs de metering permanecen.

### Comparativa de runtimes

```bash
docker compose --profile fpm --profile swoole up -d --build
bash scripts/octane-ab.sh all
```

Los runtimes deben usar el mismo código, límites, número de workers, reciclaje, metering y
generador. Se intercalan las rondas y se comparan medianas, no ejecuciones aisladas.

### Tráfico editorial realista

```bash
node --test scripts/realistic-load.test.mjs
bash scripts/realistic-ab.sh
```

El harness exige al menos veinte noticias publicadas, fija el código PHP a `HEAD`, valida el
contenido y limpia borradores, revisiones e identidades temporales. Los resultados quedan bajo
`OUTPUT_DIR` o en `/tmp/commander-benchmark-*`.

### Coste común y BFF

```bash
docker compose exec -T app php < scripts/common-cost.php
bash scripts/common-cost-http.sh
bash scripts/bff-cost.sh
```

Estas pruebas responden preguntas distintas: coste de middleware, capacidad HTTP y número de
llamadas de una pantalla. Sus cifras no se suman ni se extrapolan entre máquinas.

## Criterios de aceptación de un runtime

- Cero cruces de conexión, configuración, usuario, token, rol o permiso al alternar tenants.
- Cero `5xx`, timeouts o respuestas inválidas durante la matriz.
- Memoria estable durante al menos 100.000 peticiones con reciclaje.
- Mejora material de throughput y p95 frente a PHP-FPM con condiciones equivalentes.
- Purga de la conexión tenant al terminar incluso tras excepciones o desconexiones.
- Sin pool ilimitado de conexiones por tenant.

## Comprobación del resolutor

```bash
docker compose exec -T app php artisan test \
  --filter='CorePermissionRegistrarMemoTest|CoreResolverPermissionIsolationTest'
docker compose exec -T app php < scripts/core-resolver-check.php
```

La segunda comprobación usa MariaDB local, alterna tenants y excepciones, y verifica que cada
resolución ejecuta una consulta Commander y purga siempre el PDO tenant.
