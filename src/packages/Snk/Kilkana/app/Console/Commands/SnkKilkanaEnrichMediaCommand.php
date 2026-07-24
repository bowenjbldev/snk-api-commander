<?php

namespace Packages\Snk\Kilkana\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Packages\Commander\Models\CommanderPackageModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaMediaCollectionModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaMediaTagModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaPostModel;

class SnkKilkanaEnrichMediaCommand extends Command
{
    protected $signature = 'kilkana:enrich-media';

    protected $description = 'Fill media metadata from the editorial posts that use it';

    public function handle(): int
    {
        $instances = CommanderPackageModel::query()->provisioned()->where('slug', 'kilkana')->whereNotNull('database')
            ->get(['database', 'database_username', 'database_password']);
        $default = DB::getDefaultConnection();
        $tenant = config('database.connections.tenant');
        $mediaCount = 0;
        $failures = 0;

        try {
            foreach ($instances as $instance) {
                try {
                    config([
                        'database.connections.tenant.database' => $instance->database,
                        'database.connections.tenant.username' => $instance->database_username,
                        'database.connections.tenant.password' => $instance->database_password,
                    ]);
                    DB::purge('tenant');
                    DB::setDefaultConnection('tenant');

                    foreach (SnkKilkanaPostModel::query()->with(['coverMedia', 'category', 'tags'])->get() as $post) {
                        $media = $post->coverMedia;
                        if (!$media) continue;

                        $changes = [];
                        if (!$media->title || preg_match('/^(photo|unsplash)-[a-z0-9-]+$/i', $media->title)) $changes['title'] = $post->title;
                        if (!$media->alt) $changes['alt'] = $post->title;
                        if (!$media->caption) $changes['caption'] = $post->excerpt;
                        if (!$media->description) $changes['description'] = "Editorial cover for: {$post->title}";
                        if ($media->rights_status === 'unknown' && str_contains((string) $media->source_url, 'unsplash.com')) $changes['rights_status'] = 'licensed';
                        if (!$media->license && str_contains((string) $media->source_url, 'unsplash.com')) $changes['license'] = 'Unsplash License';
                        if ($changes) { $media->update($changes); $mediaCount++; }

                        $tagIds = $post->tags->map(fn ($tag) => SnkKilkanaMediaTagModel::firstOrCreate(['name' => $tag->name])->id);
                        if ($tagIds->isNotEmpty()) $media->tags()->syncWithoutDetaching($tagIds);
                        if ($post->category) $media->collections()->syncWithoutDetaching([
                            SnkKilkanaMediaCollectionModel::firstOrCreate(['name' => $post->category->name])->id,
                        ]);
                    }
                } catch (\Throwable $e) {
                    $failures++;
                    report($e);
                    $this->error("[{$instance->database}] {$e->getMessage()}");
                }
            }
        } finally {
            DB::purge('tenant');
            config(['database.connections.tenant' => $tenant]);
            DB::setDefaultConnection($default);
        }

        $this->info("Done: {$mediaCount} media item(s) enriched across {$instances->count()} tenant(s); {$failures} failed.");
        return $failures ? self::FAILURE : self::SUCCESS;
    }
}
