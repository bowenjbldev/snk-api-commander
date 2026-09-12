<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Packages\Kilkana\Enums\SnkKilkanaCategoryStatusEnum;

/**
 * La sección estrena el estado que le faltaba: retirada del menú, no de la calle.
 *
 * Con dos estados, dejar de ofrecer una sección solo se puede hacer devolviéndola a
 * preparación, y eso lo impide el propio modelo en cuanto tiene una pieza publicada — con
 * razón: `draft` no admite piezas publicadas y sus URLs dejarían de responder. `hidden` es
 * la respuesta correcta a esa necesidad: sigue abriéndose por su URL y desaparece de la
 * navegación.
 *
 * La columna es un `enum` de MariaDB, así que admitir un valor más es alterar el tipo. En
 * una base recién creada esto no cambia nada: la migración original deriva los valores del
 * mismo enum de PHP, que ya lo trae.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kilkana_categories', function (Blueprint $table) {
            $table->enum('status', SnkKilkanaCategoryStatusEnum::values())
                ->default(SnkKilkanaCategoryStatusEnum::Draft->value)
                ->change();
        });
    }

    /**
     * Revertir **se niega** si queda alguna oculta, en vez de elegir por su cuenta.
     *
     * Las dos salidas automáticas mienten: a `live` publica en el menú secciones que alguien
     * retiró a mano, y a `draft` retira de la calle URLs que estaban respondiendo —y choca con
     * el muro de las piezas publicadas—. Ninguna de las dos es «lo mismo de antes», así que la
     * decisión se devuelve a donde está la información.
     *
     * Y hay un motivo mecánico además del semántico: el DDL de MariaDB **no es transaccional**,
     * así que un `UPDATE` masivo seguido de un `ALTER` que falle deja las ocultas ya
     * convertidas y el tipo sin estrechar, que es lo peor de las dos.
     */
    public function down(): void
    {
        $hiddenCount = DB::table('kilkana_categories')
            ->where('status', SnkKilkanaCategoryStatusEnum::Hidden->value)
            ->count();

        if ($hiddenCount > 0) {
            throw new RuntimeException(
                "There are {$hiddenCount} hidden section(s); set them to live or draft before rolling back."
            );
        }

        Schema::table('kilkana_categories', function (Blueprint $table) {
            $table->enum('status', [
                SnkKilkanaCategoryStatusEnum::Draft->value,
                SnkKilkanaCategoryStatusEnum::Live->value,
            ])->default(SnkKilkanaCategoryStatusEnum::Draft->value)->change();
        });
    }
};
