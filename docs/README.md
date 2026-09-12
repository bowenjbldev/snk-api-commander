# Documentación de Snake API Commander

Esta carpeta es la fuente canónica del proyecto. Describe el código de esta rama y separa lo
implementado, los diseños futuros y la evidencia histórica.

## Lectura inicial

1. [Modelo de Commander](commander-model.md): vocabulario y relaciones de negocio.
2. [Arquitectura](architecture.md): cómo se resuelve y aísla una petición tenant.
3. El documento específico de la tarea.

## Mapa por necesidad

| Necesidad | Documento | Estado |
|---|---|---|
| routing, packages, overlays y aislamiento | [Arquitectura](architecture.md) | implementado |
| nombres, comentarios y en qué idioma va cada cosa | [Idioma del código](code-language.md) | convención vigente |
| cuentas, clientes, aplicaciones, planes e instancias | [Modelo de Commander](commander-model.md) | implementado |
| conexiones, tablas y relaciones | [Base de datos](database.md) | implementado |
| provisioning, estados, cuotas y baja | [Flujos](flows.md) | implementado |
| Docker, comandos, migraciones y deploy | [Operación](operations.md) | implementado |
| Octane, FrankenPHP, capacidad y benchmarks | [Rendimiento](performance.md) | implementado y medido |
| producto editorial Kilkana | [Kilkana](products/kilkana/README.md) | implementado |
| tablas y reglas editoriales | [Modelo de datos Kilkana](products/kilkana/database/README.md) | implementado |
| dataset local | [Demo de Kilkana](products/kilkana/demo.md) | implementado |
| pantallas de Commander | [Diseño del SPA](design/commander-spa.md) | prototipo con mock |
| dominios propios | [Diseño de dominios](design/custom-domains.md) | no implementado |
| resultados anteriores | [Historial de rendimiento](history/performance-2026.md) | evidencia histórica |

## Modelo mental

- Commander guarda cuentas, clientes, aplicaciones, instancias, contratos, cuotas, facturas y
  observabilidad en la base `commander`.
- Cuenta es el pagador; cliente es el tenant; aplicación es el producto; instancia es el par
  cliente-aplicación.
- Cada instancia `ready` tiene una base `{cliente}_{producto}` con usuarios, tokens y datos
  propios.
- El `Host` identifica al cliente y el segmento tras `/api/v1` identifica la aplicación.
- El dominio local de API tenant es `{cliente}.commander.test`; Kilkana se selecciona con
  `/kilkana`.
- El código común vive en `src/packages/<Paquete>`; un overlay opcional contiene solo diferencias.
- Una cuenta de grupo puede compartir contrato y cuota agregada, pero nunca bases, usuarios ni
  tokens.

## Estado documental

Cada documento distingue uno de estos estados:

- **implementado**: debe corresponder al código y a los tests actuales;
- **diseño no implementado**: decisión preparada, sin presentarla como capacidad disponible;
- **evidencia histórica**: mediciones fechadas que explican una decisión vigente.

No se mantiene una copia manual exhaustiva de rutas. La referencia generada es OpenAPI en
`/api/v1/docs.json`; los Markdown explican semántica, invariantes y operación.

## Si algo discrepa

1. Código ejecutable: migraciones, rutas, modelos y servicios.
2. Tests que fijan contratos.
3. Documentación marcada como implementada.
4. Diseños futuros.
5. Evidencia y comentarios históricos.

Salvo el README de entrada, `AGENTS.md` y archivos propios de herramientas, los Markdown del
proyecto viven bajo `docs/`.
