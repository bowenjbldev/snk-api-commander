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
            $table->unsignedBigInteger('deployment_id')->nullable();
            $table->unsignedBigInteger('invoice_id')->nullable(); // FK -> commander_invoices.id
            $table->string('reason', 100)->nullable();
            $table->string('by', 50)->nullable();
            $table->text('message')->nullable();
            $table->timestamps();

            $table->index('at');
            $table->index('deployment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commander_activity_events');
    }
};
