<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Control-plane table + seed: tenant deploys only (Commander sets core.control_plane=false).
        if (! config('core.control_plane', true)) {
            return;
        }

        Schema::create('core_quota_metrics', function (Blueprint $table) {
            $table->id();
            $table->string('metric_key', 64)->unique();
            // 'limit' es palabra reservada; null = sin limite
            $table->unsignedBigInteger('limit_value')->nullable();
            $table->unsignedBigInteger('current_value')->default(0);
            $table->timestamp('measured_at')->nullable();
            $table->string('on_exceed', 16)->default('none'); // none|warn|suspend
            $table->timestamp('exceeded_at')->nullable();
            $table->timestamps();
        });

        $defaults = config('core.quotas.defaults', []);

        foreach (['disk_db_mb', 'disk_storage_mb', 'requests_day', 'users_total'] as $key) {
            DB::table('core_quota_metrics')->insertOrIgnore([
                'metric_key' => $key,
                'limit_value' => isset($defaults[$key]) && $defaults[$key] !== null && $defaults[$key] !== ''
                    ? (int) $defaults[$key]
                    : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('core_quota_metrics');
    }
};
