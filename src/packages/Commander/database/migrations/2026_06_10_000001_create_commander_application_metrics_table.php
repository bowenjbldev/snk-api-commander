<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commander_application_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('commander_applications')->cascadeOnDelete();
            $table->string('key', 64);
            $table->string('name');
            $table->string('unit', 16);
            $table->string('kind', 16)->default('gauge');
            $table->string('window', 16)->default('current');
            $table->string('measurer');
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->unique(['application_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commander_application_metrics');
    }
};
