<?php

namespace Packages\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Packages\Core\Models\CorePackageStateModel;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autenticacion maquina-a-maquina de los endpoints /control (los llama el
 * Commander). Firma HMAC-SHA256 con secreto compartido por deploy:
 *
 *   payload  = timestamp \n METHOD \n /path \n sha256(body)
 *   firma    = hex(hmac_sha256(payload, CORE_CONTROL_SECRET))
 *   headers  = X-Control-Timestamp, X-Control-Signature
 *
 * Anti-replay: ventana de reloj (core.control.clock_skew_seconds) + firma de un
 * solo uso (cada firma valida se acepta como mucho una vez dentro de la ventana).
 */
class CoreControlAuthMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = $this->activeSecret();

        if ($secret === '') {
            // Las rutas no deberian estar registradas sin secreto; doble cierre.
            return $this->deny('Control endpoints are disabled.');
        }

        $timestamp = $request->header('X-Control-Timestamp');
        $signature = $request->header('X-Control-Signature');

        if (!is_string($timestamp) || !is_string($signature) || $timestamp === '' || $signature === '') {
            return $this->deny('Missing control signature headers.');
        }

        $skew = (int) config('core.control.clock_skew_seconds', 300);

        if (abs(time() - (int) $timestamp) > $skew) {
            return $this->deny('Control timestamp outside the allowed window.');
        }

        $payload = implode("\n", [
            $timestamp,
            $request->method(),
            '/' . ltrim($request->path(), '/'),
            hash('sha256', $request->getContent()),
        ]);

        $expected = hash_hmac('sha256', $payload, $secret);

        if (!hash_equals($expected, $signature)) {
            return $this->deny('Invalid control signature.');
        }

        if (!$this->isFreshSignature($signature, $skew)) {
            return $this->deny('Control signature already used.');
        }

        return $next($request);
    }

    /**
     * The secret to verify against. A rotated secret stored in core_package_state takes precedence
     * over CORE_CONTROL_SECRET, so rotation invalidates the old (env) secret at runtime.
     *
     * Failure handling is deliberately asymmetric:
     *  - No state row yet, or the table/column is missing (pre-migrate) → fall back to the env secret.
     *  - A stored secret that cannot be decrypted (e.g. APP_KEY changed) → fail CLOSED (return ''),
     *    because the env secret is exactly the rotated-away one; falling back to it would silently
     *    re-enable a secret that rotation was meant to kill.
     */
    private function activeSecret(): string
    {
        try {
            $state = CorePackageStateModel::query()->find(1);
        } catch (\Throwable) {
            // table/column missing (pre-migrate boot) → use the env bootstrap secret
            return (string) config('core.control.secret', '');
        }

        if ($state === null) {
            // no rotated secret stored yet → env secret
            return (string) config('core.control.secret', '');
        }

        try {
            $rotated = $state->control_secret; // triggers the 'encrypted' cast
        } catch (\Throwable) {
            // a stored-but-undecryptable secret must NOT fall back to the old env secret
            return '';
        }

        if (is_string($rotated) && $rotated !== '') {
            return $rotated;
        }

        return (string) config('core.control.secret', '');
    }

    /**
     * Single-use enforcement closes the replay hole the time-window check leaves open: a captured
     * (timestamp, signature, body) triple can otherwise be replayed for up to clock_skew seconds.
     * Cache::add is atomic (set-if-absent), so concurrent replays collide too. A cache-layer failure
     * degrades open (HMAC + skew still gate access) rather than locking out the control plane.
     */
    private function isFreshSignature(string $signature, int $skew): bool
    {
        try {
            return Cache::add('core:control:sig:' . hash('sha256', $signature), 1, $skew + 5);
        } catch (\Throwable $e) {
            report($e);

            return true;
        }
    }

    private function deny(string $message): Response
    {
        return response()->json(['error' => $message], 401);
    }
}
