<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('commander_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number', 64)->unique(); // INV-2026-0140 (handle de negocio legible)
            $table->foreignId('client_id')->constrained('commander_clients')->restrictOnDelete();
            $table->foreignId('package_id')->constrained('commander_packages')->cascadeOnDelete();
            $table->decimal('amount', 10, 2)->default(0);
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->string('status', 16)->default('pending'); // paid|pending|overdue
            $table->string('enforcement', 16)->nullable();      // grace|suspended|null
            $table->timestamps();

            $table->index('status');
            $table->index('due_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commander_invoices');
    }
};
