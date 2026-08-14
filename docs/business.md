# Negocio: Kilkana sobre Commander

## Propuesta

Kilkana es un CMS SaaS para prensa digital: publicación, flujo editorial, portada, medios,
autores, comentarios, suscriptores y audiencia sin exigir al periódico un equipo técnico propio.

Commander es su único control plane. No habrá otro catálogo, billing o provisioning específico de
Kilkana. La semántica completa está en [Modelo de Commander](commander-model.md).

## Producto compartido, operación aislada

```text
producto vendible       commander_applications: Kilkana
código compartido       src/packages/Kilkana
cliente/cabecera        commander_clients: diario-norte
instancia               diario-norte + Kilkana
datos                    diario_norte_kilkana
```

Una personalización usa un overlay con solo diferencias, por ejemplo
`src/packages/DiarioNorte/Kilkana`. Nunca se duplica el producto entero por cliente.

## Cliente individual

Un periódico individual tiene normalmente:

- una cuenta comercial;
- un cliente/cabecera;
- una instancia Kilkana;
- un contrato de catálogo con capacidad para una instancia;
- un ítem con su plan operativo;
- una base, usuarios, cuotas y observabilidad propias.

El alta puede crear cuenta y cliente en una sola operación.

## Grupo editorial

Un grupo no es una macro-instancia. Es una cuenta que paga un contrato para varias cabeceras:

```text
Grupo Editorial (cuenta y factura)
├── Diario Norte  → subdominio/base/usuarios/cuotas propios
├── Diario Centro → subdominio/base/usuarios/cuotas propios
└── Diario Sur    → subdominio/base/usuarios/cuotas propios
```

El contrato negociado fija importe total, moneda y máximo de cabeceras. Cada ítem selecciona el
plan operativo de su instancia, por lo que una cabecera puede ser Basic y otra Business sin
convertir la suma de precios de catálogo en el precio contractual del grupo.

El administrador puede usar el mismo correo en varias cabeceras, pero son usuarios locales
distintos. Los tokens no cruzan bases. Una persona con rol editorial en dos diarios ocupa un
asiento en cada uno.

## Planes y precios

La referencia de trabajo para planes Kilkana es:

| Plan | Precio orientativo mensual |
|---|---:|
| Basic | 29 € |
| Pro | 59 € |
| Business | 99 € |
| Grupo | negociado |

Son decisiones comerciales documentadas, no seeders ni precios publicados. Commander no crea
estos planes automáticamente. Antes de venderlos deben aprobarse límites, impuestos, trial y
condiciones contractuales.

Todos los planes pertenecen a la aplicación Kilkana. Sus límites son plantillas de operación;
el importe realmente pactado vive en la suscripción.

## Métricas comerciales

Kilkana declara dos métricas propias además de las técnicas de plataforma:

| Métrica | Definición |
|---|---|
| `editorial_seats` | usuarios distintos con al menos un rol que no sea `reader` ni `service`; sin rol no cuenta |
| `reads_month` | suma de `kilkana_post_views.engaged` durante el mes |

No se usan requests HTTP como sustituto de lecturas ni `users_total` como sustituto de asientos.

Política acordada:

- aviso al 80 %;
- no suspender ni cambiar el plan de Kilkana automáticamente;
- no reaccionar a un único pico viral;
- lecturas: evaluar la media de los tres meses completos anteriores;
- asientos: evaluar la última muestra del mes completo anterior;
- proponer upgrade solo tras el número configurado de periodos consecutivos excedidos.

La propuesta queda registrada en Commander como evento; el operador decide y acuerda el cambio.

## Billing

Commander es la fuente de contratos, importes pactados, facturas y líneas. Una factura de grupo
puede tener una línea consolidada o líneas por cabecera.

Esta fase no incluye:

- Stripe u otro proveedor;
- adeudo SEPA;
- conciliación bancaria;
- impuestos automáticos;
- prorrateo;
- cambio de plan o suspensión por un webhook;
- onboarding público autoservicio.

El alta es asistida por el operador. Exponerla al público requerirá registro, verificación,
aceptación contractual, pago, recuperación de fallos y onboarding de marca.

## Criterios de producto

- El éxito de una noticia no debe crear una factura sorpresa.
- Cuenta agrupa compra y factura; cada cabecera conserva control y datos propios.
- Un plan nunca sustituye al código: sin implementación desplegada no hay alta.
- Una personalización no justifica bifurcar Kilkana entero.
- La hemeroteca y el storage importado deben entrar en la elección inicial de plan.
- Extensiones de terceros deben preservar SEO, accesibilidad y rendimiento antes de abrirse como
  capacidad comercial.

El contrato de datos editorial está en [Esquema Kilkana](database/kilkana/README.md).
