<?php

namespace Packages\Cms\Support;

/**
 * Builds the HMAC-SHA256 signature for a deploy's /control request — byte-identical to what
 * Packages\Core\Http\Middleware\CoreControlAuthMiddleware verifies on the receiving deploy:
 *
 *   payload = timestamp \n METHOD \n /path \n sha256(body)
 *   firma   = hex( hmac_sha256(payload, control_secret) )
 *
 * Pure (no I/O) so it is unit-testable and cross-validated against the real middleware.
 */
class CmsControlSigner
{
    /**
     * Canonical signing string. $path is the URL path only (no query string), leading slash;
     * $body is the exact request body bytes ('' for GET).
     */
    public static function canonicalPayload(int $timestamp, string $method, string $path, string $body): string
    {
        return implode("\n", [
            (string) $timestamp,
            strtoupper($method),
            '/' . ltrim($path, '/'),
            hash('sha256', $body),
        ]);
    }

    /** Lowercase-hex HMAC-SHA256 of the canonical payload under the per-deploy secret. */
    public static function sign(string $secret, int $timestamp, string $method, string $path, string $body): string
    {
        return hash_hmac('sha256', self::canonicalPayload($timestamp, $method, $path, $body), $secret);
    }
}
