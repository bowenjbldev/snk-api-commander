# Dominios propios de instancias

> Estado: diseño no implementado.

## Estado de esta decisión

Este documento define el diseño futuro para servir una instancia desde su subdominio de
plataforma y desde uno o varios dominios propios. **No está implementado**: no existen todavía la
tabla, el resolutor multi-host ni la emisión automática de certificados.

El caso de referencia es una cabecera Kilkana que empieza en
`diario-norte.commander.example` y después adopta `diarionorte.com`, manteniendo la misma instancia,
base, usuarios, plan, cuotas y contenido.

## Decisiones cerradas

```text
account Grupo Prensa
└── client diario-norte
    └── instance diario-norte + Kilkana
        ├── plataforma  diario-norte.commander.example   permanente y derivado
        ├── principal diarionorte.com             dominio propio
        └── alias     www.diarionorte.com         redirige al principal
```

- El dominio propio pertenece a `commander_instances`, no a la cuenta ni al cliente. Una cuenta
  de grupo tiene una instancia por cabecera y cada una administra sus hostnames.
- El subdominio de plataforma es la identidad técnica y el fallback permanente. No cambia la
  frontera tenant ni necesita copiarse a una tabla. Identifica al cliente; el producto se
  selecciona con `/api/v1/{aplicación}`. En local es `{cliente}.commander.test` para todos
  los packages, incluido Kilkana. `commander.example` es solo un ejemplo de despliegue futuro.
- Una instancia puede tener varios dominios propios, pero solo un origen público principal.
- Un hostname solo puede pertenecer a una instancia en todo Commander.
- El dominio no modifica provisioning, plan, facturación, cuotas, usuarios ni base de datos.
- La administración inicial es asistida por el operador de Commander. No se sincronizan usuarios
  Kilkana ni se abre autoservicio desde el tenant.
- Portada, `/admin` y `/api/v1/{aplicación}` deben compartir origen. Así el cliente web no necesita
  una lista CORS dinámica por dominio.
- Un dominio propio identifica una sola instancia y, por tanto, una sola aplicación. Servir varios
  productos bajo el mismo vanity domain queda fuera del alcance.

## Situación actual y bloqueos

El routing vigente identifica al cliente exclusivamente desde
`{cliente}.<TENANT_BASE_DOMAIN>`:

- [`CorePackageSelectorSupport::subdomainFromHost`](../../src/packages/Core/app/Support/CorePackageSelectorSupport.php)
  devuelve `null` para cualquier dominio ajeno a la base configurada;
- [`CoreRouteServiceProvider`](../../src/packages/Core/app/Providers/CoreRouteServiceProvider.php)
  registra las rutas compartidas bajo `Route::domain('{client}.'.$tenantBase)`;
- [`CoreResolveInstanceFromHostMiddleware`](../../src/packages/Core/app/Http/Middleware/CoreResolveInstanceFromHostMiddleware.php)
  calcula también el namespace del overlay desde ese subdominio;
- [Nginx](../../docker/nginx/conf.d/app.conf.template) solo presenta el certificado wildcard de los
  dominios de plataforma;
- los enlaces de newsletter se firman contra el host recibido en
  [`SnkKilkanaPublicService`](../../src/packages/Kilkana/app/Services/SnkKilkanaPublicService.php);
- `kilkana_settings.tenant_url` se usa como URL de retorno y podría discrepar de Commander.

Aunque el vhost por defecto haga llegar un `Host` desconocido a Laravel, el handshake TLS ocurre
antes de la petición: el wildcard de plataforma no puede autenticar `diarionorte.com`.

Kilkana expone hoy solo API. El dominio completo no puede considerarse operativo hasta que el
futuro cliente público sirva `/` y `/admin` para hostnames arbitrarios.

## Modelo futuro mínimo

La única tabla nueva prevista es `commander_instance_domains`:

| Campo | Responsabilidad |
|---|---|
| `id` | identidad estable del hostname |
| `instance_id` | instancia y base a las que resuelve |
| `hostname` | FQDN normalizado, único globalmente |
| `is_primary` | origen canónico entre los dominios propios activos |
| `verification_status` | `pending`, `verified` o `failed` |
| `tls_status` | `pending`, `active` o `failed` |
| `verified_at` | prueba de control completada |
| `last_checked_at` | última comprobación DNS/TLS |
| `disabled_at` | baja reversible sin perder historia |
| `last_error` | diagnóstico operativo visible |
| timestamps | auditoría básica |

El token de verificación y un identificador externo solo se añadirán cuando se elija el
proveedor de entrada. Commander no debe guardar certificados ni claves privadas.

Un dominio está listo cuando cumple simultáneamente:

```text
verification_status = verified
tls_status          = active
disabled_at         = null
DNS                 = destino de entrada de la plataforma
health check        = instancia esperada
```

La separación entre propiedad y certificado es deliberada: validar una no demuestra que la otra
esté lista. Los proveedores de custom hostnames también publican ambos estados por separado.

### Normalización y frontera de confianza

El alta acepta un hostname, no una URL:

- sin esquema, puerto, path, query, fragmento ni wildcard;
- minúsculas y sin punto final;
- longitud y labels DNS válidos;
- nunca una IP, localhost ni un dominio reservado de plataforma;
- búsqueda posterior por igualdad exacta e índice único;
- IDN convertido de forma consistente a ASCII/Punycode o, en la primera versión, rechazado de
  manera explícita. Nunca se mezcla Unicode sin normalizar con el valor de routing.

No se construyen consultas, rutas, certificados ni URLs desde un `Host` sin validar.

## Resolución de una petición

```text
Host recibido
├── coincide con *.<TENANT_BASE_DOMAIN>
│   └── client.slug desde el label + application.slug desde /api/v1
└── no coincide
    └── lookup exacto de dominio listo → instance directa
```

Tras resolver por dominio propio se debe:

1. comprobar que la instancia está `ready`, su servicio/cliente/aplicación están activos y el
   código sigue disponible;
2. rechazar si el slug de aplicación de la ruta no coincide con `instance.application.slug`;
3. obtener el cliente del modelo resuelto, no del hostname, para aplicar el overlay correcto;
4. ejecutar exactamente la conmutación y restauración actuales de conexión, config y contexto;
5. mantener `instance_id` como identidad de cuotas, estado, logs y rate limits.

No se crea un segundo middleware que duplique la conmutación tenant: el resolutor actual debe
concentrar ambos caminos.

## Alta asistida

1. El operador registra el hostname como pendiente en Commander.
2. Commander o el proveedor entrega una prueba TXT/HTTP de propiedad.
3. El cliente completa la verificación sin mover todavía el tráfico cuando se necesite una
   migración sin corte.
4. El cliente apunta DNS a un destino estable de la plataforma.
5. La entrada obtiene y despliega el certificado.
6. Commander espera verificación, TLS, DNS y un health check que devuelva la instancia esperada.
7. Una transacción promociona el dominio a principal y degrada el principal anterior a alias.
8. Se actualizan URLs públicas y se inicia la migración SEO.

Si falla cualquier paso, el dominio técnico sigue atendiendo y permite reintentar. Un hostname
pendiente o fallido nunca enruta contenido tenant.

### DNS del cliente

- Un subdominio como `www.diarionorte.com` normalmente puede usar CNAME al destino de plataforma.
- El apex `diarionorte.com` requiere A/AAAA, ALIAS/ANAME o un proveedor con CNAME flattening.
- Se deben diagnosticar CAA, propagación, DNSSEC y conflictos con otro CDN antes de culpar al
  certificado.
- Cambiar la web no exige tocar MX. Las instrucciones deben preservar correo, SPF, DKIM y DMARC.
- Si se migra un sitio existente, se reduce el TTL con antelación y se prevalidan propiedad y TLS
  cuando el proveedor lo permita.

## Entrada HTTPS

La infraestructura debe elegir **un** mecanismo; no se construirá una abstracción multiproveedor
antes de necesitarla.

### Alternativa autoalojada

Caddy delante del Nginx actual puede emitir TLS bajo demanda. En producción debe usar un endpoint
`ask` que autorice solo hostnames verificados mediante una consulta indexada a Commander. El
almacén ACME tiene que vivir en volumen persistente y las pruebas usan el entorno staging de la
autoridad certificadora.

### Alternativa administrada

Un servicio de custom hostnames puede encargarse de validación, certificados, renovación, edge y
migración sin corte. La decisión depende del coste real, soporte de apex, volumen de dominios y
necesidad de WAF/CDN.

No se ampliará Nginx con plantillas por cliente ni se escribirá un gestor ACME en Laravel. La
elección de entrada debe quedar fijada antes de diseñar los campos dependientes del proveedor.

## Origen canónico y SEO

### Antes del dominio propio

- Un entorno de preview corto usa `noindex`.
- Un periódico que publica de verdad en `diario-norte.commander.example` puede indexarse; un subdominio
  no es por sí mismo una penalización de crawling o ranking.
- Kilkana debe poder crear una propiedad URL-prefix de Search Console por cabecera. No se comparte
  con el cliente la propiedad de dominio completa de `commander.example`, porque incluiría todas las
  cabeceras.

### Al activar el dominio propio

Solo el principal sirve las páginas públicas como origen canónico. Los alias hacen una redirección
permanente en un salto, conservando path y query. La migración incluye:

- mapa uno a uno entre URLs antiguas y nuevas;
- `301` o `308` de cada GET/HEAD público al destino final;
- `rel=canonical` autorreferente en el dominio nuevo;
- enlaces internos, sitemap normal, sitemap de noticias y RSS con el principal;
- Open Graph, JSON-LD `NewsArticle`, imágenes y datos de organización con URLs nuevas;
- verificación de ambas propiedades, Change of Address y sitemap nuevo en Search Console;
- seguimiento de crawl errors, indexación, tráfico y logs de ambos hosts;
- redirects durante al menos un año y, mientras Kilkana controle el subdominio, preferiblemente
  de forma indefinida.

No se redirigen indiscriminadamente POST, PUT, PATCH ni DELETE. La canonicalización SEO pertenece
a las rutas públicas idempotentes; la API puede aceptar aliases activos sin cambiar la identidad
tenant.

`kilkana_posts.canonical_url` sigue representando la fuente externa original de un artículo. No
se reutiliza para el canonical del sitio.

### Renderizado del futuro cliente

El panel administrativo puede ser una SPA. Portada, secciones, firmas y artículos deben entregar
contenido y metadatos desde el primer HTML mediante SSR, renderizado estático o hidratación. No se
creará una segunda versión solo para bots.

## URLs firmadas, autenticación y correo

Los enlaces de confirmación y baja actuales usan firma absoluta: el host forma parte de la firma.
Redirigirlos al dominio principal antes de validarlos los rompe.

La primera versión debe generar estas acciones sobre el subdominio técnico permanente, validar
allí y redirigir al dominio principal solo después de ejecutar la acción. Esas rutas quedan
exentas del redirect canónico temprano.

Una firma relativa solo sería segura si incluye una identidad de instancia inmutable y el
servidor comprueba que coincide con la instancia activa. Firmar solo el path y un ID de suscriptor
permitiría reutilizar una firma contra el mismo ID de otra base tenant.

El login actual entrega tokens Bearer almacenados en la base tenant. El cambio de origen no mueve
el almacenamiento del navegador: al estrenar dominio, el administrador inicia sesión de nuevo.
No se construirá un puente de sesión entre dominios. Si en el futuro se usan cookies, serán
host-only; nunca se compartirá una cookie `.commander.example` entre clientes.

Enviar newsletter desde `newsletter@diarionorte.com` es otra capacidad. Requiere verificación de
dominio de correo, SPF, DKIM y DMARC y no se deduce del dominio web. El lanzamiento puede conservar
un remitente Kilkana con nombre y `Reply-To` del periódico.

## Seguridad y aislamiento

- Solo el control demostrado del hostname autoriza routing y emisión de TLS.
- La entrada deniega un SNI/Host desconocido; nunca solicita certificados para cualquier petición.
- Si existe CDN, el origen no queda abierto a Internet sin restricción y Laravel solo confía en
  cabeceras forwarded del proxy conocido.
- La clave de caché incluye siempre el hostname. Compartir caché solo por path filtraría contenido
  entre cabeceras.
- `HSTS includeSubDomains` y preload no se activan para un dominio de cliente sin confirmar que
  Kilkana controla todos sus subdominios.
- Al quitar un dominio se desactiva primero el mapping, se detiene su renovación y se conserva el
  fallback. Una futura reasignación exige verificación nueva.
- La deriva DNS y la expiración o transferencia del dominio requieren revisiones periódicas.

## Observabilidad y operación

Commander debe mostrar por hostname:

- propietario/instancia, principal o alias;
- estado de propiedad, DNS, TLS y health check;
- último chequeo, próxima renovación si el proveedor la expone y último error;
- acciones idempotentes de verificar, reintentar, promover y desactivar.

Los logs conservan `instance_id` y añaden el host solicitado o `domain_id`. Se alertará por
renovación fallida, certificado próximo a caducar y DNS que deja de apuntar a Kilkana. Los retries
de ACME respetan backoff y límites; no se reintenta en bucle por cada request.

## Baja y rollback

1. Si el principal falla, se promociona el subdominio de plataforma sin tocar la instancia.
2. Los GET/HEAD públicos dejan de canonicalizar hacia el dominio roto.
3. Al terminar el servicio se desactiva el mapping antes del deprovisioning.
4. Un dominio que ya no apunta a Kilkana no se redirige ni se reutiliza implícitamente.
5. La fila y los eventos se conservan para explicar quién lo activó y cuándo.

## Pruebas de aceptación futuras

- El subdominio de plataforma sigue resolviendo la misma instancia y base.
- Un dominio verificado y con TLS resuelve exactamente esa instancia.
- Un hostname pendiente, desactivado o desconocido no obtiene contenido ni certificado.
- Un dominio propio de una instancia Kilkana no puede acceder a otra aplicación cambiando
  el segmento `/api/v1`. El host de plataforma sí admite los demás productos del cliente.
- El overlay se elige por `instance.client.slug`, también bajo dominio propio.
- Tres cabeceras de un grupo conservan dominios, bases, tokens y logs independientes.
- Apex y `www` sirven el mismo contenido, pero solo el principal responde `200` público.
- El redirect conserva ruta y query, no crea cadenas y no redirige escrituras API a ciegas.
- Un cambio de principal actualiza canonical, sitemap, RSS, Open Graph y `NewsArticle`.
- Un enlace de newsletter anterior al cambio sigue siendo válido y no se puede reutilizar contra
  otra instancia.
- Una respuesta cacheada para una cabecera nunca se entrega desde el host de otra.
- Fallar DNS o TLS deja operativo el subdominio de plataforma y produce un diagnóstico accionable.

## Fuera de alcance hasta que exista necesidad real

- dominios wildcard del cliente;
- un dominio para varias aplicaciones;
- autoservicio y delegación desde usuarios tenant;
- SSO o transferencia de sesión entre hosts;
- facturación o feature gating de dominios antes de conocer el coste del proveedor;
- varios proveedores de entrada intercambiables;
- remitentes de correo personalizados;

Todos los productos comparten el dominio de plataforma; añadir un segundo SaaS no requiere
un mapa aplicación→dominio. Este diseño de dominios propios es una capacidad adicional por instancia.

## Secuencia de implementación futura

1. Elegir entrada HTTPS y cerrar el contrato DNS que recibirá el cliente.
2. Crear la tabla e invariantes de dominio y sus endpoints privados de operador.
3. Extender el resolutor actual y sus tests sin duplicar la conmutación tenant.
4. Automatizar verificación, TLS, health check, retry y desactivación.
5. Hacer que el futuro frontend derive todas las URLs públicas del dominio principal.
6. Implementar canonicalización, migración SEO, observabilidad y runbook de rollback.
7. Probar un dominio sin tráfico y después una migración real antes de ofrecerlo como capacidad
   general.

## Referencias oficiales

- [Google: cambio de dominio con URLs nuevas](https://developers.google.com/search/docs/crawling-indexing/site-move-with-url-changes)
- [Google: URLs canónicas](https://developers.google.com/search/docs/crawling-indexing/consolidate-duplicate-urls)
- [Google: subdominios y crawling](https://developers.google.com/search/help/crawling-index-faq)
- [Google: datos estructurados Article/NewsArticle](https://developers.google.com/search/docs/appearance/structured-data/article)
- [Google: sitemap de noticias](https://developers.google.com/search/docs/crawling-indexing/sitemaps/news-sitemap)
- [Google: SSR, renderizado estático o hidratación](https://developers.google.com/search/docs/crawling-indexing/javascript/dynamic-rendering)
- [Caddy: On-Demand TLS y endpoint `ask`](https://caddyserver.com/docs/caddyfile/options#on-demand-tls)
- [Let's Encrypt: límites y staging](https://letsencrypt.org/docs/rate-limits/)
- [Let's Encrypt: tipos de challenge ACME](https://letsencrypt.org/docs/challenge-types/)
- [Let's Encrypt: CAA](https://letsencrypt.org/docs/caa/)
- [Cloudflare for SaaS: validación de hostnames](https://developers.cloudflare.com/cloudflare-for-platforms/cloudflare-for-saas/domain-support/hostname-validation/)
- [Cloudflare DNS: CNAME flattening en el apex](https://developers.cloudflare.com/dns/cname-flattening/set-up-cname-flattening/)
