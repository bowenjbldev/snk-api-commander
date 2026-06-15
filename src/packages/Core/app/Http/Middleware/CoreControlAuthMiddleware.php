<?php

namespace Packages\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Packages\Core\Models\CorePackageStateModel;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autenticacion maquina-a-maquina de los endpoints /control (los llama el
 * CMS). Firma HMAC-SHA256 con secreto compartido por deploy:
 *
 *   payload  = timestamp \n METHOD \n /path \n sha256(body)
 *   firma    = hex(hmac_sha256(payload, CORE_CONTROL_SECRET))
 *   headers  = X-Control-Timestamp, X-Control-Signature
 *
 * Anti-replay por ventana de reloj (core.control.clock_skew_seconds).
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

        return $next($request);
    }

    /**
     * The secret to verify against: a rotated DB override takes precedence over CORE_CONTROL_SECRET
     * (so rotation invalidates the old secret at runtime). Any read/decrypt error fails open to env.
     */
    private function activeSecret(): string
    {
        try {
            $rotated = CorePackageStateModel::query()->find(1)?->control_secret;
            if (is_string($rotated) && $rotated !== '') {
                return $rotated;
            }
        } catch (\Throwable) {
            // table missing / decrypt failure → fall back to the env secret
        }

        return (string) config('core.control.secret', '');
    }

    private function deny(string $message): Response
    {
        return response()->json(['error' => $message], 401);
    }
}
