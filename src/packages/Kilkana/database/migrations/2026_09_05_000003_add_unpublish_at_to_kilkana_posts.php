<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Cuándo una pieza publicada se retira sola: la otra mitad de programar. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kilkana_posts', function (Blueprint $table) {
            $table->timestamp('unpublish_at')->nullable()->after('publish_at');
            $table->index(['status', 'unpublish_at']);
        });
    }

    public function down(): void
    {
        Schema::table('kilkana_posts', function (Blueprint $table) {
            $table->dropIndex(['status', 'unpublish_at']);
            $table->dropColumn('unpublish_at');
        });
    }
};
