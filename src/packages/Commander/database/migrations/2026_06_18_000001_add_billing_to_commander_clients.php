<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('commander_clients', function (Blueprint $table) {
            $table->string('billing_email')->nullable()->after('email');
            $table->string('tax_id', 64)->nullable()->after('billing_email');
            $table->string('address_line1')->nullable()->after('tax_id');
            $table->string('address_line2')->nullable()->after('address_line1');
            $table->string('city', 128)->nullable()->after('address_line2');
            $table->string('postal_code', 32)->nullable()->after('city');
            $table->string('country', 2)->nullable()->after('postal_code'); // ISO-3166 alpha-2
            $table->string('payment_terms', 32)->nullable()->after('country'); // prepaid|net_15|net_30|net_60
        });
    }

    public function down(): void
    {
        Schema::table('commander_clients', function (Blueprint $table) {
            $table->dropColumn([
                'billing_email', 'tax_id', 'address_line1', 'address_line2',
                'city', 'postal_code', 'country', 'payment_terms',
            ]);
        });
    }
};
