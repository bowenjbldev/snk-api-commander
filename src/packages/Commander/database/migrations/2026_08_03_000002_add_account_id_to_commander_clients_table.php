<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commander_clients', function (Blueprint $table) {
            $table->foreignId('account_id')
                ->after('id')
                ->constrained('commander_accounts')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('commander_clients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('account_id');
        });
    }
};
