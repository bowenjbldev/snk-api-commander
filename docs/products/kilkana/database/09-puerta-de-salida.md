# Puerta de salida — el veredicto sobre cada URL pública

`kilkana_gate_results` guarda lo que la salida pública dijo de cada ruta en su última pasada: qué
invariantes pasa y cuándo se miró. **Aquí no se comprueba nada.** Quien sirve el HTML es la
salida pública —la aplicación SvelteKit—, y es quien lo lee: descubre las rutas por la API, se
las pide a sí misma y escribe el veredicto con `PUT gate`. El backend lo guarda y lo sirve, y
deja rastro.

## Qué guarda cada fila

- **`url`** única, y **`kind`** —portada, sección, pieza, firma, etiqueta, búsqueda, boletín, xml—,
  que es lo que el panel filtra.
- **`passing`**: nueve booleanos en el orden de las columnas del panel, o nulo.
- **`checked_at`**: cuándo se miró. Nulo con `passing` nulo es una ruta descubierta que la salida
  no sirve —contesta 404—, que no es lo mismo que fallar y no cuenta como bloqueo.

Sin marcas de tiempo propias: la fila entera se reemplaza en cada pasada.

## Una pasada sustituye la anterior entera

`PUT gate` recibe todas las rutas de la pasada y reemplaza la tabla en una transacción: las rutas
se descubren de nuevo cada vez, así que una que ya no existe no puede quedarse contando. Acepta
hasta quinientas, con `passing` de exactamente nueve o nulo. Lo corre quien publica
(`publish posts`), porque correr la puerta es lo que precede a un despliegue; lo lee quien abre
el panel (`index posts`), y `counts=1` devuelve solo cuántas rutas bloquean y ninguna fila. El
raíl ya no lo pide por aquí —`panel/bootstrap` se lo llevó—, pero sigue siendo la definición de
esa cifra y contra ella se comprueba que el compuesto no haya derivado.

Cada pasada deja rastro en el registro de actividad ([`08-actividad.md`](08-actividad.md)):
«blocked the deploy: 3 of 18 routes fail» o «passed the output gate on 15 routes».

## Lo que no hay

Ni despliegues —la puerta decide y nada despliega— ni historial de pasadas: se guarda la última.
