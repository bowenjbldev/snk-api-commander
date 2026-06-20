<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Overlay de identidad/facturacion. El estado runtime (active|grace|suspended) NO vive aqui:
        // vive en commander_packages, referenciado por package_id. In-process (misma app/BD).
        Schema::create('commander_deployments', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique(); // handle bare legible (antes era la PK: shop, blog…)
            $table->string('name');
            $table->string('subdomain'); // FQDN (shop.snake.test), base para base_url
            $table->unsignedBigInteger('client_id');
            $table->foreignId('package_id')->nullable(); // -> commander_packages (estado autoritativo)
            $table->string('package');                   // nombre StudlyCase (denormalizado para el SPA)
            $table->string('version', 32);
            // nullable: un deployment puede existir en estado 'provisioning' (alta deseada desde el
            // Commander) antes de que el host provisione el entorno real y se conozca la base_url.
            $table->string('base_url')->nullable();
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
