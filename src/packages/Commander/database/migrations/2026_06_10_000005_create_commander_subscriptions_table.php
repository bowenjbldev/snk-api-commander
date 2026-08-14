<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commander_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('commander_accounts')->restrictOnDelete();
            $table->foreignId('application_id')->constrained('commander_applications')->restrictOnDelete();
            $table->string('name');
            $table->string('pricing_mode', 16)->default('catalog');
            $table->decimal('monthly_amount', 10, 2)->default(0);
            $table->char('currency', 3)->default('EUR');
            $table->string('status', 16)->default('active');
            $table->unsignedInteger('max_instances')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commander_subscriptions');
    }
};
