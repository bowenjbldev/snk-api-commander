<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commander_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('commander_invoices')->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained('commander_subscriptions')->restrictOnDelete();
            $table->foreignId('subscription_item_id')->nullable()->constrained('commander_subscription_items')->restrictOnDelete();
            $table->string('description');
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_amount', 10, 2);
            $table->decimal('amount', 10, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commander_invoice_lines');
    }
};
