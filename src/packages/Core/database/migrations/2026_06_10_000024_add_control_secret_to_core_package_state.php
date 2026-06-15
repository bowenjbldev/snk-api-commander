<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Runtime control-secret override (set by the CMS via POST /control/rotate-secret). When present it
 * takes precedence over CORE_CONTROL_SECRET (.env), so rotating invalidates the old secret without
 * a redeploy. Stored encrypted; never serialized.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('core_package_state', function (Blueprint $table) {
            $table->text('control_secret')->nullable()->after('changed_by');
        });
    }

    public function down(): void
    {
        Schema::table('core_package_state', function (Blueprint $table) {
            $table->dropColumn('control_secret');
        });
    }
};
