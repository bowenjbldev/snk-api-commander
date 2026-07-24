<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('kilkana_media', 'original_name')) Schema::table('kilkana_media', function (Blueprint $table) {
            $table->string('original_name', 500)->nullable()->after('url_hash');
            $table->string('title', 500)->nullable()->after('original_name');
            $table->text('description')->nullable()->after('caption');
            $table->string('credit', 500)->nullable()->after('description');
            $table->string('source_url', 2048)->nullable()->after('credit');
            $table->string('rights_status', 32)->default('unknown')->after('source_url');
            $table->string('license', 500)->nullable()->after('rights_status');
            $table->date('rights_expires_at')->nullable()->after('license');
            $table->decimal('focal_x', 5, 4)->default(0.5)->after('rights_expires_at');
            $table->decimal('focal_y', 5, 4)->default(0.5)->after('focal_x');
            $table->unsignedInteger('duration')->nullable()->after('height');
            $table->char('content_hash', 64)->nullable()->unique()->after('duration');
            $table->softDeletes();
            $table->index(['rights_status', 'rights_expires_at']);
        });

        if (!Schema::hasTable('kilkana_media_tags')) Schema::create('kilkana_media_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->timestamps();
        });
        if (!Schema::hasTable('kilkana_media_collections')) Schema::create('kilkana_media_collections', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->timestamps();
        });
        if (!Schema::hasTable('kilkana_media_media_tag')) Schema::create('kilkana_media_media_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_id')->constrained('kilkana_media')->cascadeOnDelete();
            $table->foreignId('media_tag_id')->constrained('kilkana_media_tags')->cascadeOnDelete();
            $table->unique(['media_id', 'media_tag_id'], 'media_tag_unique');
        });
        if (!Schema::hasTable('kilkana_media_collection_media')) Schema::create('kilkana_media_collection_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_id')->constrained('kilkana_media')->cascadeOnDelete();
            $table->foreignId('media_collection_id')->constrained('kilkana_media_collections')->cascadeOnDelete();
            $table->unique(['media_id', 'media_collection_id'], 'media_collection_unique');
        });
        if (!collect(Schema::getIndexes('kilkana_media_collection_media'))->contains('name', 'media_collection_unique')) {
            Schema::table('kilkana_media_collection_media', fn (Blueprint $table) => $table->unique(['media_id', 'media_collection_id'], 'media_collection_unique'));
        }

        DB::table('kilkana_media')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $name = basename(parse_url($row->url, PHP_URL_PATH) ?: $row->url);
                DB::table('kilkana_media')->where('id', $row->id)->update([
                    'original_name' => $name,
                    'title' => pathinfo($name, PATHINFO_FILENAME),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kilkana_media_collection_media');
        Schema::dropIfExists('kilkana_media_media_tag');
        Schema::dropIfExists('kilkana_media_collections');
        Schema::dropIfExists('kilkana_media_tags');
        Schema::table('kilkana_media', function (Blueprint $table) {
            $table->dropIndex(['rights_status', 'rights_expires_at']);
            $table->dropUnique(['content_hash']);
            $table->dropColumn([
                'original_name', 'title', 'description', 'credit', 'source_url', 'rights_status',
                'license', 'rights_expires_at', 'focal_x', 'focal_y', 'duration', 'content_hash', 'deleted_at',
            ]);
        });
    }
};
