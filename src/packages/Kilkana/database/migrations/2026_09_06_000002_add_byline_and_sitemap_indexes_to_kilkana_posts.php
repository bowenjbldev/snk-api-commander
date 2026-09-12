<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los dos recorridos del archivo que quedaban en la salida pública: la portada de una firma y
 * el sitemap.
 *
 * Medido con `ANALYZE` de MariaDB sobre una copia de 68 096 piezas —60 672 publicadas, 17
 * secciones, 11 firmas—, y no sobre las 532 de la demo: con quinientas filas el optimizador
 * elige el barrido haga lo que haga el índice, así que el plan no dice nada.
 *
 * **`(author_id, status, publish_at)`.** El `whereHas('author')` del listado se resuelve como
 * una constante —`slug` es único, así que MariaDB conoce el `author_id` antes de tocar
 * `kilkana_posts`—, pero sin un índice que empiece por ahí la consulta recorría
 * `(publish_at, id)` leyendo 1 202 filas para quedarse con 50: el 4 %. Con él lee 50. De
 * 73,6 ms a 1,8 ms, y la distancia crece con el archivo, porque lo que crece es lo que
 * descarta.
 *
 * **El del sitemap, y cubre la consulta entera.** `public/sitemap` devuelve el archivo
 * publicado sin cota: barrido de 68 096 filas más un filesort de 58 838, 1 800 ms, el endpoint
 * público más caro con diferencia. El índice lleva las tres columnas del filtro, después
 * `publish_at` e `id` para el orden, y detrás las tres que la consulta devuelve; así se resuelve
 * dentro del índice (`using_index`), sin ordenar y sin bajar a la tabla ni una vez: 39,6 ms.
 *
 * Las dos decisiones raras van a propósito. `id` se nombra aunque InnoDB lo lleve pegado detrás
 * de toda clave secundaria: sin nombrarlo, el orden vuelve a costar un filesort de 58 838 filas
 * y la consulta se dobla a 79,9 ms. Y `deleted_at` va DENTRO porque `is null` se resuelve como
 * una igualdad más; fuera, comprobarlo obligaría a bajar a la tabla por cada fila y el índice
 * dejaría de cubrir —forzado en esa forma, la consulta sube a 3 460 ms, peor que el barrido—.
 *
 * Ocupa 6,5 MB sobre 213,8 MB de tabla, un 3 %, y se paga en cada escritura de una pieza. Es
 * caro para un índice y aun así sale a cuenta: el sitemap lo piden los buscadores, solos y sin
 * que nadie mire la pantalla.
 *
 * **Lo que NO se crea: `(category_id, status, publish_at)`.** Parecía el
 * gemelo del de la firma y la medida dice lo contrario. La sección no llega como constante
 * —el filtro acepta la sección o cualquiera de sus hijas, así que es un conjunto—, y con el
 * índice delante MariaDB deja de guiarse por `(status, publish_at)`: pasa a recorrer
 * `kilkana_categories`, entra por `category_id` y ordena a mano lo que saca. De 3,5 ms a
 * 2 329 ms. Sin él lee 434 filas para devolver 50, que es un precio razonable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kilkana_posts', function (Blueprint $table) {
            $table->index(['author_id', 'status', 'publish_at'], 'kilkana_posts_author_status_publish_at_index');
            $table->index(
                ['status', 'noindex', 'deleted_at', 'publish_at', 'id', 'url_date', 'slug', 'updated_at'],
                'kilkana_posts_sitemap_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('kilkana_posts', function (Blueprint $table) {
            $table->dropIndex('kilkana_posts_author_status_publish_at_index');
            $table->dropIndex('kilkana_posts_sitemap_index');
        });
    }
};
