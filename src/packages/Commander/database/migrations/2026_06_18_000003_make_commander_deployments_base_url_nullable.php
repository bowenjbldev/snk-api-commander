<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A deployment can now exist in 'provisioning' state — created from the Commander as a desired
 * subscription before the host has provisioned the real environment. The real base_url is only
 * known after `subscription.sh` runs, so it becomes nullable here. (status stays a free string;
 * 'provisioning' is just a new allowed value, enforced at the controller/validation layer.)
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('commander_deployments', function (Blueprint $table) {
            $table->string('base_url')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('commander_deployments', function (Blueprint $table) {
            $table->string('base_url')->nullable(false)->change();
        });
    }
};
