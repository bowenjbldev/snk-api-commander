<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Con que se viste el periodico, y **vestirse es copiar**.
 *
 * Tres columnas y no una, porque responden a tres preguntas distintas:
 *
 * - `theme_id` es de donde salio. Sirve para el aviso de actualizacion y para que el catalogo
 *   sepa cual esta puesto; es una clave foranea de verdad y no una cadena suelta, porque
 *   `theme` era un `varchar(64)` que aceptaba cualquier cosa —guardar «stándar» respondia 200 y
 *   el publico caia al primer tema en silencio—.
 * - `theme_spec` es **el vestido**: la copia congelada del dia que se eligio, con encima lo que
 *   la redaccion haya movido. Es lo que dibuja el periodico, y por eso publicar la v2 de un
 *   tema no le cambia la portada a quien lleva la v1.
 * - `theme_overrides` es lo que la redaccion ha movido ENCIMA del vestido, guardado como
 *   diferencia y no como otra copia entera: asi «cuantos ajustes llevas» se cuenta solo,
 *   volver al tema de fabrica es vaciar un objeto, y cambiar de tema no arrastra de tapadillo
 *   los colores del anterior.
 * - `theme_version` es la del spec copiado. Comparada con la del catalogo es el aviso de que
 *   hay una version nueva, y sin ella habria que diffear dos JSON para saberlo.
 *
 * `nullOnDelete` y no `restrictOnDelete`: como el vestido es la copia y no la fila del
 * catalogo, retirar un tema deja al periodico vestido igual —solo pierde el aviso—. Con la
 * copia colgando de la fila original habria que impedir el borrado; aqui no.
 *
 * Se van de `kilkana_settings` tres columnas que esto sustituye. `theme` era una cadena sin
 * validar: guardar «stándar» respondia 200 y el publico caia al primer tema en silencio. El
 * `theme_overrides` de entonces guardaba una entrada POR VARIANTE —o sea, era una tabla de
 * variantes escrita dentro de una columna—; el de ahora es un solo mapa, el del tema puesto.
 * Y `version` era control de concurrencia optimista opcional: `nullable`
 * en el controlador y ningun cliente la mandaba, asi que la comprobacion no llegaba a correr
 * nunca y la respuesta devolvia un numero que no garantizaba nada. Los ajustes los toca poca
 * gente y rara vez: last-write-wins, dicho en voz alta. `kilkana_layouts` SI la conserva, y
 * ahi es `required`, porque dos personas componiendo la portada a la vez es el caso normal.
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
