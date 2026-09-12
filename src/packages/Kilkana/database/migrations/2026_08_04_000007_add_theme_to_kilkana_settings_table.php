<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Con qué se viste el periódico, y **vestirse es copiar**.
 *
 * Tres columnas y no una, porque responden a tres preguntas distintas:
 *
 * - `theme_id` es de dónde salió. Sirve para el aviso de actualización y para que el catálogo
 *   sepa cuál está puesto; es una clave foránea de verdad y no una cadena suelta, porque una
 *   cadena sin validar acepta cualquier cosa —guardar «stándar» respondería 200 y el público
 *   caería al primer tema en silencio—.
 * - `theme_spec` es **el vestido**: la copia congelada del día que se eligió, con encima lo que
 *   la redacción haya movido. Es lo que dibuja el periódico, y por eso publicar la v2 de un
 *   tema no le cambia la portada a quien lleva la v1.
 * - `theme_overrides` es lo que la redacción ha movido ENCIMA del vestido, guardado como
 *   diferencia y no como otra copia entera: así «cuántos ajustes llevas» se cuenta solo,
 *   volver al tema de fábrica es vaciar un objeto, y cambiar de tema no arrastra de tapadillo
 *   los colores del anterior.
 * - `theme_version` es la del spec copiado. Comparada con la del catálogo es el aviso de que
 *   hay una versión nueva, y sin ella habría que diffear dos JSON para saberlo.
 *
 * `nullOnDelete` y no `restrictOnDelete`: como el vestido es la copia y no la fila del
 * catálogo, retirar un tema deja al periódico vestido igual —solo pierde el aviso—. Con la
 * copia colgando de la fila original habría que impedir el borrado; aquí no.
 *
 * `kilkana_settings` no lleva control de concurrencia optimista: los ajustes los toca poca
 * gente y rara vez, así que last-write-wins, dicho en voz alta. `kilkana_layouts` sí lo lleva,
 * y ahí su `version` es `required`, porque dos personas componiendo la portada a la vez es el
 * caso normal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kilkana_settings', function (Blueprint $table) {
            $table->foreignId('theme_id')
                ->nullable()
                ->after('id')
                ->constrained('kilkana_themes')
                ->nullOnDelete();
            $table->json('theme_spec')->nullable()->after('theme_id');
            $table->json('theme_overrides')->nullable()->after('theme_spec');
            $table->string('theme_version', 16)->nullable()->after('theme_overrides');
        });
    }

    public function down(): void
    {
        Schema::table('kilkana_settings', function (Blueprint $table) {
            $table->dropColumn(['theme_spec', 'theme_overrides', 'theme_version']);
            $table->dropConstrainedForeignId('theme_id');
        });
    }
};
