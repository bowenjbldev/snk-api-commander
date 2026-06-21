<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Tabla UNICA de cuotas por (package, metrica): la edita el panel (limit_value/on_exceed/unit) y
        // la mide/aplica el motor (CommanderQuotaMeasurerService: current_value/measured_at/exceeded_at).
        // No hay tabla espejo aparte.
        Schema::create('commander_quotas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained('commander_packages')->cascadeOnDelete();
            $table->string('metric_key', 64); // requests_day|disk_db_mb|disk_storage_mb|users_total
            $table->unsignedBigInteger('current_value')->default(0);
            $table->unsignedBigInteger('limit_value')->nullable(); // null = unlimited
            $table->string('on_exceed', 16)->default('none'); // none|warn|suspend
            $table->timestamp('exceeded_at')->nullable(); // cuando supero el limite por primera vez (lo gestiona el motor)
            $table->timestamp('measured_at')->nullable(); // ultima medicion del motor
            $table->string('unit', 16)->default('count'); // count|mb
            $table->timestamps();

            // Una fila por (package, metrica).
            $table->unique(['package_id', 'metric_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commander_quotas');
    }
};
