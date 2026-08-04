<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Packages\Kilkana\Enums\SnkKilkanaThemeSourceEnum;

/**
 * La variante deja de ser un puntero al código y pasa a llevar el tema entero.
 *
 * Hasta ahora `base_slug` señalaba una definición de `themes/catalog.ts` y la fila solo guardaba
 * el retoque de la redacción. Eso funciona con dos variantes que envía el producto y no funciona
 * con un catálogo que se vende: publicar un tema exigiría tocar el frontend y desplegar.
 *
 * Y no hacía falta. Un tema ya era JSON: los tokens, el estilo —incluidos el kicker y la
 * tarjeta—, `masthead` (un enum de dos valores) y las plantillas de arranque son todo dato
 * serializable. Lo que se dijo en su día de que «no baja a la base» era la política de qué puede
 * tocar un INQUILINO, no un límite del formato. Así que baja entero, en `spec`.
 *
 * `tokens` y `style` se quedan, pero ahora significan solo una cosa: la diferencia que puso la
 * redacción encima de su spec. Siguen validados por `shapeRules()`.
 *
 * `source` sustituye a `is_builtin` porque la pregunta útil no es si se puede borrar, sino quién
 * manda sobre la fila: el sincronizador reescribe las `catalog` y no toca jamás las `tenant`.
 * De ahí sale el congelado — publicar una versión nueva de un tema no le cambia la portada a
 * ningún periódico que ya lo lleve puesto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kilkana_theme_variants', function (Blueprint $table) {
            $table->enum('source', SnkKilkanaThemeSourceEnum::values())
                ->default(SnkKilkanaThemeSourceEnum::Catalog->value)
                ->after('theme_id');
            // De qué variante del catálogo salió esta copia. SET NULL: retirar un tema del
            // catálogo no puede llevarse por delante el que un periódico está vistiendo.
            $table->foreignId('origin_variant_id')->nullable()->after('source')
                ->constrained('kilkana_theme_variants')->nullOnDelete();
            $table->string('origin_version', 16)->nullable()->after('origin_variant_id');
            $table->json('spec')->nullable()->after('name');
            $table->unsignedSmallInteger('position')->default(0)->after('style');
            $table->boolean('is_default')->default(false)->after('position');
            $table->string('thumbnail_path')->nullable()->after('is_default');
        });

        // Lo sembrado hasta hoy vino del producto, así que es catálogo. Lo que vistiera un
        // periódico se materializa como copia propia en la primera adopción; aquí todavía no
        // hay spec con el que copiarlo.
        DB::table('kilkana_theme_variants')
            ->where('is_builtin', true)
            ->update(['source' => SnkKilkanaThemeSourceEnum::Catalog->value]);

        DB::table('kilkana_theme_variants')
            ->where('is_builtin', false)
            ->update(['source' => SnkKilkanaThemeSourceEnum::Tenant->value]);

        Schema::table('kilkana_theme_variants', function (Blueprint $table) {
            $table->dropColumn(['base_slug', 'is_builtin']);
        });
    }

    public function down(): void
    {
        Schema::table('kilkana_theme_variants', function (Blueprint $table) {
            $table->string('base_slug', 64)->default('standard')->after('theme_id');
            $table->boolean('is_builtin')->default(false)->after('style');
        });

        DB::table('kilkana_theme_variants')
            ->where('source', SnkKilkanaThemeSourceEnum::Catalog->value)
            ->update(['is_builtin' => true]);

        // El CHECK de `spec` cita su columna y MariaDB no deja soltarla mientras exista.
        DB::statement('ALTER TABLE kilkana_theme_variants DROP CONSTRAINT IF EXISTS spec');

        Schema::table('kilkana_theme_variants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('origin_variant_id');
            $table->dropColumn(['source', 'origin_version', 'spec', 'position', 'is_default', 'thumbnail_path']);
        });
    }
};
