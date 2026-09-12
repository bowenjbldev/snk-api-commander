<?php

namespace Packages\Commander\Contracts;

use Packages\Commander\Models\CommanderApplicationRepoModel;
use Packages\Commander\Support\CommanderGitCommitData;

/**
 * Origen del historial git de un package para los insights. El contenedor enlaza
 * CommanderLocalGitSource (`git log` sobre el .git montado), y CommanderGitSyncService escribe lo
 * que devuelva la implementación enlazada en las mismas tablas. El proveedor concreto solo se nombra
 * en la columna `provider`; el resto es agnóstico.
 */
interface CommanderPackageGitSource
{
    /**
     * Commits del package (newest-first), acotados a su path, posteriores a $sinceSha (exclusivo)
     * si se da; historial completo si es null.
     *
     * @return iterable<CommanderGitCommitData>
     */
    public function fetchCommits(CommanderApplicationRepoModel $repo, ?string $sinceSha): iterable;
}
