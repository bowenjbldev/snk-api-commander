<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection('commander')->getDriverName() === 'sqlite') {
            return;
        }

        DB::connection('commander')->statement(
            'ALTER TABLE commander_quotas
             ADD CONSTRAINT commander_quotas_scope
             CHECK ((instance_id IS NULL) <> (subscription_id IS NULL))'
        );
    }

    public function down(): void
    {
        if (DB::connection('commander')->getDriverName() === 'sqlite') {
            return;
        }

        DB::connection('commander')->statement(
            'ALTER TABLE commander_quotas DROP CONSTRAINT IF EXISTS commander_quotas_scope'
        );
    }
};
