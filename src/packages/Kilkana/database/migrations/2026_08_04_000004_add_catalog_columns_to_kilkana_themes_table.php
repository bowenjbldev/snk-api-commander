<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Packages\Kilkana\Enums\SnkKilkanaThemeStatusEnum;

/**
 * `kilkana_themes` deja de ser un puntero a una familia de código y pasa a ser el tema vendible.
 *
 * La tabla nació con lo justo —slug, nombre, posición— porque el catálogo eran dos variantes. El
 * producto pide muchos temas navegables, filtrables por categoría y ordenables por varios
 * criterios, así que aquí entra lo necesario para describir y ordenar el catálogo.
 *
 * `status` existe por el mismo motivo que en las secciones: un tema se prepara antes de
 * ofrecerlo, y retirarlo no puede romper a quien ya lo viste. Como al adoptarlo se guarda una
 * copia congelada del spec, `deprecated` deja de venderlo sin tocar ningún periódico vivo.
 *
 * `popularity` es un número CURADO, no medido. Con el catálogo replicado en la base de cada
 * inquilino no hay dónde contar cuántos periódicos visten un tema: cada base solo se ve a sí
 * misma. Medirlo de verdad exigiría un contador global en el control plane.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kilkana_themes', function (Blueprint $table) {
            $table->string('tagline')->nullable()->after('name');
            $table->text('description')->nullable()->after('tagline');
            $table->enum('status', SnkKilkanaThemeStatusEnum::values())
                ->default(SnkKilkanaThemeStatusEnum::Draft->value)
                ->after('description');
            $table->boolean('featured')->default(false)->after('status');
            $table->unsignedInteger('popularity')->default(0)->after('position');
            // La del fichero maestro. El sincronizador compara contra esta y no reescribe un
            // tema que no ha cambiado: en un catálogo de 300, publicar uno escribe una fila.
            $table->string('version', 16)->default('1.0.0')->after('popularity');
            $table->string('thumbnail_path')->nullable()->after('version');
            $table->json('screenshots')->nullable()->after('thumbnail_path');
            $table->string('demo_url', 2048)->nullable()->after('screenshots');
            $table->timestamp('published_at')->nullable()->after('demo_url');

            // Las dos parrillas del catálogo: la curada y la de novedades.
            $table->index(['status', 'position']);
            $table->index(['status', 'published_at']);
        });

        // El buscador del catálogo, sin motor externo — mismo patrón que `kilkana_posts`.
        Schema::table('kilkana_themes', function (Blueprint $table) {
            $table->fullText(['name', 'tagline', 'description']);
        });
    }

    public function down(): void
    {
        Schema::table('kilkana_themes', function (Blueprint $table) {
            $table->dropFullText(['name', 'tagline', 'description']);
            $table->dropIndex(['status', 'position']);
            $table->dropIndex(['status', 'published_at']);
        });

        // El CHECK de `screenshots` cita su columna, y MariaDB no deja soltarla mientras exista.
        DB::statement('ALTER TABLE kilkana_themes DROP CONSTRAINT IF EXISTS screenshots');

        Schema::table('kilkana_themes', function (Blueprint $table) {
            $table->dropColumn([
                'tagline', 'description', 'status', 'featured', 'popularity',
                'version', 'thumbnail_path', 'screenshots', 'demo_url', 'published_at',
            ]);
        });
    }
};
