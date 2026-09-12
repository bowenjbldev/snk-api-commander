<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Packages\Kilkana\Enums\SnkKilkanaAuthorKindEnum;

/**
 * La firma estrena su clase y su correo público, y deja de exigir una cuenta.
 *
 * Las tres columnas contestan la misma pregunta desde tres lados: **una firma no es un
 * asiento**. Una agencia publica sin entrar al panel, así que `user_id` pasa a admitir nulo y
 * el seeder no inventa una cuenta por agencia solo para satisfacer la clave foránea. El
 * correo tampoco puede ser el de la cuenta: `users.email` es con el que se ENTRA, y el de la
 * página de autor es con el que se CONTACTA — y la ficha no puede publicar el primero como si
 * fuera el segundo.
 *
 * `kind` nace en `contributor` porque es el alta más común —una colaboración que firma y no
 * entra— y es lo que el panel crea por defecto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kilkana_authors', function (Blueprint $table) {
            $table->enum('kind', SnkKilkanaAuthorKindEnum::values())
                ->default(SnkKilkanaAuthorKindEnum::Contributor->value)
                ->after('slug');

            // El contacto público de la página de autor, y uno de los cuatro requisitos que
            // decide si esa página vale algo en un buscador.
            $table->string('public_email')->nullable()->after('website');

            // Nulo permitido: una agencia firma sin cuenta. El índice único lo tolera —varios
            // nulos no chocan entre sí— así que la firma con cuenta sigue siendo 1:1.
            $table->foreignId('user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Volver atrás exige que ninguna firma se haya quedado sin cuenta, o la columna no
        // puede recuperar el NOT NULL. No se inventa una: quien revierte decide a quién
        // enlazarlas, igual que decide el estado de una sección oculta.
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
