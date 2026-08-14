<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commander_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('commander_applications')->restrictOnDelete();
            $table->string('slug', 64);
            $table->string('name');
            $table->string('status', 16)->default('active');
            $table->text('description')->nullable();
            $table->decimal('price_monthly', 10, 2)->default(0);
            $table->timestamps();

            $table->unique(['application_id', 'slug']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commander_plans');
    }
};
