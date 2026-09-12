<?php

namespace Packages\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Packages\Core\Support\CoreActiveInstanceSupport;
use Packages\Core\Support\CoreMeteringBufferSupport;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Registra cada request en commander_request_logs DESPUÉS de atenderla (terminable). En un
 * worker persistente el INSERT correría antes de cerrar la respuesta, así que las filas pasan
 * por CoreMeteringBufferSupport y se escriben en lote.
 * Va el primero del grupo 'api' para registrar también los 429 del throttle
 * y los 402 del kill-switch. Un fallo aquí JAMÁS rompe el request.
 */
class CoreMeteringMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // Fallback de inicio si el SAPI no informa REQUEST_TIME_FLOAT.
        $request->attributes->set('core_metering_start', microtime(true));

        // En un worker persistente el pico de memoria es el histórico del proceso, no el de la
        // petición: sin esto memory_kb valdría lo mismo para ocho peticiones seguidas.
        memory_reset_peak_usage();

        // La instancia activa ya la fijó el resolutor (que va antes). Se captura aquí,
        // no en terminate, para conservar la identidad resuelta del request.
        $request->attributes->set('core_metering_instance_id', app(CoreActiveInstanceSupport::class)->id());

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            if (! config('core.metering.enabled', true)) {
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

            $instanceId = $request->attributes->get('core_metering_instance_id');

            // Sin instancia resuelta (control plane / apex) no es tráfico tenant: no se mide.
            if ($instanceId === null) {
                return;
            }

            // REQUEST_TIME_FLOAT es por petición en todo SAPI, incluido un worker persistente,
            // donde una constante de arranque sería el uptime del proceso.
            $start = (float) $request->server(
                'REQUEST_TIME_FLOAT',
                $request->attributes->get('core_metering_start', microtime(true)),
            );

            $route = $request->route()?->uri();

            $now = now('UTC');

            // Solo quien la petición ya autenticó dentro del inquilino: aquí la conexión ya volvió
            // a commander, y resolver el guard en este punto buscaría el token en la base de control.
            $user = auth()->hasUser() ? auth()->user() : null;

            app(CoreMeteringBufferSupport::class)->add([
                'instance_id' => $instanceId,
                // Insert por query builder: Eloquent no estampa, se setean a mano.
                'created_at' => $now,
                'updated_at' => $now,
                'method' => $request->method(),
                // La columna route es string(191): se trunca para que una URI larga no lance «Data too long».
                'route' => $route !== null ? Str::limit($route, 191, '') : null,
                'path' => Str::limit($path, 255, ''),
                'status' => $response->getStatusCode(),
                'duration_ms' => max(0, (int) round((microtime(true) - $start) * 1000)),
                'user_id' => $user?->getKey(),
                'token_id' => $user?->currentAccessToken()?->id,
                'ip' => $request->ip(),
                'request_bytes' => (int) $request->header('Content-Length', '0') ?: strlen($request->getContent()),
                'response_bytes' => $this->responseBytes($response),
                // Uso real del heap y no páginas reservadas: en un worker persistente las páginas
                // ya están cogidas del arranque y no distinguen una petición ligera de una pesada.
                'memory_kb' => (int) (memory_get_peak_usage() / 1024),
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
