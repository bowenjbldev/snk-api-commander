<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los dos estados de una cuenta que no son «activa».
 *
 * `invited_at` dice que la cuenta existe y su dueño todavía no ha entrado: ocupa asiento desde
 * que se invita, y se vacía cuando pone su primera contraseña. `suspended_at` cierra el paso
 * al panel sin soltar el asiento ni retirar la firma, y por eso se deshace. Los dos son fechas
 * y no banderas: cuándo se invitó es lo que dice si el enlace habrá caducado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('invited_at')->nullable()->after('email_verified_at');
            $table->timestamp('suspended_at')->nullable()->after('invited_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['invited_at', 'suspended_at']);
        });
    }
};
