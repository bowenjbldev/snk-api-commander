<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Packages\Kilkana\Enums\SnkKilkanaCommentStatusEnum;

/**
 * Un comentario puede quedar RECHAZADO, que no es spam ni es borrarlo.
 *
 * Con tres estados, descartar un comentario leido solo se podia hacer llamandolo basura o
 * borrandolo: lo primero ensucia lo que sirve para reconocer el spam siguiente, y lo segundo
 * se lleva el historial de la cuenta, que es lo que separa un mal dia de una cuenta vigilada.
 *
 * La columna es un `enum` de MariaDB, asi que admitir un valor mas es alterar el tipo. En
 * una base recien creada esto no cambia nada: la migracion original deriva los valores del
 * mismo enum de PHP, que ya lo trae.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kilkana_comments', function (Blueprint $table) {
            $table->enum('status', SnkKilkanaCommentStatusEnum::values())
                ->default(SnkKilkanaCommentStatusEnum::Pending->value)
                ->change();
        });
    }

    /**
     * Revertir se niega si queda alguno rechazado: convertirlos a spam o a pendiente seria
     * decidir por la moderacion, y el DDL de MariaDB no es transaccional.
     */
    public function down(): void
    {
        $rechazados = DB::table('kilkana_comments')
            ->where('status', SnkKilkanaCommentStatusEnum::Rejected->value)
            ->count();

        if ($rechazados > 0) {
            throw new RuntimeException(
                "There are {$rechazados} rejected comment(s); decide them again before rolling back."
            );
        }

        Schema::table('kilkana_comments', function (Blueprint $table) {
            $table->enum('status', [
                SnkKilkanaCommentStatusEnum::Pending->value,
                SnkKilkanaCommentStatusEnum::Approved->value,
                SnkKilkanaCommentStatusEnum::Spam->value,
            ])->default(SnkKilkanaCommentStatusEnum::Pending->value)->change();
        });
    }
};
