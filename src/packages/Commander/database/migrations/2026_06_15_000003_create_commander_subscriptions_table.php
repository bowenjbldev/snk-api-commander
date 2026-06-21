<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Términos de subscripción por package (1:N = historial de planes). Cada fila = un término: el plan
 * contratado, su precio CONGELADO (snapshot) y su periodo. El término VIGENTE es el de `ended_at IS NULL`
 * (al que apunta `commander_packages.subscription_id`); los cerrados (`ended_at` no nulo) son historial.
 * Cambiar de plan cierra el vigente y abre uno nuevo. `plan_id` es trazabilidad al catálogo; los
 * snapshots `plan`/`price_monthly` mandan en billing/MRR aunque el plan cambie o se borre.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('commander_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('commander_clients')->restrictOnDelete();
            $table->foreignId('package_id')->constrained('commander_packages')->cascadeOnDelete();
            // plan_id es solo trazabilidad (el snapshot plan/price_monthly manda en billing). Sin FK,
            // igual que commander_packages.subscription_id: commander_plans se crea en una migración
            // posterior y nada depende de esta integridad referencial; un plan_id colgado es inocuo.
            $table->unsignedBigInteger('plan_id')->nullable()->index();
            $table->string('plan'); // snapshot del nombre del plan
            $table->decimal('price_monthly', 10, 2)->default(0); // snapshot del precio (congelado)
            $table->string('billing_status', 16)->default('paid'); // paid|pending|overdue
            $table->boolean('auto_suspend')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable(); // null = término vigente; no nulo = histórico
            $table->timestamps();

            // 1:N: varios términos por package. El "vigente único" se garantiza en transacción
            // (provision/change-plan/de-provision) + el puntero commander_packages.subscription_id.
            $table->index(['package_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commander_subscriptions');
    }
};
