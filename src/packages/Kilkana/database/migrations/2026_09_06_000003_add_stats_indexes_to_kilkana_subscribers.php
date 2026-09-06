<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las cifras de la lista dejan de recorrerla entera.
 *
 * `SnkKilkanaNewsletterService::listStats()` —la banda de la pestaña de suscriptores— hace
 * cuatro preguntas y las cuatro barrían la tabla, porque `kilkana_subscribers` solo tenía la
 * clave primaria y el único de `email`. Medido con `ANALYZE` de MariaDB sobre 52 352 filas:
 *
 * | Consulta | Antes | Después |
 * | --- | ---: | ---: |
 * | altas confirmadas de la semana | 17,2 ms | 0,37 ms |
 * | bajas de la ventana de noventa días | 12,0 ms | 1,68 ms |
 * | `group by status` | 44,9 ms | 28,9 ms |
 *
 * `(status, consent_at)` sirve a las altas y, de paso, al `group by status`, que pasa a
 * recorrer el índice en vez de la tabla. `(status, updated_at)` sirve a las bajas. Las dos
 * empiezan por `status` porque las dos preguntan por un estado y luego por una ventana de
 * tiempo, que es lo que un índice compuesto resuelve de una pieza.
 *
 * **Lo que NO se crea: `(consent_source)`.** El otro `group by` de la pantalla parecía pedirlo
 * y con él va peor, de 18,2 a 22,4 ms: son tres valores repartidos sobre cincuenta mil filas,
 * así que recorrer el índice entero no ahorra nada frente a recorrer la tabla, y encima habría
 * que mantenerlo en cada alta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kilkana_subscribers', function (Blueprint $table) {
            $table->index(['status', 'consent_at'], 'kilkana_subscribers_status_consent_at_index');
            $table->index(['status', 'updated_at'], 'kilkana_subscribers_status_updated_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('kilkana_subscribers', function (Blueprint $table) {
            $table->dropIndex('kilkana_subscribers_status_consent_at_index');
            $table->dropIndex('kilkana_subscribers_status_updated_at_index');
        });
    }
};
