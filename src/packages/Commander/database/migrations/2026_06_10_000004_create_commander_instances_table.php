<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commander_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('commander_clients')->restrictOnDelete();
            $table->foreignId('application_id')->constrained('commander_applications')->restrictOnDelete();
            $table->string('database', 64)->nullable();
            $table->string('database_username', 64)->nullable();
            $table->text('database_password')->nullable();
            $table->string('provisioning_status', 20)->default('pending');
            $table->string('service_status', 20)->default('active');
            $table->string('reason', 100)->nullable();
            $table->text('message')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('grace_until')->nullable();
            $table->timestamp('changed_at')->nullable();
            $table->unsignedBigInteger('requests_24h')->default(0);
            $table->decimal('quota_health', 6, 4)->default(0);
            $table->timestamp('last_seen')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('provisioned_at')->nullable();
            $table->timestamp('deprovisioned_at')->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'application_id']);
            $table->index(['provisioning_status', 'service_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commander_instances');
    }
};
