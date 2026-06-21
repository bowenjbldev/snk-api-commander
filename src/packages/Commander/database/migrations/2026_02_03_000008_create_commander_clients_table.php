<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('commander_clients', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique(); // handle legible (antes era la PK)
            $table->string('name');
            $table->string('company')->nullable();
            $table->string('email');
            $table->string('billing_email')->nullable();
            $table->string('tax_id', 64)->nullable();
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city', 128)->nullable();
            $table->string('postal_code', 32)->nullable();
            $table->string('country', 2)->nullable();       // ISO-3166 alpha-2
            $table->string('payment_terms', 32)->nullable(); // prepaid|net_15|net_30|net_60
            $table->string('phone', 50)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 16)->default('active'); // active|inactive
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commander_clients');
    }
};
