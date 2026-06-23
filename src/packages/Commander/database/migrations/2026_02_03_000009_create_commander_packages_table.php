<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla unica de packages de negocio. Cada package en disco (packages/*, via git) tiene UNA fila,
 * sincronizada por el registro (label/description/listing_status editables). El provisioning rellena
 * las columnas OPERATIVAS (subdomain, database, client/subscription, estado runtime, KPIs) y marca
 * `provisioned_at`; el de-provision las anula (soft) conservando la fila + el historial de billing.
 * Producto 1:1 (un package == una app viva). Billing, cuotas, snapshots y metering referencian
 * commander_packages.id via package_id.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('commander_packages', function (Blueprint $table) {
            $table->id();
            // StudlyCase del package dentro del cliente (p.ej. Shop). NO único global: dos clientes
            // pueden tener su propio "Shop" (packages/Acme/Shop, packages/Initech/Shop).
            $table->string('name', 100);

            // ── Catalogo (registro): existe siempre, sincronizado desde disco; metadatos del operador ──
            $table->string('label')->nullable();
            $table->text('description')->nullable();
            $table->string('listing_status', 16)->default('active'); // active|inactive (visible en catalogo)

            // ── Operativo (instancia viva): nullable hasta provisionar; el de-provision lo anula ──
            // El CLIENTE (client_id -> clients.slug) ES el subdominio del host; el slug del package va
            // en el path. URL: {client.slug}.{BASE_DOMAIN}/api/v1/{slug}. BD: {client.slug}_{slug}.
            $table->foreignId('client_id')->nullable()->constrained('commander_clients')->restrictOnDelete();
            $table->string('slug', 100)->nullable();    // snake-case del package (segmento de path)
            $table->string('database', 64)->nullable(); // BD del package: snake(client.slug)_snake(slug)
            // Enlace operativo al subscription activo. Sin FK para evitar el ciclo:
            // commander_subscriptions.package_id es la relacion autoritativa y si va constrained.
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->string('status', 20)->default('active'); // estado runtime: active|grace|suspended
            $table->string('reason', 100)->nullable();
            $table->text('message')->nullable();
            $table->timestamp('grace_until')->nullable();
            $table->string('changed_by', 50)->nullable();
            $table->timestamp('changed_at')->nullable();
            $table->unsignedBigInteger('requests_24h')->default(0);
            $table->decimal('quota_health', 6, 4)->default(0); // worst-metric ratio (0..>1)
            $table->timestamp('last_seen')->nullable();
            $table->timestamp('provisioned_at')->nullable(); // marca la instancia viva (null == solo catalogo)
            $table->timestamps();

            // Una instancia viva por (cliente, package): el resolutor llega al cliente por el host
            // (clients.slug) y filtra por slug del package. Un cliente, un "shop".
            $table->unique(['client_id', 'slug']);
            $table->index('subscription_id');
            $table->index('provisioned_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commander_packages');
    }
};
