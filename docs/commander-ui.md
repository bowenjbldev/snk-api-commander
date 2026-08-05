# Commander UI

## Propósito

`commander/` es la SPA del operador. Controla clientes, productos, instancias, planes,
suscripciones, facturas, cuotas, uso y estado. No genera código ni despliega contenedores: opera
sobre la API real de `src/packages/Commander`.

En producción se sirve en `commander.<BASE_DOMAIN>`. El nginx del compose enruta `^~ /api/` al
Laravel y todo lo demás a esta SPA, así que **comparten origen**: no hay CORS ni proxy de Vite que
mantener.

La SPA anterior (React 19 + Carbon + ECharts + zustand) se conserva en `commander-react/` como
referencia. De ella sobreviven las decisiones de dominio, no el código.

## Stack

- SvelteKit 2 y Svelte 5 (runas), TypeScript y Vite, en modo **SPA pura**
  (`adapter-static` con `fallback: index.html`, `ssr: false`).
- ECharts 6 para las gráficas de tarjeta; SVG propio para las tendencias por fila.
- Public Sans Variable y DM Mono, autoalojadas con `@fontsource`.
- Vitest, ESLint *strict type-checked*, Prettier y `scripts/ds-check.mjs`.

```bash
cd commander
npm run dev        # o docker compose up -d commander-web
npm run check      # svelte-check estricto
npm run lint       # eslint strict type-checked
npm run ds-check   # reglas del design system
npm test           # vitest
npm run build      # → build/
npm run screenshots
```

## Arquitectura del frontend

```text
src/lib/
├── tokens/     variables CSS (único hex del proyecto) + enum CssVar
├── ui/         primitivas sin dominio: no saben qué es una instancia
├── domain/     lo que ningún sistema trae hecho: SupplyLine, Meter, AttentionTray
├── viz/        EChart + puente de tema + options/ + svg/Sparkline
├── api/        client (Bearer, 401) · endpoints · decode · resources
├── model/      types de commander_* · enums · situation · assertNever
├── format/     n · k · eur · pct · rel · cd · stamp · date · mb
├── table/      pager con runas
├── state/      session · data · clock · theme · toasts · storage
├── shell/      raíl, cabecera, pie, paleta ⌘K, PageHeader
├── nav/        routes (el único fichero que navega) + nav
└── uikit/      registro de la guía viva
src/routes/
├── login/            entrada
├── uikit/            guía viva, sin sesión
└── (app)/            armazón + pantallas
```

### Las reglas del design system

Comprobadas por `npm run ds-check`, no recordadas:

1. **Ningún hex fuera de `tokens/`.** En TypeScript, ni siquiera `'var(--ok)'`: se usa `CssVar`.
2. **`style=` en línea solo para geometría calculada.** El resto va en el `<style>` del componente.
3. **Techo de líneas por capa**, midiendo lógica (script + marcado) y total por separado: un SFC
   lleva las dos cosas y un techo plano castigaría el CSS. Hoy: ui 80/160, dominio 130/240,
   rutas 150/260. Están calibrados contra los ficheros reales. Las excepciones van **con nombre y
   motivo** en `TOTAL_EXCEPTIONS`, nunca subiendo el techo de la capa: subirlo para que quepa un
   fichero se lo regala a los otros cuarenta. Hoy hay una, `Button`, y solo en `total`.
4. **`ui/` no importa de `domain/`, `api/`, `nav/` ni `state/`.** La dependencia va en un sentido.
5. **Toda primitiva sale en `/uikit`**, por demo propia o dentro de la de su compuesto. Hoy son 34
   primitivas en 31 demos, descubiertas por glob: crear una la hace aparecer sin tocar ningún
   índice, y borrarla la quita.

### Tipado

`strict` completo más `noUncheckedIndexedAccess` y `exactOptionalPropertyTypes`. Todo dominio
cerrado es un `enum` en `model/enums.ts`, y cinco enums más matan las cadenas que se escapan de los
tipos: `Endpoint`, `Route`, `StorageKey`, `QueryParam` y `CssVar`. Todo `switch` cierra con
`assertNever`.

**El borde valida.** La API no usa `JsonResource`: son modelos Eloquent crudos. `api/decode.ts` es
la única frontera donde existe `string` suelto, y rechaza un valor de enum desconocido con el campo
en el mensaje. También coacciona los agregados numéricos: MySQL devuelve los `SUM` como cadena y
sumarlos sin coaccionar los **concatena**.

### Variante y tono son ejes distintos

Un mando no tiene «variante peligro». La **variante** dice cuánta caja tiene —primaria rellena,
secundaria con borde, acento con el primario en tinta— y el **tono** dice de qué color es: el
preaviso es ámbar, el corte rojo, restablecer verde. Los mismos tres colores que la línea de
suministro de la fila, para que el mando se lea sin abrir nada.

Mezclarlos obligaba a inventar una variante por cada color del prototipo, así que un eje sustituyó
a una variante y la pieza encogió. `tokens/tone.css` deja `--sem`, `--sem-bg` y `--sem-bd` puestos
a partir de `data-tone`; cada componente decide qué hace con ellos —teñir la letra, rellenar la
caja, pintar una barra— y añadir un tono es una entrada, no seis.

**El neutro no declara nada a propósito**: la ausencia de tono no es el mismo gris en una etiqueta
que en un botón.

## Las tres piezas propias

- **Línea de suministro** (`domain/supply.ts`): devuelve forma y tono, no colores. Aquí vive el
  cuarto estado, el que no está en los datos: cuando vence el preaviso el backend trata la
  instancia como cortada aunque la columna siga diciendo `grace`, así que lo deduce el reloj.
- **Contador con umbral** (`domain/meter.ts`): una cuota no es un porcentaje. Relleno neutro por
  debajo del 80 %, ámbar hasta el límite, rojo por encima con el exceso saliendo del carril; la
  marca del umbral siempre visible y la política dentro del instrumento. Límite vacío = ilimitado.
- **Bandeja de atención** (`domain/attention.ts` + `attention-rules.ts`): la cola de trabajo del
  operador, derivada de los datos en cada lectura. Solo se persiste lo descartado.

## Datos y autenticación

Token Sanctum Bearer en `localStorage['commander_token']`; cualquier `401` cierra la sesión. Al
arrancar se valida con `auth/me`.

La carga inicial trae en paralelo clientes, productos, suscripciones, facturas, cuotas, planes y
actividad. **Ninguna lista de la API admite paginación, búsqueda, orden ni filtro por parámetro**:
devuelven la tabla entera, así que filtrar, ordenar y paginar se hace en el navegador.

`session.can()` oculta lo que no procede, pero **no es una frontera de seguridad**: el permiso real
lo comprueba cada controlador.

## Huecos conocidos

- Las situaciones «sin desplegar» y «sin dar de alta» de `backend-catalogo.md` no se pueden deducir:
  no hay `POST /packages` ni columnas `source`/`deployed`. `model/situation.ts` está preparado para
  producirlas el día que existan; hoy la Flota trabaja con cuatro.
- `GET logs` limita a 500 filas, así que no hay virtualización ni hace falta.
- No hay endpoint de KPIs ni de MRR: se derivan en el cliente de suscripciones, productos y facturas.
- La actividad de código depende de `commander:git:sync`; sin repositorio sincronizado la pestaña lo
  dice en vez de fingir una gráfica vacía.
- Pendiente: la ficha de cliente (`/clients/[slug]`), los formularios de alta —cliente, tarifa,
  provisionar instancia— y que los mandos de suministro de la cabecera llamen a la API en vez de
  quedarse en el marcado.

## Build y despliegue

`npm run build` genera `commander/build`. `commander/nginx.conf` aplica fallback SPA a `index.html`
y caché inmutable a `/_app/immutable/`. El Dockerfile hace build con Node y sirve estático con
nginx; el compose de desarrollo monta el código y corre Vite con HMR.

**Al renombrar la carpeta, recrea el contenedor**: un bind mount se ata al inodo, no a la ruta, así
que un `restart` sigue sirviendo el directorio antiguo. Hace falta
`docker compose up -d --force-recreate commander-web`.

**El contenedor tiene sus propios `node_modules` y `.svelte-kit`**, en volúmenes anónimos que tapan
los del host. Sin eso, el `npm install` del contenedor y el del host se pisan las dependencias
nativas opcionales y el host se queda sin poder compilar. Vite escucha en 5173 y nginx proxifica
ahí; el HMR viaja por el 443 que termina TLS (`COMMANDER_HMR_CLIENT_PORT`).

Para producción, `VITE_API_BASE` solo hace falta si el frontend deja de compartir origen con la API.
El backend sigue siendo la autoridad de CORS, auth y permisos.
