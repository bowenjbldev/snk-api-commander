<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lista del boletín del tenant. Alta pública (pending), verificación y
        // baja llegarán con el canal de email; la lista ya es del periódico.
        Schema::create('folio_subscribers', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('status', 16)->default('pending'); // pending|confirmed|unsubscribed
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folio_subscribers');
    }
};
