<?php

namespace Packages\Commander\Support;

use Carbon\CarbonImmutable;

/**
 * Un commit ya acotado al path de un package: líneas +/- y ficheros tocados son SOLO los de ese path.
 * Lo produce un PackageGitSource y lo persiste CommanderGitSyncService en commander_package_commits.
 */
final readonly class CommanderGitCommitData
{
    public function __construct(
        public string $sha,
        public CarbonImmutable $committedAt,
        public ?string $authorName,
        public ?string $authorEmail,
        public string $message,
        public int $additions,
        public int $deletions,
        public int $filesChanged,
    ) {
    }
}
