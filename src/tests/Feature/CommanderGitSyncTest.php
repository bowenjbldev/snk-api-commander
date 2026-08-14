<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Packages\Commander\Contracts\CommanderPackageGitSource;
use Packages\Commander\Git\CommanderLocalGitSource;
use Packages\Commander\Models\CommanderApplicationActivityDailyModel;
use Packages\Commander\Models\CommanderApplicationCommitModel;
use Packages\Commander\Models\CommanderApplicationModel;
use Packages\Commander\Models\CommanderApplicationRepoModel;
use Packages\Commander\Services\CommanderGitSyncService;
use Packages\Commander\Support\CommanderGitCommitData;
use Tests\CommanderTestCase;

/**
 * Pipeline de git insights: el servicio toma commits del package que implementa cada aplicación
 * y los baja a raw + rollup diario. Se testea con un source FAKE (sin git/red); el parseo real de
 * `git log --numstat` se cubre aparte sobre CommanderLocalGitSource::parseLog (puro).
 */
class CommanderGitSyncTest extends CommanderTestCase
{
    use RefreshDatabase;

    private function application(string $name = 'Shop'): CommanderApplicationModel
    {
        return CommanderApplicationModel::create([
            'package_name' => $name,
            'name' => $name,
            'slug' => strtolower($name),
            'status' => 'active',
            'code_status' => 'available',
        ]);
    }

    public function test_sync_uses_shared_code_when_the_client_has_no_overlay(): void
    {
        $application = $this->application('Kilkana');
        $this->app->instance(CommanderPackageGitSource::class, $this->fakeSource([]));

        $this->app->make(CommanderGitSyncService::class)->sync();

        $this->assertSame(
            'src/packages/Kilkana',
            CommanderApplicationRepoModel::where('application_id', $application->id)->value('path_prefix'),
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
        $application = $this->application();

        $fake = $this->fakeSource([
            new CommanderGitCommitData('bbb', CarbonImmutable::parse('2026-06-20T15:00:00Z'), 'Jorge', 'j@x', 'fix', 5, 1, 1),
            new CommanderGitCommitData('aaa', CarbonImmutable::parse('2026-06-20T10:00:00Z'), 'Jorge', 'j@x', 'feat', 10, 2, 3),
            new CommanderGitCommitData('ccc', CarbonImmutable::parse('2026-06-19T09:00:00Z'), 'Jorge', 'j@x', 'init', 100, 0, 9),
        ]);
        $this->app->instance(CommanderPackageGitSource::class, $fake);

        $summary = $this->app->make(CommanderGitSyncService::class)->sync();

        $this->assertSame([['application' => 'shop', 'commits' => 3, 'days' => 2]], $summary);

        // Sin overlay desplegado, los insights apuntan al producto compartido.
        $repo = CommanderApplicationRepoModel::where('application_id', $application->id)->firstOrFail();
        $this->assertSame('src/packages/Shop', $repo->path_prefix);
        $this->assertSame('bbb', $repo->last_synced_sha); // el más reciente por fecha (15:00 del 20)

        $this->assertSame(3, CommanderApplicationCommitModel::where('application_id', $application->id)->count());

        // Rollup diario: el 20 agrega bbb+aaa, el 19 solo ccc.
        $d20 = CommanderApplicationActivityDailyModel::where('application_id', $application->id)->where('day', '2026-06-20')->firstOrFail();
        $this->assertSame(2, $d20->commit_count);
        $this->assertSame(15, $d20->additions);
        $this->assertSame(3, $d20->deletions);
        $this->assertSame(4, $d20->files_changed);

        $d19 = CommanderApplicationActivityDailyModel::where('application_id', $application->id)->where('day', '2026-06-19')->firstOrFail();
        $this->assertSame(1, $d19->commit_count);
        $this->assertSame(100, $d19->additions);

        // Segunda pasada: el cursor acota → nada nuevo, idempotente.
        $second = $this->app->make(CommanderGitSyncService::class)->sync();
        $this->assertSame(0, $second[0]['commits']);
        $this->assertSame(3, CommanderApplicationCommitModel::where('application_id', $application->id)->count());
        $this->assertSame(2, CommanderApplicationActivityDailyModel::where('application_id', $application->id)->count());
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
