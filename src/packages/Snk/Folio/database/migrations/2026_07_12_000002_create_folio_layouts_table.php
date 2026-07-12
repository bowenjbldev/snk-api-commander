<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folio_layouts', function (Blueprint $table) {
            $table->id(); // PK entera (convención folio_*)
            $table->string('layout', 16)->unique(); // 'portada' | 'seccion'
            $table->json('schema');                  // LayoutSchema completo {version, meta?, sections[]}
            $table->unsignedInteger('version')->default(1); // concurrencia optimista; distinto de schema.version
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folio_layouts');
    }
};
