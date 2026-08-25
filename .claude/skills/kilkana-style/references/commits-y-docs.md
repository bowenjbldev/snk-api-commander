# El commit, la documentación y las puertas

---

## 1. La voz del commit

Conventional Commits en la cabecera, y **el asunto en español, narrativo, contando el efecto**.
Nunca la implementación. Casi siempre dos cláusulas unidas por «, y …»: lo que ahora pasa, y lo que
ha dejado de pasar.

```
fix(api): publicar una pieza es AHORA, y la fecha deja de pedirse a quien no la tiene
feat(seed): la fototeca estrena firma y estante, que salían en blanco en la mediateca
fix(api): subir el mismo fichero dos veces a la vez es un duplicado, no un error
perf(core): la caché de permisos se acota al inquilino y deja de rehacerse en cada petición
chore(security): la IP real llega a Laravel, y los tokens caducados dejan de acumularse
feat(kilkana)!: un tema es un spec, y el vestido baja a los ajustes
```

Tipos vivos: `feat`, `fix`, `perf`, `refactor`, `chore`, `docs`, `test`. Ámbitos: el que nombra la
puerta tocada (`api`, `core`, `seed`, `seeder`, `kilkana`, `commander`, `commander-web`,
`security`, `docs`). `!` para el cambio de contrato.

### La anatomía del cuerpo

Cuatro movimientos, en este orden:

1. **El síntoma, en negrita, y en la primera frase.** Y si no era una regresión, se dice.
2. **Por qué pasaba.** El mecanismo concreto, con el nombre del método y el mensaje de error
   literal si lo hubo.
3. **La regla que se establece**, en lista con negritas cuando son varios casos.
4. **La evidencia**: qué se comprobó, contra qué, y qué salió.

```
fix(api): publicar una pieza es AHORA, y la fecha deja de pedirse a quien no la tiene

**Desde el editor no se podía publicar una pieza**, y no era una regresión: nunca
se ha podido. Lo destapó la auditoría cruzada probando con una pieza completa.

Un borrador tiene `publish_at` nulo —se sella al salir a la calle—, así que el
relleno que `update()` hace desde la fila no tenía nada con que rellenar y
`required_if` rechazaba con «The publish at field is required when status is
published».

`stampFor()` recoge la regla entera, en el servicio y no en el controlador, para
que se pueda probar:

- sin fecha → ahora;
- **fecha futura → ahora**, que es lo que ya hacía el `bulk`;
- fecha pasada → se respeta, que es una decisión editorial;
- **`scheduled` no entra**: una programada sin fecha es un error de verdad.

Comprobado contra la API en las cuatro combinaciones, con piezas de usar y tirar
que se borraron después.
```

Firma al final:

```
Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
```

**Lo que no va en un commit**: «se ha refactorizado X», «se han añadido tests», «mejoras varias».
Si el asunto no dice qué cambia para quien usa esto, no está terminado.

---

## 2. Dónde va cada documento

La documentación canónica está en [`docs/`](../../../../docs/README.md), un documento por ámbito.
**No se añade Markdown suelto**: salvo `AGENTS.md` y el `README.md` de entrada, todo vive ahí.

| Cambia… | Se actualiza… |
|---|---|
| componentes, discovery, resolución de tenant, overlays, límites de seguridad | `docs/architecture.md` |
| request tenant, provisioning, fallback, de-provisioning, cuotas, despliegues | `docs/flows.md` |
| topología, tablas Core/Commander, esquema Kilkana | `docs/database.md` y `docs/database/kilkana/` |
| negocio, entidades, cardinalidades, contratos | `docs/commander-model.md` |
| Kilkana como producto, planes | `docs/business.md` |
| puesta en marcha, Docker, migraciones, seeders, scheduler | `docs/operations.md` |
| dominios propios, DNS, TLS, SEO multi-host | `docs/domains.md` |

Dos reglas que hacen el árbol autorreparable:

- **Cada tema tiene un dueño único.** Quien necesita una cifra enlaza; no la copia. Un número
  copiado en seis sitios envejece a seis velocidades.
- **Cuando documento y código discrepan sobre qué *existe*, gana el código y el documento es un
  bug**, y se corrige en el mismo cambio que lo destapó. Un documento que ya mintió una vez deja de
  leerse entero.

Lo que se aprende arreglando algo se anota en el documento **de su tema**, no en el que se tenía
abierto: es lo único que evita que dos documentos digan cosas distintas sobre lo mismo.

---

## 3. Las puertas

```bash
docker compose exec -T app php artisan test
cd src && vendor/bin/pint --test && composer audit
```

Cuando toca migraciones o rutas:

```bash
docker compose exec -T app php artisan config:cache
docker compose exec -T app php artisan route:cache
docker compose exec -T provisioner php artisan core:migrate --all-tenants --force
```

Y tras un despliegue que traiga temas nuevos:

```bash
docker compose exec -T app php artisan kilkana:sync-themes
```

Falla si un tema no valida, y eso es lo correcto.

### Lo que las puertas NO cubren

Los tests corren sobre SQLite `:memory:`. **No validan permisos DDL, MariaDB, collation ni
`information_schema`.** Un cambio que toque cualquiera de esos cuatro se prueba contra MariaDB de
verdad, con:

```bash
docker compose exec -T db sh -c 'mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" diario_norte_kilkana -e "…"'
```

Los clientes locales son `diario_norte`, `diario_centro` y `diario_sur`.

**Reporta test, build y lint por separado.** Uno verde no implica los demás, y decir «pasa todo»
cuando solo corriste uno es lo que hace que la siguiente sesión se fíe de un dato falso.

---

## 4. Una regla que se rompe dos veces se convierte en una puerta

No en un párrafo. Si algo se ha roto dos veces y un documento no lo ha impedido, lo que falta es un
test que lo cante, y ese test entra en el mismo commit. **Lo que no está en la puerta se rompe en
silencio.**

Ejemplos vivos en este repositorio: los tests que cuentan consultas para que un N+1 no vuelva, los
que fijan el SQL con `->toSql()`, y las tres pruebas gemelas que atan los contratos que comparte con
`kilkana.app` y que ningún compilador ve.
