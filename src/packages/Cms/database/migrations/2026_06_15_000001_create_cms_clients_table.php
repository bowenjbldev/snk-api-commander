<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cms_clients', function (Blueprint $table) {
            $table->string('id', 64)->primary(); // slug
            $table->string('name');
            $table->string('company')->nullable();
            $table->string('email');
            $table->string('phone', 50)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 16)->default('active'); // active|inactive
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_clients');
    }
};
