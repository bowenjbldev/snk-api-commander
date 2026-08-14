<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commander_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('commander_accounts')->restrictOnDelete();
            $table->string('number', 64)->unique();
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->char('currency', 3)->default('EUR');
            $table->string('status', 16)->default('pending');
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commander_invoices');
    }
};
