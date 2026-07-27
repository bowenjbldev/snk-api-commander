<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Packages\Snk\Kilkana\Models\SnkKilkanaTagModel;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kilkana_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', SnkKilkanaTagModel::NAME_MAX)->unique();
            $table->string('slug', SnkKilkanaTagModel::SLUG_MAX)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kilkana_tags');
    }
};
