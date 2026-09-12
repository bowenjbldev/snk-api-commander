<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Packages\Kilkana\Enums\SnkKilkanaCommentStatusEnum;

/**
 * Un comentario puede quedar RECHAZADO, que no es spam ni es borrarlo.
 *
 * Con tres estados, descartar un comentario leído solo se puede hacer llamándolo basura o
 * borrándolo: lo primero ensucia lo que sirve para reconocer el spam siguiente, y lo segundo
 * se lleva el historial de la cuenta, que es lo que separa un mal día de una cuenta vigilada.
 *
 * La columna es un `enum` de MariaDB, así que admitir un valor más es alterar el tipo. En
 * una base recién creada esto no cambia nada: la migración original deriva los valores del
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
     * Revertir se niega si queda alguno rechazado: convertirlos a spam o a pendiente sería
     * decidir por la moderación, y el DDL de MariaDB no es transaccional.
     */
    public function down(): void
    {
        $rejectedCount = DB::table('kilkana_comments')
            ->where('status', SnkKilkanaCommentStatusEnum::Rejected->value)
            ->count();

        if ($rejectedCount > 0) {
            throw new RuntimeException(
                "There are {$rejectedCount} rejected comment(s); decide them again before rolling back."
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
