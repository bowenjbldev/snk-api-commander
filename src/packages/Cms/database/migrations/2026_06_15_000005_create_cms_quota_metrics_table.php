<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cms_quota_metrics', function (Blueprint $table) {
            $table->string('id', 96)->primary(); // "${deployment_id}:${metric_key}"
            $table->string('deployment_id', 64);
            $table->string('metric_key', 32); // requests_day|disk_db_mb|disk_storage_mb|users_total
            $table->unsignedBigInteger('current_value')->default(0);
            $table->unsignedBigInteger('limit_value')->nullable(); // null = unlimited
            $table->string('on_exceed', 16)->default('none'); // none|warn|suspend
            $table->timestamp('exceeded_at')->nullable();
            $table->string('unit', 16)->default('count'); // count|mb
            $table->timestamps();

            $table->index('deployment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_quota_metrics');
    }
};
