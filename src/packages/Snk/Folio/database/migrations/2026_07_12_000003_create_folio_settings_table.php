<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folio_settings', function (Blueprint $table) {
            $table->id(); // singleton: fila 1
            $table->json('settings')->nullable(); // {theme, theme_overrides, tenant:{name,tagline,city}, onboarded}
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folio_settings');
    }
};
