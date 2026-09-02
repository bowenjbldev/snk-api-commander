<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La papelera de las piezas, y quién tiró cada cosa.
 *
 * Una pieza borrada deja de existir para el panel y para la calle, pero se queda treinta días
 * con su URL reservada: restaurarla no rompe ningún enlace entrante, y a los treinta días se
 * purga sola. `deleted_by` es de la papelera —«quién lo tiró» es la mitad de lo que se lee
 * antes de decidir— y se añade también a los medios, que ya tenían el borrado lógico y no el
 * nombre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kilkana_posts', function (Blueprint $table) {
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->after('deleted_at')->constrained('users')->nullOnDelete();
            $table->index('deleted_at');
        });
        Schema::table('kilkana_media', function (Blueprint $table) {
            $table->foreignId('deleted_by')->nullable()->after('deleted_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('kilkana_media', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deleted_by');
        });
        Schema::table('kilkana_posts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deleted_by');
            $table->dropIndex(['deleted_at']);
            $table->dropSoftDeletes();
        });
    }
};
