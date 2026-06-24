<?php

namespace Packages\Commander\Git;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Process;
use Packages\Commander\Contracts\CommanderPackageGitSource;
use Packages\Commander\Models\CommanderPackageRepoModel;
use Packages\Commander\Support\CommanderGitCommitData;

/**
 * Lee el historial git LOCAL (el `.git` montado read-only en el contenedor) acotado al path del
 * package. `git log` solo lee la base de objetos → no necesita work-tree; el pathspec hace el
 * scoping nativo (commits Y líneas, solo de ese path). Sin tokens ni red.
 */
class CommanderLocalGitSource implements CommanderPackageGitSource
{
    /** Centinela de cabecera de commit + separador de campos (US, 0x1f) para un parseo robusto. */
    private const SENTINEL = '@@C@@';

    public function __construct(
        private readonly string $gitDir,
        private readonly string $workTree,
        private readonly string $binary = 'git',
    ) {
    }

    public function fetchCommits(CommanderPackageRepoModel $repo, ?string $sinceSha): iterable
    {
        $path = (string) $repo->path_prefix;

        if ($path === '') {
            return [];
        }

        $output = $this->runLog($path, $sinceSha);

        // Cursor que ya no es ancestro (rebase/force-push): reintenta historial completo.
        if ($output === null && $sinceSha !== null) {
            $output = $this->runLog($path, null);
        }

        return $output === null ? [] : $this->parseLog($output);
    }

    private function runLog(string $path, ?string $sinceSha): ?string
    {
        $args = [
            $this->binary,
            '-c', 'safe.directory='.$this->workTree, // evita "dubious ownership" en volúmenes montados
            '--git-dir='.$this->gitDir,
            '--work-tree='.$this->workTree,
            'log',
        ];

        if ($sinceSha !== null && $sinceSha !== '') {
            $args[] = $sinceSha.'..HEAD'; // incremental: solo lo posterior al cursor
        }

        $args[] = '--numstat';
        $args[] = '--no-renames'; // un rename = del + add → líneas correctas, parseo simple
        $args[] = '--pretty=format:'.self::SENTINEL.'%x1f%H%x1f%aI%x1f%an%x1f%ae%x1f%s';
        $args[] = '--';
        // `:/` ancla el pathspec a la RAÍZ del work-tree (si no, git lo resuelve relativo al cwd del
        // proceso —/var/www/src— y no matchearía `src/packages/...`).
        $args[] = ':/'.$path;

        $result = Process::run($args);

        return $result->successful() ? $result->output() : null;
    }

    /**
     * Parser PURO (testeable sin git): salida de `git log --numstat` → DTOs. Cada commit = una línea
     * centinela seguida de N líneas numstat (`<add>\t<del>\t<path>`; `-` en binarios).
     *
     * @return array<int, CommanderGitCommitData>
     */
    public function parseLog(string $output): array
    {
        $commits = [];
        $current = null;

        foreach (preg_split('/\r?\n/', $output) as $line) {
            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, self::SENTINEL)) {
                if ($current !== null) {
                    $commits[] = $this->build($current);
                }

                $f = explode("\x1f", $line);
                $current = [
                    'sha' => $f[1] ?? '',
                    'committed_at' => $f[2] ?? '',
                    'author_name' => $f[3] ?? '',
                    'author_email' => $f[4] ?? '',
                    'message' => $f[5] ?? '',
                    'additions' => 0,
                    'deletions' => 0,
                    'files' => 0,
                ];

                continue;
            }

            if ($current === null) {
                continue;
            }

            $parts = explode("\t", $line, 3);
            if (count($parts) < 3) {
                continue;
            }

            [$add, $del] = $parts;
            $current['additions'] += $add === '-' ? 0 : (int) $add;
            $current['deletions'] += $del === '-' ? 0 : (int) $del;
            $current['files']++;
        }

        if ($current !== null) {
            $commits[] = $this->build($current);
        }

        return $commits;
    }

    /** @param array<string, mixed> $c */
    private function build(array $c): CommanderGitCommitData
    {
        return new CommanderGitCommitData(
            sha: (string) $c['sha'],
            committedAt: CarbonImmutable::parse((string) $c['committed_at']),
            authorName: $c['author_name'] !== '' ? (string) $c['author_name'] : null,
            authorEmail: $c['author_email'] !== '' ? (string) $c['author_email'] : null,
            message: (string) $c['message'],
            additions: (int) $c['additions'],
            deletions: (int) $c['deletions'],
            filesChanged: (int) $c['files'],
        );
    }
}
