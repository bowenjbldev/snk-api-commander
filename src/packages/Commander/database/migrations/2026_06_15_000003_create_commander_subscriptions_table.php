<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('commander_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('commander_clients')->restrictOnDelete();
            $table->foreignId('package_id')->constrained('commander_packages')->cascadeOnDelete();
            $table->string('plan');
            $table->decimal('price_monthly', 10, 2)->default(0);
            $table->string('billing_status', 16)->default('paid'); // paid|pending|overdue
            $table->boolean('auto_suspend')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamps();

            $table->unique('package_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commander_subscriptions');
    }
};
