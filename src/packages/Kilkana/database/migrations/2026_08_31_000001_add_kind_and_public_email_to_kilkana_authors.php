<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Packages\Kilkana\Enums\SnkKilkanaAuthorKindEnum;

/**
 * La firma estrena su clase y su correo publico, y deja de exigir una cuenta.
 *
 * Las tres columnas contestan la misma pregunta desde tres lados: **una firma no es un
 * asiento**. Una agencia publica sin entrar al panel, asi que `user_id` pasa a admitir nulo y
 * el seeder deja de inventar una cuenta por agencia solo para satisfacer la clave foranea. El
 * correo tampoco puede seguir siendo el de la cuenta: `users.email` es con el que se ENTRA, y
 * el de la pagina de autor es con el que se CONTACTA — publicar el primero como si fuera el
 * segundo es lo que hacia la ficha.
 *
 * `kind` nace en `contributor` porque es el alta mas comun —una colaboracion que firma y no
 * entra— y es lo que el panel ya crea por defecto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kilkana_authors', function (Blueprint $table) {
            $table->enum('kind', SnkKilkanaAuthorKindEnum::values())
                ->default(SnkKilkanaAuthorKindEnum::Contributor->value)
                ->after('slug');

            // El contacto publico de la pagina de autor, y uno de los cuatro requisitos que
            // decide si esa pagina vale algo en un buscador.
            $table->string('public_email')->nullable()->after('website');

            // Nulo permitido: una agencia firma sin cuenta. El indice unico lo tolera —varios
            // nulos no chocan entre si— asi que la firma con cuenta sigue siendo 1:1.
            $table->foreignId('user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Volver atras exige que ninguna firma se haya quedado sin cuenta, o la columna no
        // puede recuperar el NOT NULL. No se inventa una: quien revierte decide a quien
        // enlazarlas, igual que decide el estado de una seccion oculta.
        $bylinesWithoutAccount = DB::table('kilkana_authors')->whereNull('user_id')->count();

        if ($bylinesWithoutAccount > 0) {
            throw new RuntimeException(
                "There are {$bylinesWithoutAccount} byline(s) without an account; link them to a user before rolling back."
            );
        }

        Schema::table('kilkana_authors', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable(false)->change();
            $table->dropColumn(['kind', 'public_email']);
        });
    }
};
