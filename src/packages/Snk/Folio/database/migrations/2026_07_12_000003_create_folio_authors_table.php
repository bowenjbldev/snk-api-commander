<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Firma editorial; puede existir sin cuenta y vincularse a users después.
        Schema::create('folio_authors', function (Blueprint $table) {
            $table->id();
            // Perfil público E-E-A-T: avatar de la biblioteca (relacional), web y redes.
            $table->foreignId('avatar_media_id')->nullable()->constrained('folio_media')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('bio')->nullable();
            $table->string('website', 2048)->nullable();
            $table->json('socials')->nullable(); // {twitter, linkedin, ...} — pares libres
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folio_authors');
    }
};
