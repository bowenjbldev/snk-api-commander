<?php

namespace Packages\Snk\Kilkana\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Packages\Commander\Models\CommanderPackageModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaPostModel;

class SnkKilkanaEnrichMediaCommand extends Command
{
    protected $signature = 'kilkana:enrich-media';

    protected $description = 'Fill media metadata from the editorial posts that use it';

    /**
     * Rellena lo que el artículo SÍ sabe del fichero: título, alt, pie, crédito y derechos.
     *
     * Ya no toca etiquetas ni colecciones. Copiaba los tags del post y el nombre de su
     * sección, y como corre sobre TODOS los tenants aprovisionados, bastaba una ejecución
     * para dejar la fototeca de cada periódico con el vocabulario editorial dentro. Un tag
     * de medio describe el fichero —«retrato», «apaisado», «archivo»—, no el tema de la
     * noticia; eso lo pone quien mira la foto, y ningún comando puede adivinarlo.
     */

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
