# La demo: El Diario, de Alzira

> Estado: implementado. Dataset local reproducible de Kilkana.

`demo.kilkana.test` es el periódico con el que se enseña el producto, y por eso el seeder no
produce «datos de prueba»: produce un diario comarcal que tiene que aguantar la mirada de quien va
a comprarlo. Lo siembra `SnkKilkanaDatabaseSeeder` sobre el perfil `data/profiles/eldiario.json`
(cliente `demo`, `demo: true`); `diario-norte` es el segundo inquilino y va vacío a propósito,
con `accounts: true`: sin archivo y sin abrir, pero con el administrador local y el lector de demo
para entrar a recorrer su puesta en marcha.

Ese host es el frontend del producto. La API de la misma instancia usa
`https://demo.commander.test/api/v1/kilkana`: cliente `demo`, dominio de plataforma
`commander.test` y package `kilkana` en la ruta.

## Qué simula cada bloque

| Bloque | Fuente | Lo que tiene que parecer |
|---|---|---|
| Secciones | `CATEGORIES` en el seeder | Diecisiete secciones con estados reales: una oculta del menú, una en preparación sin piezas. |
| Redacción y firmas | `STAFF`, `EXTERNAL_BYLINES` | Seis de plantilla, una agencia, la firma colectiva, un columnista y una colaboradora, con bio, cargo, redes y retrato. |
| Piezas | `data/posts.json`, 532 | Titulares y entradillas escritos a mano; el cuerpo lo compone `SnkKilkanaDemoBodyFactory`. |
| Fotos | `data/media/`, 60 JPEG de 1920 × 1080 con licencia Unsplash; autor y página en `sources.json` | La mediateca con crédito, alt, pie y estante por mesa, y derivadas de 160 a 1920. |
| Lecturas | `seedViews()` | Una curva por edad de la pieza sobre `reads` del snapshot. |
| Lectores y comentarios | `seedReaders()`, `seedComments()` | Gente de la Ribera comentando lo que acaba de salir. |
| Suscriptores | `seedSubscribers()` | La lista del boletín con sus tres estados y dos orígenes. |

## El reloj: la demo no envejece

El snapshot está escrito en fechas fijas y su pieza más reciente es del 28 de junio de 2026. Sin
más, una demo abierta en septiembre parece un periódico cerrado: la calle abre con dos meses de
silencio y la audiencia de «hoy» marca cero. Por eso **el archivo entero se desplaza el mismo
número de días para que esa pieza sea de hoy**, conservando el ritmo real —noventa piezas al mes,
los huecos— y arrastrando con él lecturas, comentarios y URLs.

**El desplazamiento se ancla a la base, no al reloj.** La primera siembra lo fija; las siguientes
lo leen de la pieza publicada más antigua del snapshot que siga en la base, comparada con su
propia fecha en el snapshot: la misma pieza en los dos lados, se borre lo que se borre. Si cada siembra desplazara desde hoy,
las claves `(url_date, slug)` cambiarían y resembrar duplicaría el archivo. Para poner la demo al
día otra vez hay que **refrescar la base**, no resembrar encima. Lo fija `KilkanaDemoClockTest`.

## El cuerpo de las piezas

El snapshot trae titular y entradilla escritos a mano y no trae cuerpo; lo compone
`SnkKilkanaDemoBodyFactory` con la prosa de `SnkKilkanaDemoProse`, por familia de sección: ocho
familias, cuarenta y ocho párrafos en las cuatro mesas grandes —sesenta y cuatro en local— y
veinticuatro en las pequeñas, con ocho intertítulos, ocho citas con quien las dice y ocho cierres
por familia. Cada pieza lleva siete párrafos distintos, dos intertítulos, una cita y un cierre,
elegidos por el hash del slug: no se repite ninguno dentro de la pieza y dos piezas seguidas de la
misma sección comparten como mucho tres. Con el archivo entero, cada párrafo de una mesa grande
aparece unas doce veces y unas nueve en las pequeñas. Salen unas trescientas palabras, que es un
artículo corto de comarcal. Lo fija `SnkKilkanaDemoBodyFactoryTest`.

## Los comentarios

Las voces viven en `SnkKilkanaDemoVoices`: ocho frases por familia de sección —la gente comenta
lo que ha leído: el partido, los precios, la obra de su calle—, dieciséis generales, quince
respuestas y seis de spam. Dos de cada tres comentarios son de la familia de la pieza.


Un comentario llega en los cuatro días que siguen a la pieza, nunca antes de que exista ni después
de hoy. Lector, frase y hora salen de hashes distintos, para que el mismo lector no diga siempre
la misma frase. La cola de moderación son los de las últimas tres semanas; lo de hace meses ya
está decidido, y entre lo decidido hay rechazados, que son el historial de la cuenta.

**El spam lo firman cuatro cuentas de usar y tirar** —correo de proveedor gratuito, sin nombre,
de hace días—, no los vecinos que llevan años comentando: es lo que se ve en cualquier cola real,
y lo que hace que bloquear a su autor sea la respuesta y no un castigo a alguien de casa. Tres de
las cuatro ya están bloqueadas; la cuarta sigue abierta para que la cola tenga a quién bloquear.
Los ocho lectores con nombre tienen altas repartidas en dos años y medio.

## Lo que rodea a las piezas

- **Historial.** La mitad de las doscientas piezas más recientes lleva una edición real hecha
  con el servicio: la primera versión no tenía el antetítulo, o se quedaba en la primera frase de
  la entradilla. La revisión se fecha entre una y seis horas después de publicar.
- **SEO y destacadas.** Una de cada tres piezas lleva descripción SEO —la entradilla recortada a
  158 caracteres—, como una redacción que lo rellena a ratos; las seis más leídas del último mes
  van marcadas como destacadas.
- **Lecturas medidas.** Entre cero y cuatro por lector con nombre, sobre piezas con muro, desde el
  día uno del mes y después de que la pieza exista: alguien que no ha gastado ninguna, alguien a
  mitad y alguien que ya se ha topado con el muro. Es lo que enseña el medidor de la ficha de un
  lector en el panel.
- **El boletín con historia.** Cuatro números enviados en los últimos noventa días y un borrador
  abierto, con lo que la lista hizo con cada uno repartido por suscriptor y sin azar: una de cada
  dos aperturas, uno de cada ocho clics, y unas pocas bajas que coinciden con quienes de verdad
  están dados de baja. La apertura media de la pestaña de suscriptores sale de ahí.
- **La papelera con algo dentro.** Dos piezas y dos fotos sin uso tiradas por dirección: una de
  cada con prisa, a la semana de purgarse, y otra recién caída. Piezas de hace entre diez y sesenta
  días, para no tocar ni la portada ni el ancla del reloj; resembrar no las saca de la papelera.
- **El registro de actividad.** Deducido de lo que la base ya cuenta: cada pieza publicada en los
  últimos noventa días es un «published» firmado por su firma —o por el programador, las de
  madrugada—, cada revisión un «edited», cada día con comentarios aprobados un lote de la
  moderadora, y encima lo que una redacción hace de vez en cuando: un tema vestido, una sección
  renombrada, dos etiquetas fundidas, un asiento invitado, un lector bloqueado. Todo lo demás que
  siembra el seeder se siembra con el registro callado.
- **Suscriptores.** Unos cuatrocientos, con nombres de la Ribera, altas repartidas en año y medio,
  tres de cada cuatro confirmadas por el formulario y un poso importado de la lista en papel.
- **Fotos.** Las ciento setenta y seis direcciones del snapshot se reparten entre los sesenta
  ficheros por carga, de la más compartida a la menos y siempre al fichero que menos piezas lleva:
  ninguno se queda sin estrenar y el más cargado queda cerca de la media.
- **La portada no se siembra.** `kilkana_layouts` arranca vacía a propósito: la calle compone con
  la plantilla del tema hasta que la redacción publica desde el panel, y una demo que enseña ese
  gesto necesita empezar sin nada publicado.
- **Medio ambiente es la sección más joven.** Diez piezas del snapshot —el embalse, el humedal, los
  azudes, el parque solar— para que un comarcal de la Ribera hable del río, y su propia familia de
  prosa y de voces.

## Lo que no se hace

- **Cifras del panel dentro del artículo.** El recuadro de claves lleva las etiquetas, no las
  lecturas: ningún periódico imprime sus analíticas.
- **Datos de otra geografía.** Lectores, organizaciones, teléfonos y suscriptores son de Alzira y
  la Ribera; el otro perfil tiene los suyos.
- **Correos que puedan existir.** Todo va en dominios `.example`.

## Resembrar

```bash
docker compose exec -T -e KILKANA_SEED_DEMO=1 provisioner php artisan core:seed --client=demo --package=Kilkana --force
```

`KILKANA_SEED_DEMO=1` es la puerta del archivo y va en el comando: sin ella el seeder siembra la
identidad y los permisos, dice «demo: KILKANA_SEED_DEMO no está puesta» y no toca ni una pieza.

Resembrar repone lo que trae el snapshot y no quita lo editado encima. Para volver a poner el
reloj a hoy, refrescar la base primero ([Operación](../../operations.md)).
