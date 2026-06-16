<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Control-plane table: only on tenant deploys. The Commander (operator panel) sets
        // core.control_plane=false in CommanderServiceProvider and skips it (no table, no seed).
        if (! config('core.control_plane', true)) {
            return;
        }

        Schema::create('core_package_state', function (Blueprint $table) {
            $table->id();
            $table->string('status', 20)->default('active');
            $table->string('reason', 100)->nullable();
            $table->text('message')->nullable();
            $table->timestamp('grace_until')->nullable();
            $table->string('changed_by', 50)->nullable();
            $table->timestamps();
        });

        // Tabla single-row: la fila 1 es el estado del deploy.
        DB::table('core_package_state')->insertOrIgnore([
            'id' => 1,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('core_package_state');
    }
};
