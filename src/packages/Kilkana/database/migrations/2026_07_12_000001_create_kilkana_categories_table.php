<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Packages\Kilkana\Enums\SnkKilkanaCategoryStatusEnum;
use Packages\Kilkana\Models\SnkKilkanaCategoryModel;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kilkana_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('kilkana_categories')->restrictOnDelete();
            $table->string('name');
            $table->string('slug', SnkKilkanaCategoryModel::SLUG_MAX)->unique();
            $table->string('seo_title', 500)->nullable();
            $table->text('seo_description')->nullable();
            $table->enum('status', SnkKilkanaCategoryStatusEnum::values())->default(SnkKilkanaCategoryStatusEnum::Draft->value);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('noindex')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kilkana_categories');
    }
};
