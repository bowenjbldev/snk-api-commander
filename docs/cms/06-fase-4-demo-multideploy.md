# Fase 4 — Demo multi-deploy viva

## Contexto (por qué)

Hasta la Fase 3 el CMS ya tenía su propia base de datos, su login de operador, el
cliente HMAC firmado contra `/control` y un SPA cableado a la API real. Pero todo
eso se podía probar, como mucho, contra **un** deploy montado a mano: registrar la
fila del despliegue, copiar el secreto, apuntar el `base_url`… Un proceso manual,
frágil y poco convincente.

El objetivo de esta fase es eliminar ese trabajo manual y demostrar lo único que
de verdad importa: que el panel controla despliegues **reales, aislados y
autónomos** de extremo a extremo. Queremos poder teclear una orden y obtener un
cliente nuevo —contenedor propio, base de datos propia, subdominio propio,
control plane activo— **auto-registrado** en el CMS y listo para que el operador
lo suspenda, lo ponga en gracia o lo reactive desde la interfaz. Y queremos la
prueba en vivo de que, cuando el panel suspende un despliegue, ese despliegue
responde `402 Payment Required` por sí mismo, sin que el CMS tenga que estar
vigilándolo.

Para conseguirlo se extiende el aprovisionador local `scripts/subscription.sh`
(que ya creaba BD + contenedor + vhost por cliente) para que además genere el
secreto de control, lo inyecte en el deploy y lo dé de alta en el CMS mediante dos
comandos Artisan nuevos del paquete `Cms`: `cms:register-deploy` y
`cms:deregister-deploy`. El resultado es una flota multi-deploy viva sobre la que
ejercitar todo el control plane.

## Qué se construyó

Tres piezas que encajan entre sí:

1. **`scripts/subscription.sh` extendido.** El comando `add <cliente> <Package>`
   ahora, además de la BD MariaDB, el contenedor `app-<cliente>` y el vhost de
   nginx, hace cuatro cosas nuevas:
   - genera un `CONTROL_SECRET` por despliegue;
   - lo persiste en el estado del cliente y lo inyecta como
     `CORE_CONTROL_SECRET` en el contenedor (activa `/control` en el deploy);
   - añade un **alias de red** en nginx por cada subdominio de cliente, para que
     el contenedor del CMS pueda resolver `<cliente>.<BASE_DOMAIN>` por la red
     interna de Docker;
   - ejecuta `php artisan cms:register-deploy` en el servicio `app` (el que corre
     `API_PACKAGE=Cms`) para dar de alta el despliegue en el registro del panel.

   El comando `remove <cliente>` hace el camino inverso: llama a
   `cms:deregister-deploy` antes de tirar el contenedor.

2. **`CmsRegisterDeployCommand`** (`cms:register-deploy`). Comando Artisan del
   paquete `Cms` que inserta/actualiza la fila del despliegue (y un cliente
   asociado) en la BD del CMS, guardando el `base_url` del control plane y el
   `control_secret` cifrado. Es idempotente.

3. **`CmsDeregisterDeployCommand`** (`cms:deregister-deploy`). Comando inverso:
   borra la fila del despliegue y sus espejos de cuota/uso del registro del CMS.

### Ficheros y clases exactas

- `scripts/subscription.sh` — funciones `regen_compose`, `write_vhost`, `cmd_add`,
  `cmd_remove`.
- `src/packages/Cms/app/Console/Commands/CmsRegisterDeployCommand.php` — clase
  `Packages\Cms\Console\Commands\CmsRegisterDeployCommand`, firma `cms:register-deploy`.
- `src/packages/Cms/app/Console/Commands/CmsDeregisterDeployCommand.php` — clase
  `Packages\Cms\Console\Commands\CmsDeregisterDeployCommand`, firma `cms:deregister-deploy`.
- `docker-compose.yml` — servicio base `nginx` (se extiende con aliases desde el
  compose generado `docker-compose.clients.yml`).

## Decisiones clave (y por qué)

### Un secreto de control por despliegue, generado en el alta

Cada despliegue necesita su propio `CORE_CONTROL_SECRET`: es la clave HMAC con la
que el CMS firma las llamadas a su `/control` y con la que el middleware del deploy
verifica esas firmas. Generarlo en el alta —y no reutilizar uno global— mantiene
los despliegues realmente aislados: comprometer el secreto de un cliente no
compromete a los demás.

Se genera con un prefijo legible para que sea fácil reconocerlo en logs y dumps:

```bash
control_secret="sk_ctl_live_$(openssl rand -hex 12)"
```

Ese mismo valor se escribe en el estado del cliente (`docker/clients/<cliente>.env`,
clave `CONTROL_SECRET`), se inyecta en el contenedor como `CORE_CONTROL_SECRET` y se
pasa al CMS vía `--secret`. Una sola fuente de verdad, tres consumidores.

### El secreto se persiste en el estado del cliente, no solo en el contenedor

`regen_compose` reconstruye `docker-compose.clients.yml` **completo** a partir de
los ficheros `docker/clients/*.env`. Si el secreto solo viviera como variable de
entorno del contenedor, cualquier `remove`/`add` de otro cliente que dispara un
`regen_compose` lo perdería. Por eso vive en el `.env` de estado del cliente:

```bash
cat > "$CLIENTS_DIR/$c.env" <<EOF
CLIENT=$c
PACKAGE=$pkg
DB_DATABASE=$db
DB_USERNAME=$db
DB_PASSWORD=$db
CONTROL_SECRET=$control_secret
CREATED_AT=$(date -Iseconds)
EOF
```

Como detalle de compatibilidad, en `regen_compose` se hace
`CONTROL_SECRET=""` **antes** de `source "$state"`: los ficheros de cliente
anteriores a la Fase 4 no tienen esa clave, y así su control plane simplemente
queda apagado (`CORE_CONTROL_SECRET` vacío) en vez de romper la regeneración.

### Alias de red en nginx en vez de exponer puertos por cliente

Este es el punto más sutil de la fase y conviene entenderlo bien. El CMS corre en
el contenedor `app` y necesita llamar a `https://<cliente>.<BASE_DOMAIN>/api/v1/control/...`.
Ese subdominio existe en el hosts de Windows (apunta a `127.0.0.1`) para el
navegador, pero **dentro** de la red de Docker `127.0.0.1` es el propio contenedor
`app`, no nginx. Si no hiciéramos nada, el CMS no sabría resolver el subdominio del
cliente.

La solución no es abrir un puerto por cliente ni montar un DNS, sino aprovechar
que todo el tráfico HTTP(S) entra por **un único** nginx que ya enruta por `Host`.
Basta con que el nombre `<cliente>.<BASE_DOMAIN>` resuelva, dentro de la red, al
contenedor `nginx`. Eso se consigue con **aliases de red**: por cada cliente,
`regen_compose` añade su FQDN como alias de red del servicio `nginx`.

`regen_compose` acumula los aliases mientras recorre los clientes:

```bash
# nginx alias so the CMS container resolves <cliente>.<BASE_DOMAIN> to nginx (Host routing).
aliases="${aliases}          - ${CLIENT}.\${BASE_DOMAIN}\n"
```

y al final, si hay al menos un cliente, extiende el servicio `nginx` base con esos
aliases en el compose generado:

```yaml
  nginx:
    networks:
      app-network:
        aliases:
          - demoa.${BASE_DOMAIN}
          - demob.${BASE_DOMAIN}
```

Así, cuando el CMS hace `GET https://demoa.<BASE_DOMAIN>/api/v1/control/state`, el
DNS interno de Docker resuelve `demoa.<BASE_DOMAIN>` → contenedor `nginx`; nginx
recibe la petición, mira la cabecera `Host`, y la enruta por su vhost al
`fastcgi_pass app-demoa:9000` correcto. El CMS no necesita conocer la topología
interna: habla con cada cliente por su subdominio público, exactamente igual que
en producción.

### Idempotencia del registro

`cms:register-deploy` usa `updateOrCreate` tanto para el cliente como para el
despliegue, indexando por `id`. Por eso re-ejecutarlo (por ejemplo, tras recrear
el contenedor) **actualiza** la fila en vez de duplicarla. Esto encaja con la
naturaleza de `subscription.sh`, que puede reaplicarse, y evita estados sucios en
el registro del CMS.

### El secreto se guarda cifrado en el CMS

En `CmsRegisterDeployCommand` el campo `control_secret` del `DeploymentModel`
recibe el secreto en claro, pero el modelo lo cifra en reposo (cast `encrypted`).
El registro guarda además `control_secret_rotated_at` para llevar la trazabilidad
de la rotación (relevante en la Fase 7). El cliente HMAC del CMS lo descifra solo
en el momento de firmar.

### `deregister` borra el deploy pero conserva el cliente

`CmsDeregisterDeployCommand` borra la fila del despliegue y sus espejos
(`QuotaModel`, `UsageSnapshotModel` por `deployment_id`), pero **deja la fila del
cliente** en su sitio. Un cliente puede sobrevivir a un despliegue concreto
(historial de facturación, reaprovisionamiento), así que tirarlo en una baja de
deploy sería destruir información de negocio.

## Cómo funciona

### Alta: `subscription.sh add <cliente> <Package>`

El flujo completo de `cmd_add`, en orden:

1. Valida el nombre del cliente y que el paquete exista en `src/packages/`.
2. Comprueba que el paquete no tenga ya otra suscripción (BD = snake_case del
   paquete ⇒ una suscripción por paquete).
3. Genera `control_secret = "sk_ctl_live_$(openssl rand -hex 12)"`.
4. Escribe `docker/clients/<cliente>.env` con `CONTROL_SECRET` incluido.
5. `regen_compose` reconstruye `docker-compose.clients.yml`: define el servicio
   `app-<cliente>` con `CORE_CONTROL_SECRET: "${CONTROL_SECRET}"` y añade el alias
   de red del subdominio a `nginx`.
6. Crea la BD/usuario MariaDB.
7. `write_vhost` genera el vhost `docker/nginx/conf.d/client-<cliente>.conf.template`.
8. Levanta `app-<cliente>`, recarga `nginx` (re-render de vhosts + aliases) y
   espera a que la app responda `migrate:status`.
9. Ejecuta `core:seed --force`.
10. **Registra el deploy en el CMS** llamando, dentro del servicio `app`:

```bash
docker compose exec -T app php artisan cms:register-deploy "$c" \
    --package="$pkg" \
    --base-url="https://$c.$BASE_DOMAIN/api/v1" \
    --secret="$control_secret" \
    --client="$c"
```

Si esa llamada falla (típicamente porque el servicio `app` no corre con
`API_PACKAGE=Cms` y por tanto no conoce el comando), el script emite un AVISO pero
**no aborta** el alta: el despliegue sigue siendo funcional y autónomo; solo no
queda controlado por el panel.

11. Añade la entrada al hosts de Windows e imprime el resumen (URL, docs, login
    seed, BD, "registrado en el CMS").

Nótese que el `base_url` que se pasa al CMS **ya incluye `/api/v1`**
(`https://<cliente>.<BASE_DOMAIN>/api/v1`). El cliente HMAC del CMS añade luego
`/control/...` y firma usando el path del URL completo, de modo que cuadra con el
`$request->path()` que ve el middleware del deploy.

### Qué escribe `cms:register-deploy` en el registro

`CmsRegisterDeployCommand::handle()` resuelve los valores por defecto y persiste
dos filas. El `host` para el campo `subdomain` se deriva con `parse_url($baseUrl,
PHP_URL_HOST)`:

```php
$baseUrl  = $this->option('base-url') ?: "https://{$deployment}.snake.test/api/v1";
$clientId = $this->option('client')  ?: $deployment;
$host     = parse_url($baseUrl, PHP_URL_HOST) ?: "{$deployment}.snake.test";

ClientModel::updateOrCreate(['id' => $clientId], [
    'name' => $clientName, 'company' => $clientName,
    'email' => "ops@{$clientId}.local", 'status' => 'active', ...
]);

DeploymentModel::updateOrCreate(['id' => $deployment], [
    'subdomain' => $host,
    'client_id' => $clientId,
    'package' => $package,
    'base_url' => $baseUrl,
    'control_secret' => $secret,            // cifrado en reposo
    'control_secret_rotated_at' => now(),
    'status' => 'active',
    'changed_by' => 'cms',
    ...
]);
```

El despliegue queda con `status = active`, `last_seen = now()` y los contadores de
uso (`requests_24h`, `quota_health`) a cero, a la espera del primer `sync`.

### Baja: `subscription.sh remove <cliente>`

`cmd_remove` hace el camino inverso, y lo primero que hace es desregistrar del CMS
(con tolerancia a fallo) antes de tocar la infraestructura:

```bash
docker compose exec -T app php artisan cms:deregister-deploy "$c" >/dev/null 2>&1 || true
docker compose rm -sf "app-$c"
rm -f "$NGINX_CONF_DIR/client-$c.conf.template"
rm -f "$CLIENTS_DIR/$c.env"
regen_compose            # quita el alias de nginx del cliente
docker compose up -d nginx
```

`cms:deregister-deploy` borra la fila del despliegue y sus espejos de cuota/uso;
si el deploy no estaba registrado, avisa y devuelve éxito (no es un error tirar de
una baja algo que ya no existe). La BD MariaDB se conserva salvo `--drop-db`.

## Verificación

La prueba decisiva de esta fase es **viva**: provisionar un despliegue real,
ejercer el ciclo de control desde el panel/API del CMS, ver al deploy responder
`402` por sí solo, y desmontar.

### 1. Provisionar un despliegue real

```bash
bash scripts/subscription.sh init          # una vez: prepara compose + COMPOSE_FILE
bash scripts/subscription.sh add demoa Cms # alta del despliegue de demo
```

Al terminar, `demoa` está vivo en `https://demoa.<BASE_DOMAIN>/api/v1`, sirve su
seed, y aparece en el registro del CMS como `active`. Comprobación del estado
propio del deploy (firmado por el CMS, no a mano):

```bash
# El deploy se sirve solo: health exento del kill-switch
curl -k https://demoa.<BASE_DOMAIN>:<HTTPS_PORT>/api/v1
```

### 2. Suspender desde el CMS → el deploy devuelve 402

Desde el panel (o vía la API de operador del CMS) se suspende `demoa`. El CMS firma
un `POST /control/state {status:"suspended", reason, message}` contra el deploy.
A partir de ahí, cualquier ruta **no exenta** del deploy responde
`402 Payment Required`:

```bash
# Ruta de negocio: ahora 402 (el deploy se apaga a sí mismo)
curl -k -o /dev/null -w '%{http_code}\n' \
    https://demoa.<BASE_DOMAIN>:<HTTPS_PORT>/api/v1/<ruta-de-negocio>
# -> 402

# EXENTAS del kill-switch (siguen 200): health y el propio control plane
curl -k -o /dev/null -w '%{http_code}\n' \
    https://demoa.<BASE_DOMAIN>:<HTTPS_PORT>/api/v1
# -> 200
```

Esto demuestra la autonomía: el `402` lo produce el middleware del **deploy**
leyendo su propio `core_package_state`, no el CMS interceptando tráfico.

### 3. Gracia y reactivación

- **Grace:** el CMS firma `POST /control/state {status:"grace", grace_until}`. El
  deploy sigue sirviendo hasta `grace_until`; una vez vencido, se comporta como
  suspendido (se resuelve en lectura).
- **Resume:** el CMS firma `POST /control/state {status:"active"}`. Las rutas de
  negocio vuelven a responder `200`.

Cada transición se ve reflejada tanto en el deploy (`GET /control/state`) como en
la fila del despliegue en el CMS tras un `sync`.

### 4. Desmontar

```bash
bash scripts/subscription.sh remove demoa            # conserva la BD
bash scripts/subscription.sh remove demoa --drop-db  # además borra la BD (pide confirmación)
```

Tras el `remove`, `cms:deregister-deploy demoa` ha quitado la fila del registro y
el alias de nginx ha desaparecido del compose: el CMS ya no lista ni intenta
controlar el despliegue.

## Gotchas

- **`127.0.0.1` significa cosas distintas según el contexto.** En el hosts de
  Windows el subdominio apunta a `127.0.0.1` (para el navegador); dentro de la red
  de Docker, `127.0.0.1` es el propio contenedor que llama. Sin el alias de red de
  nginx, el CMS **no** podría resolver el subdominio del cliente. El alias es lo
  que hace que `<cliente>.<BASE_DOMAIN>` resuelva a `nginx` por dentro y el
  enrutado por `Host` haga el resto.

- **El `base_url` ya incluye `/api/v1`.** No lo dupliques al construir el registro.
  El cliente HMAC añade `/control/...` y firma el path del URL completo
  (`parse_url`) para que cuadre con el `$request->path()` del receptor.

- **`regen_compose` reescribe el compose de clientes entero.** No edites
  `docker-compose.clients.yml` a mano (lleva la cabecera "NO editar a mano"):
  cualquier `add`/`remove` lo regenera desde `docker/clients/*.env`. Todo lo que
  deba sobrevivir —el secreto incluido— tiene que estar en esos `.env` de estado.

- **Compatibilidad con clientes pre-Fase-4.** `regen_compose` fuerza
  `CONTROL_SECRET=""` antes de cargar cada `.env`. Un cliente antiguo sin esa clave
  arranca con el control plane apagado, en vez de romper la regeneración del
  compose.

- **El registro en el CMS no es un punto de fallo del alta.** Si `app` no corre
  con `API_PACKAGE=Cms`, `cms:register-deploy` no existe y el alta imprime un AVISO
  pero **continúa**. El despliegue queda funcional y autónomo; solo no controlado
  por el panel hasta que se registre.

- **Los vhosts son plantillas `*.conf.template`.** Se montan en
  `/etc/nginx/templates/` y el entrypoint de `nginx:alpine` corre `envsubst` al
  arrancar. Por eso en `write_vhost` se escapan `\$host`, `\$upstream`, etc. (deben
  llegar literales a la config final) mientras que `$BASE_DOMAIN` sí se interpola
  en el script. Tras un `add`/`remove` hay que recargar nginx
  (`docker compose up -d nginx`) para re-renderizar.

- **El secreto viaja en claro por el `exec`, cifrado en reposo.** El
  `--secret=<...>` se pasa como argumento al `php artisan` y el `DeploymentModel`
  lo cifra al guardarlo. En local es aceptable; en producción el secreto de cada
  deploy se define en su propio `.env`.

## Ficheros tocados

- `scripts/subscription.sh` — generación de `CONTROL_SECRET`, inyección de
  `CORE_CONTROL_SECRET`, aliases de red en `nginx` (`regen_compose`), y llamadas a
  `cms:register-deploy` (`cmd_add`) / `cms:deregister-deploy` (`cmd_remove`).
- `src/packages/Cms/app/Console/Commands/CmsRegisterDeployCommand.php` — comando
  `cms:register-deploy` (alta idempotente del despliegue + cliente en el registro).
- `src/packages/Cms/app/Console/Commands/CmsDeregisterDeployCommand.php` — comando
  `cms:deregister-deploy` (baja del despliegue + espejos de cuota/uso).
- `docker-compose.yml` — servicio base `nginx` que el compose generado
  `docker-compose.clients.yml` extiende con los aliases por cliente.

Ver `CONTROL-PLANE.md` para el modelo de datos del control plane en el deploy y el
contrato exacto de `/control`.
