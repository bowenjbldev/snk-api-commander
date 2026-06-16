<?php

namespace Packages\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Registra cada request en core_request_logs DESPUES de enviar la respuesta
 * (terminable: con FPM la respuesta ya salio, cero latencia para el cliente).
 * Va el primero del grupo 'api' para registrar tambien los 429 del throttle
 * y los 402 del kill-switch. Un fallo aqui JAMAS rompe el request.
 */
class CoreMeteringMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // Fallback de inicio si LARAVEL_START no estuviese definido
        $request->attributes->set('core_metering_start', microtime(true));

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            if (!config('core.metering.enabled', true)) {
                return;
            }

            $path = $request->path();

            if (in_array($path, (array) config('core.metering.skip_paths', []), true)) {
                return;
            }

            $rate = (float) config('core.metering.sample_rate', 1.0);

            if ($rate < 1.0 && (mt_rand() / mt_getrandmax()) > $rate) {
                return;
            }

            $start = defined('LARAVEL_START')
                ? LARAVEL_START
                : (float) $request->attributes->get('core_metering_start', microtime(true));

            $route = $request->route()?->uri();

            DB::table('core_request_logs')->insert([
                'occurred_at' => now('UTC'),
                'method' => $request->method(),
                // route column is string(191); truncate so a long URI can't raise "Data too long".
                'route' => $route !== null ? Str::limit($route, 191, '') : null,
                'path' => Str::limit($path, 255, ''),
                'status' => $response->getStatusCode(),
                'duration_ms' => max(0, (int) round((microtime(true) - $start) * 1000)),
                'user_id' => $request->user()?->getKey(),
                'token_id' => $request->user()?->currentAccessToken()?->id,
                'ip' => $request->ip(),
                'request_bytes' => (int) $request->header('Content-Length', '0') ?: strlen($request->getContent()),
                'response_bytes' => $this->responseBytes($response),
                'memory_kb' => (int) (memory_get_peak_usage(true) / 1024),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function responseBytes(Response $response): ?int
    {
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return null;
        }

        $content = $response->getContent();

        return $content === false ? null : strlen($content);
    }
}
