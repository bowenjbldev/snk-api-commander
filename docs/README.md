# Documentación de Snake API Commander

Este directorio describe el backend y el control plane que existen en esta rama. El repositorio
no contiene todavía un cliente web; su diseño funcional futuro está documentado.

## Mapa de lectura

| Necesidad | Documento |
|---|---|
| entender negocio, entidades y cardinalidades | [Modelo de Commander](commander-model.md) |
| entender routing, packages, overlays y aislamiento | [Arquitectura](architecture.md) |
| entender la propuesta comercial de Kilkana | [Negocio](business.md) |
| tocar migraciones, modelos o relaciones | [Base de datos](database.md) y [Kilkana](database/kilkana/README.md) |
| seguir un alta, request, cuota o baja | [Flujos](flows.md) |
| preparar dominios propios, TLS y una migración SEO | [Dominios propios](domains.md) |
| diseñar o implementar el futuro SPA de Commander | [Diseño funcional del SPA](design/commander-spa.md) |
| ejecutar, probar, migrar o desplegar | [Operación](operations.md) |

## Modelo mental

- Commander guarda cuentas, clientes, aplicaciones, instancias, contratos, billing, cuotas y
  observabilidad en la base `commander`.
- Cuenta es el pagador; cliente es el tenant/subdominio; aplicación es el producto; instancia es
  el par único cliente-aplicación.
- Cada instancia `ready` tiene una base `{cliente}_{package}` con usuarios, tokens y datos de
  negocio aislados.
- El host identifica al cliente y el primer segmento tras `/api/v1` a la aplicación:
  `{cliente}.dom/api/v1/{aplicación}`.
- La aplicación se descubre desde `src/packages/<Paquete>`; un overlay opcional en
  `src/packages/<Cliente>/<Paquete>` contiene solo diferencias.
- Un plan pertenece a una aplicación. Un contrato pertenece a cuenta+aplicación y sus ítems
  asignan un plan operativo a cada instancia.
- Una cuenta normal tiene un cliente; una cuenta de grupo puede tener varias cabeceras sin
  compartir sus bases ni usuarios, aunque el contrato puede sumar una cuota común.

## Prioridad si algo discrepa

1. Migraciones, rutas, modelos y servicios ejecutables.
2. Tests que fijan el contrato.
3. Esta documentación.
4. Comentarios históricos y documentos externos.

Los Markdown del proyecto viven aquí, salvo el `README.md` de entrada y `AGENTS.md`.
