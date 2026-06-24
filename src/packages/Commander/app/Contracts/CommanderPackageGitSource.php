<?php

namespace Packages\Commander\Contracts;

use Packages\Commander\Models\CommanderPackageRepoModel;
use Packages\Commander\Support\CommanderGitCommitData;

/**
 * Origen del historial git de un package para los insights. Implementaciones intercambiables
 * (CommanderLocalGitSource = `git log` del .git montado; CommanderBitbucketApiSource = API remota,
 * futuro) que escriben siempre las MISMAS tablas via CommanderGitSyncService.
 */
interface CommanderPackageGitSource
{
    /**
     * Commits del package (newest-first), acotados a su path, posteriores a $sinceSha (exclusivo)
     * si se da; historial completo si es null.
     *
     * @return iterable<CommanderGitCommitData>
     */
    public function fetchCommits(CommanderPackageRepoModel $repo, ?string $sinceSha): iterable;
}
