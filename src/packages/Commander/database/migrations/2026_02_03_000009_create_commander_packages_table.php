<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de instancias de package provisionadas. Una fila por subdominio servido
 * (creada por el Commander al dar de alta). Absorbe el antiguo core_package_state:
 * el estado runtime (active|grace|suspended) son columnas de esta fila. El resto de
 * tablas de metering (commander_request_logs/usage_rollups/quota_metrics) referencian commander_packages.id via package_id.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('commander_packages', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();       // StudlyCase, = directorio en packages/
            $table->string('subdomain', 100)->unique();  // snake-case, = host {sub}.{BASE_DOMAIN}
            $table->string('database', 64)->nullable();  // BD del package (multi-tenant); la fija el provisioning
            $table->string('status', 20)->default('active'); // active|grace|suspended
            $table->string('reason', 100)->nullable();
            $table->text('message')->nullable();
            $table->timestamp('grace_until')->nullable();
            $table->string('changed_by', 50)->nullable();
            $table->timestamp('changed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commander_packages');
    }
};
