<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Packages\Kilkana\Models\SnkKilkanaDictionaryWordModel;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kilkana_dictionary_words', function (Blueprint $table) {
            $table->id();
            $word = $table->string('word', SnkKilkanaDictionaryWordModel::WORD_MAX)->unique();
            // Binaria donde la intercalación por defecto no distingue mayúsculas ni tildes: para
            // un corrector, «Roma» y «roma» son dos palabras, y «Almería» no da por buena
            // «Almeria». sqlite ya compara así.
            if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $word->collation('utf8mb4_bin');
            }
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kilkana_dictionary_words');
    }
};
