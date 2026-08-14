<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commander_subscription_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('commander_subscriptions')->cascadeOnDelete();
            $table->foreignId('instance_id')->unique()->constrained('commander_instances')->restrictOnDelete();
            $table->foreignId('plan_id')->constrained('commander_plans')->restrictOnDelete();
            $table->string('status', 16)->default('active');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commander_subscription_items');
    }
};
