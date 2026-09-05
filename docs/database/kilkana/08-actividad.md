# Actividad — el registro de la redacción

`kilkana_activity` es la línea de tiempo del periódico: quién hizo qué, sobre qué y cuándo. La
escribe el propio panel al escribir, y la leen `/admin/activity` y la campana del armazón.

## Qué guarda cada fila

- **Quién, dos veces.** `user_id` para filtrar, y `actor_name` con `actor_role` tal como eran ese
  día: una cuenta que se retira o cambia de papel no puede reescribir lo que firmó. Sin `user_id`
  es la máquina —`Scheduler`—, que es quien publica a su hora.
- **`kind` y `verb`.** La clase es lo que el panel colorea y reparte en pestañas
  (`SnkKilkanaActivityModel::TABS`); el verbo es la frase, en el inglés del panel: «published»,
  «renamed the category», «approved 6 comments on».
- **`what`, `before`, `after`, `href`.** Sobre qué, el valor que había y el que hay cuando el
  movimiento fue sustituir uno por otro, y a qué pantalla lleva.
- **Solo `created_at`**, con índice: es una línea de tiempo y se lee por fecha. Se sirven noventa
  días (`DAYS`); lo anterior se queda y no se enseña. No hay poda todavía.

## Quién escribe, y cómo

`SnkKilkanaActivityService` es el único escritor, y es un singleton a propósito: `quiet()` es un
estado, y con una instancia por llamada el que calla no sería el que escucha.

- **Los modelos se cuentan solos.** El servicio los escucha desde el proveedor: una pieza cuenta
  su estado —creada, enviada a revisión, publicada, programada, despublicada, editada—, una
  sección su alta, su nombre y su baja, una etiqueta su alta y su baja, un medio su subida, su
  borrado y su vuelta, la portada su cambio, y los ajustes el tema que se viste —con el nombre
  del anterior— o el ajuste que se toca.
- **Lo que un modelo no puede contar lo apunta quien lo hace** con `log()`: un lote de moderación
  («approved 6 comments on» y la pieza, o «3 posts»), una invitación con su papel, una suspensión,
  una fusión de etiquetas, una versión restaurada, un lector bloqueado.
- **`quiet()` calla a los modelos** mientras dura la llamada: fundir dos etiquetas borra una y
  «deleted the tag» no es lo que pasó; restaurar una versión guarda la pieza y «edited» tampoco;
  sembrar quinientas piezas no es que la máquina creara quinientos borradores. Con `force` se
  apunta igual: es como el seeder escribe el registro de la demo con sus fechas.
- **Nunca falla hacia fuera.** Un registro que tumba la escritura que registra es peor que un
  hueco en el registro: `log()` captura y reporta.

## Lo que el panel lee

`GET activity` (`view others posts`: lo ve quien ve el sumario de la redacción, que deja fuera al
colaborador externo) devuelve `data` —del más reciente al más antiguo, por páginas de cincuenta
(`page`, `per_page` hasta cien) con `meta` como en `readers`—, `people` —quién aparece en el
registro visible, para el desplegable— y `stats`: movimientos de hoy, personas activas en siete
días (la máquina no cuenta), lo que salió —publicado o programado— y las decisiones de la puerta,
que hoy son cero porque la puerta no existe. Acepta `tab` (`content`, `people`, `system`), `who`
(el nombre tal cual), `count` —solo filas y hasta quinientas, que es lo que pide la campana, y así
el armazón paga una consulta y no seis— y `all`, que se lleva lo filtrado entero hasta cinco mil
para un export. Todas las cuentas van acotadas a los noventa días; `actor_name` lleva índice
porque se filtra y se lista por él y la tabla solo crece.

**Los movimientos de cuentas —`seat`— solo los ve quien tiene `index users`.** El resto de la
redacción no ve quién entró ni quién salió; es la única fila del registro que no es de todos.

## Lo que no hay

Ni búsqueda por texto, ni poda, ni marca de «leído» por cuenta, ni menciones. La puerta de salida
no escribe porque no existe.

## Hasta cuándo se miró

`users.activity_seen_at` guarda cuándo abrió cada cuenta la campana por última vez. `GET
activity?count=N` lo devuelve como `seen_at` junto a las filas, y la campana marca como no leído
lo posterior; `POST activity/seen` lo pone a ahora. Es una fecha por cuenta y no un aviso por
fila: lo que se lee es el registro, y una marca por fila sería inventarse avisos dirigidos que
no existen.
