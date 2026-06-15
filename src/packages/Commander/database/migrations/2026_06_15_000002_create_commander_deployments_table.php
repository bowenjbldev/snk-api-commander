<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('commander_deployments', function (Blueprint $table) {
            $table->string('id', 64)->primary(); // slug == name
            $table->string('name');
            $table->string('subdomain');
            $table->string('client_id', 64);
            $table->string('package');
            $table->string('version', 32);
            $table->string('base_url');

            // Control secret used to sign /control requests to this deploy (encrypted at rest).
            $table->text('control_secret')->nullable();
            $table->timestamp('control_secret_rotated_at')->nullable();
            $table->string('subscription_id', 64)->nullable();

            $table->unsignedBigInteger('requests_24h')->default(0);
            $table->decimal('quota_health', 6, 4)->default(0); // worst-metric ratio (0..>1)
            $table->timestamp('last_seen')->nullable();

            // Deploy state (Commander-known; later refreshed from GET /control/state).
            $table->string('status', 16)->default('active'); // active|grace|suspended
            $table->string('reason', 100)->nullable();
            $table->text('message')->nullable();
            $table->timestamp('grace_until')->nullable();
            $table->string('changed_by', 50)->nullable();
            $table->timestamp('changed_at')->nullable();

            $table->timestamps();

            $table->index('client_id');
            $table->index('subscription_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commander_deployments');
    }
};
