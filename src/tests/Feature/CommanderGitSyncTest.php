<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Packages\Commander\Contracts\CommanderPackageGitSource;
use Packages\Commander\Git\CommanderLocalGitSource;
use Packages\Commander\Models\CommanderClientModel;
use Packages\Commander\Models\CommanderPackageActivityDailyModel;
use Packages\Commander\Models\CommanderPackageCommitModel;
use Packages\Commander\Models\CommanderPackageModel;
use Packages\Commander\Models\CommanderPackageRepoModel;
use Packages\Commander\Services\CommanderGitSyncService;
use Packages\Commander\Support\CommanderGitCommitData;
use Tests\CommanderTestCase;

/**
 * Pipeline de git insights (commander:git:sync): el servicio toma commits de un CommanderPackageGitSource
 * y los baja a raw + rollup diario. Se testea con un source FAKE (sin git/red); el parseo real de
 * `git log --numstat` se cubre aparte sobre CommanderLocalGitSource::parseLog (puro).
 */
class CommanderGitSyncTest extends CommanderTestCase
{
    use RefreshDatabase;

    private function provisionedPackage(string $name = 'Shop'): CommanderPackageModel
    {
        $client = CommanderClientModel::create([
            'slug' => 'acme', 'name' => 'Acme', 'email' => 'a@a.test', 'status' => 'active',
        ]);

        return CommanderPackageModel::create([
            'name' => $name, 'slug' => strtolower($name), 'database' => 'acme_'.strtolower($name),
            'client_id' => $client->id, 'status' => 'active', 'provisioned_at' => now(),
        ]);
    }

    public function test_sync_uses_shared_code_when_the_client_has_no_overlay(): void
    {
        $package = $this->provisionedPackage('Kilkana');
        $this->app->instance(CommanderPackageGitSource::class, $this->fakeSource([]));

        $this->app->make(CommanderGitSyncService::class)->sync();

        $this->assertSame(
            'src/packages/Kilkana',
            CommanderPackageRepoModel::where('package_id', $package->id)->value('path_prefix'),
        );
    }

    /** Source fake: devuelve los commits solo en la primera pasada (cursor null); luego nada. */
    private function fakeSource(array $commits): CommanderPackageGitSource
    {
        return new class($commits) implements CommanderPackageGitSource
        {
            public array $sinceCalls = [];

            public function __construct(private array $commits) {}

            public function fetchCommits($repo, $sinceSha): iterable
            {
                $this->sinceCalls[] = $sinceSha;

                return $sinceSha === null ? $this->commits : [];
            }
        };
    }

    public function test_sync_stores_commits_daily_rollup_and_cursor(): void
    {
        $package = $this->provisionedPackage();

        $fake = $this->fakeSource([
            new CommanderGitCommitData('bbb', CarbonImmutable::parse('2026-06-20T15:00:00Z'), 'Jorge', 'j@x', 'fix', 5, 1, 1),
            new CommanderGitCommitData('aaa', CarbonImmutable::parse('2026-06-20T10:00:00Z'), 'Jorge', 'j@x', 'feat', 10, 2, 3),
            new CommanderGitCommitData('ccc', CarbonImmutable::parse('2026-06-19T09:00:00Z'), 'Jorge', 'j@x', 'init', 100, 0, 9),
        ]);
        $this->app->instance(CommanderPackageGitSource::class, $fake);

        $summary = $this->app->make(CommanderGitSyncService::class)->sync();

        $this->assertSame([['package' => 'acme/shop', 'commits' => 3, 'days' => 2]], $summary);

        // Sin overlay desplegado, los insights apuntan al producto compartido.
        $repo = CommanderPackageRepoModel::where('package_id', $package->id)->firstOrFail();
        $this->assertSame('src/packages/Shop', $repo->path_prefix);
        $this->assertSame('bbb', $repo->last_synced_sha); // el más reciente por fecha (15:00 del 20)

        $this->assertSame(3, CommanderPackageCommitModel::where('package_id', $package->id)->count());

        // Rollup diario: el 20 agrega bbb+aaa, el 19 solo ccc.
        $d20 = CommanderPackageActivityDailyModel::where('package_id', $package->id)->where('day', '2026-06-20')->firstOrFail();
        $this->assertSame(2, $d20->commit_count);
        $this->assertSame(15, $d20->additions);
        $this->assertSame(3, $d20->deletions);
        $this->assertSame(4, $d20->files_changed);

        $d19 = CommanderPackageActivityDailyModel::where('package_id', $package->id)->where('day', '2026-06-19')->firstOrFail();
        $this->assertSame(1, $d19->commit_count);
        $this->assertSame(100, $d19->additions);

        // Segunda pasada: el cursor acota → nada nuevo, idempotente.
        $second = $this->app->make(CommanderGitSyncService::class)->sync();
        $this->assertSame(0, $second[0]['commits']);
        $this->assertSame(3, CommanderPackageCommitModel::where('package_id', $package->id)->count());
        $this->assertSame(2, CommanderPackageActivityDailyModel::where('package_id', $package->id)->count());
        $this->assertSame([null, 'bbb'], $fake->sinceCalls);
    }

    public function test_local_git_source_parses_numstat_output(): void
    {
        $s = "\x1f";
        $output = implode("\n", [
            "@@C@@{$s}sha1{$s}2026-06-20T15:00:00+00:00{$s}Jorge{$s}j@x{$s}fix bug",
            "5\t1\tsrc/packages/Acme/Shop/app/Foo.php",
            "10\t0\tsrc/packages/Acme/Shop/app/Bar.php",
            '',
            "@@C@@{$s}sha2{$s}2026-06-19T09:00:00+00:00{$s}Jorge{$s}j@x{$s}init",
            "-\t-\tsrc/packages/Acme/Shop/logo.png", // binario → 0/0 pero cuenta como fichero
            "3\t2\tsrc/packages/Acme/Shop/README.md",
        ]);

        $commits = (new CommanderLocalGitSource('/x/.git', '/x', 'git'))->parseLog($output);

        $this->assertCount(2, $commits);
        $this->assertSame('sha1', $commits[0]->sha);
        $this->assertSame(15, $commits[0]->additions);
        $this->assertSame(1, $commits[0]->deletions);
        $this->assertSame(2, $commits[0]->filesChanged);
        $this->assertSame('fix bug', $commits[0]->message);
        $this->assertSame(3, $commits[1]->additions);
        $this->assertSame(2, $commits[1]->deletions);
        $this->assertSame(2, $commits[1]->filesChanged); // binario + README
    }
}
