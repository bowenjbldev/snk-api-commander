<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commander_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('billing_email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('tax_id', 64)->nullable();
            $table->string('payment_terms', 32)->nullable();
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city', 128)->nullable();
            $table->string('postal_code', 32)->nullable();
            $table->string('country', 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commander_accounts');
    }
};
