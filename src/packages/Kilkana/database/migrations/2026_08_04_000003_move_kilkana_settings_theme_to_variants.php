<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El tema del periodico deja de ser una cadena y pasa a ser una clave foranea.
 *
 * `theme` era un `varchar(64)` que aceptaba cualquier cosa: guardar «stándar» respondia 200 y
 * el publico caia al primer tema en silencio. Y `theme_overrides` guardaba, por variante, justo
 * lo que una redaccion podia cambiar — o sea, era una tabla de variantes escrita dentro de una
 * columna. Al existir `kilkana_theme_variants` las dos sobran: la variante ES la fila.
 *
 * `version` se va con ellas. Era control de concurrencia optimista opcional —`nullable` en el
 * controlador, y ningun cliente la mandaba—, asi que la comprobacion no llegaba a correr nunca
 * y la respuesta devolvia un numero que no garantizaba nada. Los ajustes los toca poca gente y
 * rara vez: last-write-wins, dicho en voz alta. `kilkana_layouts` SI la conserva, y ahi es
 * `required`, porque dos personas componiendo la portada a la vez es el caso normal.
 *
 * La lista de variantes esta CONGELADA a proposito. Es una migracion de datos: tiene que
 * reproducir lo que habia el dia que se escribio, no lo que envie el producto dentro de un ano.
 * La lista viva vive en `SnkKilkanaThemeVariantModel::BUILTIN`.
 */
return new class extends Migration
{
    /** El paquete y sus variantes tal como estaban al escribir esta migracion. */
    private const PACK = 'press';

    private const BUILTIN = ['standard', 'compact'];

    public function up(): void
    {
        Schema::table('kilkana_settings', function (Blueprint $table) {
            $table->foreignId('theme_variant_id')
                ->nullable()
                ->after('id')
                ->constrained('kilkana_theme_variants')
                ->restrictOnDelete();
        });

        $this->backfill();

        // MariaDB no deja soltar una columna citada por un CHECK: `$table->json()` crea
        // `CHECK (json_valid(theme_overrides))` con el nombre de la propia columna, y el DROP
        // falla con «hence column cannot be dropped or renamed». Fuera la restriccion primero.
        DB::statement('ALTER TABLE kilkana_settings DROP CONSTRAINT IF EXISTS theme_overrides');

        Schema::table('kilkana_settings', function (Blueprint $table) {
            $table->dropColumn(['theme', 'theme_overrides', 'version']);
        });
    }

    public function down(): void
    {
        Schema::table('kilkana_settings', function (Blueprint $table) {
            $table->string('theme', 64)->nullable()->after('id');
            $table->json('theme_overrides')->nullable()->after('theme');
            $table->unsignedInteger('version')->default(1)->after('onboarded');
        });

        $this->restore();

        Schema::table('kilkana_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('theme_variant_id');
        });
    }

    /**
     * De `theme` + `theme_overrides` a filas.
     *
     * Una entrada de overrides con contenido era una variante propia de esa redaccion, asi que
     * se convierte en una fila NO builtin: las builtin son punteros limpios a lo que envia el
     * producto, y meterles ahi lo que alguien retoco romperia esa garantia.
     */
    private function backfill(): void
    {
        $ajustes = DB::table('kilkana_settings')->where('id', 1)->first();
        if (! $ajustes) {
            return; // Base recien migrada: no hay nada que mover y el seeder hara su trabajo.
        }

        $themeId = $this->pack();
        foreach (self::BUILTIN as $slug) {
            $this->variant($themeId, $slug, $slug, true);
        }

        $elegida = in_array($ajustes->theme, self::BUILTIN, true) ? $ajustes->theme : null;
        $overrides = json_decode((string) ($ajustes->theme_overrides ?? ''), true);
        $overrides = is_array($overrides) ? $overrides : [];

        foreach ($overrides as $base => $override) {
            // Una clave que no es una variante conocida ya estaba muerta: `loadOverrides` solo
            // conserva las que casan con el catalogo, asi que el frontend nunca la aplicaba.
            if (! in_array($base, self::BUILTIN, true) || ! is_array($override)) {
                continue;
            }

            $tokens = is_array($override['tokens'] ?? null) && $override['tokens'] !== [] ? $override['tokens'] : null;
            $style = is_array($override['style'] ?? null) && $override['style'] !== [] ? $override['style'] : null;
            if ($tokens === null && $style === null) {
                continue;
            }

            $this->variant($themeId, "{$base}-custom", $base, false, $tokens, $style);
            if ($base === $elegida) {
                $elegida = "{$base}-custom";
            }
        }

        if ($elegida !== null) {
            DB::table('kilkana_settings')->where('id', 1)->update([
                'theme_variant_id' => DB::table('kilkana_theme_variants')->where('slug', $elegida)->value('id'),
            ]);
        }
    }

    /** Deshace el movimiento: la variante vuelve a ser una cadena y su diferencia, un mapa. */
    private function restore(): void
    {
        $ajustes = DB::table('kilkana_settings')->where('id', 1)->first();
        if (! $ajustes || ! $ajustes->theme_variant_id) {
            return;
        }

        $variante = DB::table('kilkana_theme_variants')->where('id', $ajustes->theme_variant_id)->first();
        if (! $variante) {
            return;
        }

        $override = array_filter([
            'tokens' => json_decode((string) ($variante->tokens ?? ''), true),
            'style' => json_decode((string) ($variante->style ?? ''), true),
        ], static fn ($valor) => is_array($valor) && $valor !== []);

        DB::table('kilkana_settings')->where('id', 1)->update([
            'theme' => $variante->base_slug,
            'theme_overrides' => $override === [] ? null : json_encode([$variante->base_slug => $override]),
        ]);
    }

    private function pack(): int
    {
        $id = DB::table('kilkana_themes')->where('slug', self::PACK)->value('id');

        return $id ? (int) $id : (int) DB::table('kilkana_themes')->insertGetId([
            'slug' => self::PACK,
            'position' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function variant(int $themeId, string $slug, string $base, bool $builtin, ?array $tokens = null, ?array $style = null): void
    {
        if (DB::table('kilkana_theme_variants')->where('slug', $slug)->exists()) {
            return;
        }

        DB::table('kilkana_theme_variants')->insert([
            'theme_id' => $themeId,
            'base_slug' => $base,
            'slug' => $slug,
            'tokens' => $tokens === null ? null : json_encode($tokens),
            'style' => $style === null ? null : json_encode($style),
            'is_builtin' => $builtin,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
