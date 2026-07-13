<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('commander_packages', function (Blueprint $table) {
            $table->string('database_username', 64)->nullable()->after('database');
            $table->text('database_password')->nullable()->after('database_username');
        });
    }

    public function down(): void
    {
        Schema::table('commander_packages', function (Blueprint $table) {
            $table->dropColumn(['database_username', 'database_password']);
        });
    }
};
