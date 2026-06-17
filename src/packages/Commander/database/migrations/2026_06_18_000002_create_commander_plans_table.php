<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('commander_plans', function (Blueprint $table) {
            $table->string('id', 64)->primary(); // slug
            $table->string('package'); // the src/packages business package this plan provisions (Shop, Blog…)
            $table->string('name'); // plan label (Basic, Pro, Enterprise…)
            $table->text('description')->nullable();
            $table->decimal('price_monthly', 10, 2)->default(0);

            // Default quota limits applied to the deploy when this plan is provisioned (null = unlimited).
            $table->unsignedBigInteger('q_requests_day')->nullable();
            $table->unsignedBigInteger('q_disk_db_mb')->nullable();
            $table->unsignedBigInteger('q_disk_storage_mb')->nullable();
            $table->unsignedBigInteger('q_users_total')->nullable();

            $table->string('status', 16)->default('active'); // active|inactive
            $table->timestamps();

            $table->index('package');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commander_plans');
    }
};
