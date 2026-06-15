<?php

namespace Packages\Commander\Exceptions;

use RuntimeException;

/**
 * A /control call to a deploy failed (unreachable, timeout, bad signature, non-2xx).
 * Controllers map this to HTTP 502 and leave the local mirror untouched — the deploy is
 * the source of truth, so a failed push must not desync the registry.
 */
class CommanderControlException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $remoteStatus = null,
        public readonly ?string $remoteBody = null,
    ) {
        parent::__construct($message);
    }
}
