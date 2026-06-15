# SnakeCloud Commander — Documentación

Este directorio documenta, fase a fase y de extremo a extremo, **el panel de operador
(Commander) de SnakeCloud y su plano de control**: cómo un operador gobierna decenas de
despliegues de cliente autónomos desde una sola consola, y por qué cada pieza está donde
está. Está escrito para que una persona pueda revisarlo con calma y entender el *porqué* de
cada decisión, no solo el *qué*.

## El problema, en una frase

SnakeCloud aloja despliegues aislados de clientes (estilo Heroku/IONOS). Cada despliegue es
**autónomo**: si el panel se cae, los clientes siguen siendo servidos. El Commander no es un proxy
por el que pase el tráfico, sino una **consola que ordena**: ve el estado/uso/facturación de
cada despliegue y puede suspenderlo, ponerlo en gracia, reactivarlo, rotar su secreto o
sincronizarlo, llamando a la API firmada `/control` de cada uno.

```
   Operador (navegador)
        │  HTTPS + Bearer
        ▼
  ┌───────────────┐     /control firmado HMAC      ┌──────────────────┐
  │  Commander           │ ─────────────────────────────▶ │  Despliegue A     │  (Core, 402 si suspendido)
  │  (paquete Commander  │ ─────────────────────────────▶ │  Despliegue B     │
  │   + SPA React) │ ─────────────────────────────▶ │  Despliegue C     │
  └───────────────┘   (la frontera de confianza)    └──────────────────┘
        ▲  registro propio (clients, deployments, invoices, quotas…)
```

## Por qué fueron ocho fases

Construir un plano de control multi-tenant real no es una sola tarea: es una pila de
capacidades, y cada una se construyó y se **verificó en vivo de forma independiente** antes
de seguir. Esa es la razón de "tanto trabajo". El orden no es arbitrario: cada fase depende
de la anterior.

| Fase | Qué se construyó | Por qué hacía falta | Documento |
|------|------------------|---------------------|-----------|
| 1 | Registro + auth del paquete `Commander` | El panel necesita su propia fuente de verdad y un login antes de poder controlar nada | [`03-fase-1-registro-auth.md`](03-fase-1-registro-auth.md) |
| 2 | Proxy `/control` firmado (cliente HMAC + acciones) | Es el **mecanismo** de control remoto: suspender/gracia/reactivar/sync llamando a cada despliegue | [`04-fase-2-proxy-control.md`](04-fase-2-proxy-control.md) |
| 3 | Cablear el SPA al API real (login + datos + acciones) | Convierte la maqueta con datos falsos en una app real | [`05-fase-3-cablear-spa.md`](05-fase-3-cablear-spa.md) |
| 4 | Demo multi-deploy viva (`subscription.sh` ↔ control plane) | Hace que el panel controle despliegues **reales** end-to-end (suspend → 402) | [`06-fase-4-demo-multideploy.md`](06-fase-4-demo-multideploy.md) |
| 5 | Charts de uso reales por despliegue | La pestaña Usage pasa de datos sintéticos a métricas reales (`core_usage_rollups`) | [`07-fase-5-charts-uso.md`](07-fase-5-charts-uso.md) |
| 6 | Charts de plataforma reales (caché de snapshots) | El Dashboard agrega uso real sin depender de que toda la flota esté viva | [`08-fase-6-charts-plataforma.md`](08-fase-6-charts-plataforma.md) |
| 7 | Rotación real del secreto de control (3 capas, runtime) | Último stub que faltaba: invalidar el secreto viejo sin reiniciar el despliegue | [`09-fase-7-rotacion-secreto.md`](09-fase-7-rotacion-secreto.md) |
| 8 | Hardening + cierre (auto-sync, CORS, auditoría) + arreglos operativos | Robustez para uso real: scheduler, CORS, auditoría, y los problemas de concurrencia sqlite/rate-limit que aparecieron al usarlo | [`10-fase-8-hardening.md`](10-fase-8-hardening.md) |

## Por dónde empezar a leer

1. **[`01-arquitectura.md`](01-arquitectura.md)** — el mapa: los tres componentes, la frontera
   de confianza, el stack y el modelo de datos. Empieza aquí.
2. **[`02-plano-de-control.md`](02-plano-de-control.md)** — la pieza central: el contrato HMAC
   byte a byte, la máquina de estados (`active`/`grace`/`suspended`) y la rotación de secreto.
3. Las **fases 1–8** en orden (tabla de arriba). Cada una sigue la misma estructura: Contexto
   (por qué) → Qué se construyó → Decisiones clave → Cómo funciona → Verificación → Gotchas →
   Ficheros.
4. **[`11-flujos.md`](11-flujos.md)** — las secuencias paso a paso (login, suspender, sync,
   rotar, aprovisionar…) si prefieres ver el sistema "en movimiento".
5. **[`12-operaciones.md`](12-operaciones.md)** — el runbook: levantar el stack, sembrar,
   aprovisionar un despliegue, comandos artisan y resolución de problemas.

## Índice completo

| # | Documento | Contenido |
|---|-----------|-----------|
| — | [`README.md`](README.md) | Este índice |
| 01 | [`01-arquitectura.md`](01-arquitectura.md) | Componentes, stack, modelo de datos, frontera de confianza |
| 02 | [`02-plano-de-control.md`](02-plano-de-control.md) | Contrato HMAC, endpoints, máquina de estados, rotación |
| 03 | [`03-fase-1-registro-auth.md`](03-fase-1-registro-auth.md) | Fase 1 — Registro + auth |
| 04 | [`04-fase-2-proxy-control.md`](04-fase-2-proxy-control.md) | Fase 2 — Proxy `/control` firmado |
| 05 | [`05-fase-3-cablear-spa.md`](05-fase-3-cablear-spa.md) | Fase 3 — Cablear el SPA |
| 06 | [`06-fase-4-demo-multideploy.md`](06-fase-4-demo-multideploy.md) | Fase 4 — Demo multi-deploy viva |
| 07 | [`07-fase-5-charts-uso.md`](07-fase-5-charts-uso.md) | Fase 5 — Charts de uso por despliegue |
| 08 | [`08-fase-6-charts-plataforma.md`](08-fase-6-charts-plataforma.md) | Fase 6 — Charts de plataforma |
| 09 | [`09-fase-7-rotacion-secreto.md`](09-fase-7-rotacion-secreto.md) | Fase 7 — Rotación de secreto |
| 10 | [`10-fase-8-hardening.md`](10-fase-8-hardening.md) | Fase 8 — Hardening + cierre |
| 11 | [`11-flujos.md`](11-flujos.md) | Flujos end-to-end (diagramas de secuencia) |
| 12 | [`12-operaciones.md`](12-operaciones.md) | Runbook: ejecutar, aprovisionar, depurar |

Documentos de diseño previos, fuera de esta serie pero relacionados: `docs/CONTROL-PLANE.md`
(la especificación original del control plane desde el lado del despliegue) y `docs/DESIGN.md`
(el prompt de diseño de la UI). El `README.md` de la raíz cubre el modelo modular de paquetes
y la simulación local de suscripciones.

## Glosario rápido

- **Despliegue** — la instancia aislada de un cliente (contenedor + BD), corriendo el paquete
  `Core` + un paquete de negocio. Expone `/api/v1` y, si tiene secreto, `/api/v1/control/*`.
- **Plano de control (`/control`)** — la API firmada HMAC por la que el Commander gobierna un
  despliegue: `state`, `usage`, `rotate-secret`.
- **Kill-switch** — el middleware del despliegue que devuelve `402` en rutas de negocio cuando
  está `suspended`. Exime el health (`/api/v1`) y `/api/v1/control/*`.
- **`control_secret`** — el secreto HMAC compartido por despliegue. El Commander lo guarda cifrado;
  el despliegue lo lee del entorno o, tras una rotación, de `core_package_state`.
- **Espejo / mirror** — la copia local en el Commander del estado del despliegue (columnas planas de
  `commander_deployments`), que la SPA lee. Se actualiza al ejecutar una acción o un `sync`.
- **Snapshot de uso** — fila de `commander_usage_snapshots`: caché local por despliegue y hora que
  alimenta los charts de plataforma sin llamar a cada despliegue en cada carga.

## Estado

Las ocho fases están implementadas y verificadas: pruebas automáticas (backend `php artisan
test`, SPA `npm run test`) en verde, build de producción del SPA limpio, y **verificación en
vivo en cada fase** (aprovisionar un despliegue real, ejercer el loop completo —incluida la
rotación de secreto con el viejo dando `401` y el nuevo `200`— y desmontar). Una revisión
adversarial final corrigió tres defectos de correctitud en la SPA. Los detalles de
verificación están en cada documento de fase y en [`12-operaciones.md`](12-operaciones.md).
