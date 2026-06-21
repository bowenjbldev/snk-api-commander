<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('commander_activity_events', function (Blueprint $table) {
            $table->id();
            $table->timestamp('at')->nullable();
            $table->string('kind', 16); // suspend|grace|resume|paid|quota
            $table->foreignId('package_id')->nullable()->constrained('commander_packages')->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('commander_invoices')->cascadeOnDelete();
            $table->string('reason', 100)->nullable();
            $table->string('by', 50)->nullable();
            $table->text('message')->nullable();
            $table->timestamps();

            $table->index('at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commander_activity_events');
    }
};
