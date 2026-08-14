<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commander_clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('commander_accounts')->restrictOnDelete();
            $table->string('slug', 63)->unique();
            $table->string('name');
            $table->string('status', 16)->default('active');
            $table->string('email');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commander_clients');
    }
};
