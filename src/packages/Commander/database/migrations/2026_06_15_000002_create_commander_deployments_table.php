<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Overlay de identidad/facturacion. El estado runtime (active|grace|suspended) NO vive aqui:
        // vive en core_packages, referenciado por package_id. In-process (misma app/BD), sin HMAC.
        Schema::create('commander_deployments', function (Blueprint $table) {
            $table->string('id', 64)->primary(); // slug == name == subdomain
            $table->string('name');
            $table->string('subdomain');
            $table->string('client_id', 64);
            $table->foreignId('package_id')->nullable(); // -> core_packages (estado autoritativo)
            $table->string('package');                   // nombre StudlyCase (denormalizado para el SPA)
            $table->string('version', 32);
            $table->string('base_url');
            $table->string('subscription_id', 64)->nullable();

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
