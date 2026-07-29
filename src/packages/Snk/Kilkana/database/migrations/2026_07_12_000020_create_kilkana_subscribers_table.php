<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Packages\Snk\Kilkana\Enums\SnkKilkanaConsentSourceEnum;
use Packages\Snk\Kilkana\Enums\SnkKilkanaSubscriberStatusEnum;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kilkana_subscribers', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->enum('status', SnkKilkanaSubscriberStatusEnum::values())->default(SnkKilkanaSubscriberStatusEnum::Pending->value);
            $table->enum('consent_source', SnkKilkanaConsentSourceEnum::values())->nullable();
            $table->timestamp('consent_at')->nullable();
            $table->string('consent_ip', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kilkana_subscribers');
    }
};
