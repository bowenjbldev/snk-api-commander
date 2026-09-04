<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** La portada de un vídeo puede ser una foto de la biblioteca en vez de un fotograma suyo. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kilkana_media', function (Blueprint $table) {
            $table->foreignId('poster_media_id')->nullable()->after('poster_at')->constrained('kilkana_media')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('kilkana_media', function (Blueprint $table) {
            $table->dropConstrainedForeignId('poster_media_id');
        });
    }
};
