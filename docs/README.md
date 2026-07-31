# Documentación de Snake API Commander

Este directorio es la fuente canónica de contexto del repositorio. Describe el código que
existe en esta rama y separa explícitamente lo implementado de la dirección de producto.

## Mapa de lectura

| Si necesitas… | Lee… |
|---|---|
| entender el sistema completo | [Arquitectura](architecture.md) |
| decidir cómo encaja Kilkana como SaaS | [Negocio](business.md) |
| tocar migraciones, modelos o relaciones | [Base de datos](database.md) y el [detalle de Kilkana](database/kilkana/README.md) |
| seguir una petición, un alta o una baja | [Flujos](flows.md) |
| ejecutar, probar, migrar o desplegar | [Operación](operations.md) |
| cambiar la SPA o su lenguaje visual | [Commander UI](commander-ui.md) |

## Modelo mental en un minuto

- Hay una aplicación Laravel y un control plane llamado **Commander**.
- El subdominio identifica al cliente y el primer segmento tras `/api/v1` identifica al
  producto: `{cliente}.dom/api/v1/{paquete}`.
- La base `commander` guarda clientes, catálogo, instancias, planes, billing, cuotas,
  observabilidad y el operador.
- Cada par `(cliente, producto)` provisionado tiene otra base aislada con Core y sus tablas de
  negocio. No hay `tenant_id` en esas tablas.
- Un producto compartido vive en `src/packages/<Paquete>`. Un overlay opcional vive en
  `src/packages/<Cliente>/<Paquete>` y contiene solo diferencias.
- Para una ruta concreta gana el overlay; lo que no personaliza cae en el producto compartido.
- Commander crea registros y bases de datos, pero no genera ni clona código de producto.
- `provisioned_at` es la frontera de exposición: una fila pendiente no recibe tráfico.
- Las acciones operativas de Commander se direccionan por `commander_packages.id`, nunca por el
  nombre compartido del producto.

## Componentes

```text
Navegador del operador
        │
        ▼
commander.<BASE_DOMAIN> ── SPA `commander/`
        │ Bearer
        ▼
paquete Commander ──────── base `commander`
        │                         │
        │ encola DDL              ├─ catálogo, clientes, planes y billing
        ▼                         └─ estado, cuotas, logs y rollups
worker `provisioner`

Petición de lector/redacción
        │
        ▼
{cliente}.<BASE_DOMAIN>/api/v1/{paquete}
        │ resolve host + path
        ▼
Core ── producto compartido ── overlay opcional
        │
        ▼
base aislada `{cliente}_{paquete}`
```

## Prioridad cuando algo discrepa

1. Migraciones, rutas, modelos y servicios ejecutables.
2. Tests que fijan el contrato.
3. Esta documentación.
4. Comentarios históricos y documentos externos.

Si el código y la documentación divergen, corrige la documentación en el mismo cambio. Los
Markdown del proyecto deben vivir aquí, salvo el `README.md` de entrada y `AGENTS.md`.

## Alcance

Este repositorio contiene el backend y la SPA de Commander, además del backend compartido de
Kilkana. La salida pública y el panel editorial Svelte viven en el repositorio hermano
`kilkana.app`; las afirmaciones sobre ese frontend en [Negocio](business.md) son contexto de
producto, no un contrato verificable desde este árbol.
