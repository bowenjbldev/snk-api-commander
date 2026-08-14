<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commander_applications', function (Blueprint $table) {
            $table->id();
            $table->string('package_name', 100)->unique();
            $table->string('slug', 100)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 16)->default('active');
            $table->string('code_status', 16)->default('available');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'code_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commander_applications');
    }
};
