# Negocio: Kilkana como SaaS

## Propuesta

Kilkana es un CMS SaaS para prensa digital. Su promesa comercial es concreta: **un CMS que
sale con el SEO y la calidad de publicación resueltos**, sin exigir al periódico un equipo
técnico propio.

El cliente natural es un periódico local o comarcal; después, revistas digitales y medios de
nicho. El producto combina flujo editorial, portada compuesta, medios, autores, comentarios,
boletín y salida pública. El repositorio hermano `kilkana.app` contiene la experiencia editorial
y pública; este repositorio contiene su API y la plataforma que la opera.

La segunda pieza de valor es la lista de correo: es audiencia propiedad del medio, no prestada
por buscadores o redes sociales.

## Decisión de plataforma

Commander es el control plane de Kilkana. No se construirá un segundo panel de infraestructura
solo para este producto.

Cada alta genera una instancia operativa propia:

```text
cliente ElConfidencial + producto Kilkana
  → fila propia en commander_packages
  → suscripción, cuotas, facturas y observabilidad propias
  → base aislada elconfidencial_kilkana
  → URL elconfidencial.<dominio>/api/v1/kilkana
```

El código de Kilkana sigue siendo uno y compartido en `src/packages/Kilkana`. No se crea una
carpeta vacía por cliente. Si algún cliente necesita una diferencia, se añade entonces un overlay
como `src/packages/ElConfidencial/Kilkana`, sin bifurcar el producto completo.

Esto separa tres identidades que antes se mezclaban:

| Identidad | Representación |
|---|---|
| producto vendible | `Kilkana` y sus planes |
| código compartido | `src/packages/Kilkana` |
| contrato e instancia de un cliente | fila numérica de `commander_packages` + su BD |

Aunque una instancia no tenga personalización, su fila individual no sobra: es donde viven su
estado, sus límites, su suscripción, sus facturas y el punto de extensión si un día la necesita.

## Identidad de marca y dominios

SnakePC y Kilkana no son el mismo ámbito comercial:

| Ámbito | Función | Dominio local previsto |
|---|---|---|
| SnakePC | portfolio, plataforma y control plane privado | `commander.snakepc.test` |
| Kilkana | producto SaaS para periódicos | `{cliente}.kilkana.test` |

El dominio propio de Kilkana es una fase futura. Mientras no se desacople el dominio de Commander
del dominio de tenants, el entorno seguirá usando un solo `BASE_DOMAIN`. La decisión y el trabajo
técnico necesario están fijados en [Arquitectura](architecture.md#ámbitos-de-dominio-snakepc-y-kilkana).

## Qué existe en este repositorio

| Capacidad | Estado actual |
|---|---|
| catálogo de productos desplegados | Commander lo sincroniza desde paquetes con rutas |
| clientes y planes | CRUD real en Commander |
| alta de instancia | asíncrona, con cliente nuevo o existente y admin tenant propio |
| aislamiento | una BD y un usuario runtime restringido por instancia |
| suscripciones | términos históricos con snapshot de nombre y precio |
| facturas | ledger manual con estados y acciones de enforcement |
| cuotas | requests/día, disco BD, disco de ficheros y usuarios totales |
| estado | active, grace y suspended con kill-switch |
| observabilidad | logs, rollups de uso, KPIs e insights Git |
| cobro | no hay pasarela, adeudo SEPA ni conciliación automática |
| autoservicio público | no existe; el alta la hace el operador desde Commander |

Kilkana aporta en su backend publicaciones, URLs con historial, autores, secciones, biblioteca de
medios, composición, ajustes, comentarios, suscriptores, roles y audiencia. El contrato del esquema
está en [Base de datos](database/kilkana/README.md).

## Plan comercial objetivo

La siguiente tabla es una **hipótesis de precio interna**, no una capacidad aplicada hoy por el
código ni una promesa publicada:

| Plan | Precio objetivo | Asientos | Disco | Lecturas/mes |
|---|---:|---:|---:|---:|
| Starter | 29 € | 5 | 20 GB | 50.000 |
| Pro | 59 € | 15 | 60 GB | 150.000 |
| Business | 99 € | ilimitados | 120 GB | 500.000 |
| Grupo | a medida | a medida | a medida | a medida |

`Grupo` es un contrato comercial sobre varias cabeceras, no una instancia más grande ni una BD
compartida. Commander las agrupa mediante `commander_accounts`, pero cada cabecera conserva su
cliente, instancia, suscripción, cuotas y base. La factura consolidada y cualquier negociación de
asientos entre cabeceras siguen siendo trabajo de billing pendiente.

Un cliente fuera de Grupo también tiene cuenta comercial, en relación 1:1 con su única cabecera.
Así billing parte siempre de la misma raíz y no existe un segundo flujo sin cuenta.

Principios comerciales propuestos:

- todas las capacidades first-party incluidas, sin gate por lista de funciones;
- prueba de 14 días, sin plan gratuito permanente;
- aviso al 80 % y sin factura sorpresa por un pico puntual;
- cambio de nivel solo ante exceso sostenido, medido sobre una ventana que absorba la volatilidad
  de una noticia viral;
- migración y atención ante caída incluidas;
- extensiones de terceros cerradas hasta poder verificar su impacto en SEO, accesibilidad y
  rendimiento.

Las cifras, competencia y costes que originaron esta propuesta estaban fechados en julio de 2026
en `KILKANA-NEGOCIO.md`, un documento externo al repositorio. Deben volver a verificarse antes de
publicar precios o tomar una decisión financiera.

## Diferencia entre cuotas actuales y modelo objetivo

Commander todavía mide un contrato genérico de API:

| Métrica actual | Sirve para Kilkana | Diferencia pendiente |
|---|---|---|
| `users_total` | aproximación a asientos | cuenta también lectores y cuentas de servicio; hace falta contar puestos facturables |
| `disk_db_mb` | sí | el plan objetivo habla de disco total, no de una banda separada |
| `disk_storage_mb` | sí | debe sumarse o presentarse junto al disco de BD según contrato |
| `requests_day` | no como eje comercial | Kilkana quiere lecturas de artículo por mes, medidas desde navegador |

Para cobrar los planes objetivo faltan, como mínimo:

1. `editorial_seats`: roles facturables, excluyendo `reader` y cuentas de servicio.
2. `reads_month`: suma de `kilkana_post_views.engaged`, no requests HTTP ni renders.
3. Una ventana comercial definida —la propuesta es media móvil trimestral— y avisos al 80 %.
4. Política de upgrade sostenido; el motor actual aplica `none | warn | suspend`, no cambia planes.
5. Pasarela y conciliación. Commander hoy registra facturas, pero no cobra.

No se deben renombrar las métricas genéricas para aparentar esta equivalencia: son preguntas
distintas y producirían facturación incorrecta.

## Flujo comercial operativo actual

1. Se despliega el código compartido de Kilkana.
2. Commander sincroniza su fila de catálogo.
3. El operador crea uno o varios planes asociados a `Kilkana`.
4. El operador provisiona un cliente, sus credenciales de admin y un plan.
5. El worker crea la BD y activa la instancia.
6. Commander gestiona estado, plan, cuotas, uso, facturas y baja.
7. Solo si aparece una necesidad contractual se despliega un overlay del cliente.

El alta desde Commander es suficiente para operar el SaaS de forma asistida. Convertirla en alta
pública autoservicio es otra fase: requiere registro, verificación, aceptación contractual, pago,
recuperación de fallos y onboarding de marca. No debe exponerse el endpoint del operador como si
fuera ese producto.

## Prioridades para Commander

Orden recomendado para convertir la operación asistida en negocio repetible:

1. estabilizar el ciclo de vida de provisioning con estados explícitos, reintentos y
   reconciliación;
2. implementar métricas comerciales específicas de Kilkana sin romper las genéricas;
3. añadir alertas y proyección antes de enforcement o cambio de plan;
4. integrar cobro mediante un proveedor, no construir pagos desde cero;
5. solo después, abrir autoservicio y onboarding público.

## Reglas de producto

- La personalización no justifica duplicar Kilkana entero; un overlay contiene solo diferencias.
- Un plan no sustituye al código: si no hay implementación compartida ni exclusiva, el alta falla.
- El éxito del cliente no debe producir una factura imprevisible por una noticia viral.
- La salida pública es el diferenciador. Un plugin o tema no puede degradarla sin que un gate lo
  detecte.
- El audio generado consume mucho disco; si se incorpora, debe ser opt-in y mostrar el impacto
  antes de acciones masivas.
- El plan inicial de una migración debe considerar la hemeroteca importada, no solo el tráfico del
  primer mes.
