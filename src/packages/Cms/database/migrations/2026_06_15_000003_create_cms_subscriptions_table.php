<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cms_subscriptions', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('client_id', 64);
            $table->string('deployment_id', 64);
            $table->string('plan');
            $table->decimal('price_monthly', 10, 2)->default(0);
            $table->string('billing_status', 16)->default('paid'); // paid|pending|overdue
            $table->boolean('auto_suspend')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamps();

            $table->index('client_id');
            $table->index('deployment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_subscriptions');
    }
};
