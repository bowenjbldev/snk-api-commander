<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Overlay de facturacion + cache de KPIs. La IDENTIDAD (slug/name/subdomain/package/base_url) NO
        // vive aqui: se deriva de commander_packages via package_id (accessors del modelo). El estado
        // runtime (active|grace|suspended) tambien vive en commander_packages. In-process (misma app/BD).
        Schema::create('commander_deployments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->foreignId('package_id')->nullable(); // -> commander_packages (identidad + estado autoritativo)
            $table->unsignedBigInteger('subscription_id')->nullable(); // FK -> commander_subscriptions.id

            $table->unsignedBigInteger('requests_24h')->default(0);
            $table->decimal('quota_health', 6, 4)->default(0); // worst-metric ratio (0..>1)
            $table->timestamp('last_seen')->nullable();

            $table->timestamps();

            $table->index('client_id');
            $table->index('subscription_id');
            $table->index('package_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commander_deployments');
    }
};
