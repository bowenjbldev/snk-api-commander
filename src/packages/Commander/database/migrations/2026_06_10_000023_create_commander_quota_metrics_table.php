<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('commander_quota_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->nullable()->index();
            $table->string('metric_key', 64);
            // 'limit' es palabra reservada; null = sin limite
            $table->unsignedBigInteger('limit_value')->nullable();
            $table->unsignedBigInteger('current_value')->default(0);
            $table->timestamp('measured_at')->nullable();
            $table->string('on_exceed', 16)->default('none'); // none|warn|suspend
            $table->timestamp('exceeded_at')->nullable();
            $table->timestamps();

            // Una fila por (package, metrica). Las filas se crean al provisionar el package
            // (CommanderProvisioningController), no aqui: en una BD compartida no hay un
            // unico deploy cuyas cuotas sembrar al migrar.
            $table->unique(['package_id', 'metric_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commander_quota_metrics');
    }
};
