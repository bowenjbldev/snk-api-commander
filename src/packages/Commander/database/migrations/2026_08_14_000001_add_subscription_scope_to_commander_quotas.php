<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commander_quotas', function (Blueprint $table) {
            $table->unsignedBigInteger('instance_id')->nullable()->change();
        });
        Schema::table('commander_quotas', function (Blueprint $table) {
            $table->foreignId('subscription_id')->nullable()->after('instance_id')
                ->constrained('commander_subscriptions')->cascadeOnDelete();
            $table->unique(['subscription_id', 'metric_id']);
        });
        Schema::table('commander_activity_events', function (Blueprint $table) {
            $table->foreignId('subscription_id')->nullable()->after('instance_id')
                ->constrained('commander_subscriptions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('commander_activity_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subscription_id');
        });

        DB::table('commander_quotas')->whereNotNull('subscription_id')->delete();
        Schema::table('commander_quotas', function (Blueprint $table) {
            $table->dropUnique(['subscription_id', 'metric_id']);
            $table->dropConstrainedForeignId('subscription_id');
        });
        Schema::table('commander_quotas', function (Blueprint $table) {
            $table->unsignedBigInteger('instance_id')->nullable(false)->change();
        });
    }
};
