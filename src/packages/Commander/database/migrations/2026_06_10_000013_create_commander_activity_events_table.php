<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commander_activity_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instance_id')->nullable()->constrained('commander_instances')->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('commander_invoices')->cascadeOnDelete();
            $table->string('kind', 32);
            $table->string('reason', 100)->nullable();
            $table->text('message')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commander_activity_events');
    }
};
