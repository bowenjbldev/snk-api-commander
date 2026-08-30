<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Packages\Kilkana\Enums\SnkKilkanaCategoryStatusEnum;

/**
 * La seccion estrena el estado que le faltaba: retirada del menu, no de la calle.
 *
 * Con dos estados, dejar de ofrecer una seccion solo se podia hacer devolviendola a
 * preparacion, y eso lo impide el propio modelo en cuanto tiene una pieza publicada — con
 * razon: `draft` no admite piezas publicadas y sus URLs dejarian de responder. `hidden` es
 * la respuesta correcta a esa necesidad: sigue abriendose por su URL y desaparece de la
 * navegacion.
 *
 * La columna es un `enum` de MariaDB, asi que admitir un valor mas es alterar el tipo. En
 * una base recien creada esto no cambia nada: la migracion original deriva los valores del
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
     * Las dos salidas automaticas mienten: a `live` publica en el menu secciones que alguien
     * retiro a mano, y a `draft` retira de la calle URLs que estaban respondiendo —y choca con
     * el muro de las piezas publicadas—. Ninguna de las dos es «lo mismo de antes», asi que la
     * decision se devuelve a donde esta la informacion.
     *
     * Y hay un motivo mecanico ademas del semantico: el DDL de MariaDB **no es transaccional**,
     * asi que un `UPDATE` masivo seguido de un `ALTER` que falle deja las ocultas ya
     * convertidas y el tipo sin estrechar, que es lo peor de las dos.
     */
    public function down(): void
    {
        $ocultas = DB::table('kilkana_categories')
            ->where('status', SnkKilkanaCategoryStatusEnum::Hidden->value)
            ->count();

        if ($ocultas > 0) {
            throw new RuntimeException(
                "There are {$ocultas} hidden section(s); set them to live or draft before rolling back."
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
