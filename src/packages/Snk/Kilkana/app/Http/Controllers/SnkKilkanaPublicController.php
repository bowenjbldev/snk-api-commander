<?php

namespace Packages\Snk\Kilkana\Http\Controllers;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Packages\Snk\Kilkana\Models\SnkKilkanaSubscriberModel;
use Packages\Snk\Kilkana\Services\SnkKilkanaPublicService;

/**
 * Lectura pública del periódico (sin auth): solo contenido `published`, resuelto por
 * slug (URL SEO). Un slug antiguo responde `{redirect_to}` para que el frontend emita
 * el 301. El kill-switch por paquete (CoreEnsureActiveMiddleware) sigue aplicando.
 */
#[Group('Public')]
class SnkKilkanaPublicController extends Controller
{
    public function __construct(private readonly SnkKilkanaPublicService $public) {}

    public function posts(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cat' => 'nullable|string|max:32',
            'author' => 'nullable|string|max:255',
            'q' => 'nullable|string|min:2|max:128',
            'count' => 'nullable|integer|min:1|max:100',
        ]);

        return response()->json($this->public->posts($data));
    }

    public function categories(): JsonResponse
    {
        return response()->json($this->public->categories());
    }

    /**
     * El lector dice que ha abierto o leído una pieza.
     *
     * Lo manda el navegador y no el render del servidor, que es lo que hace el número
     * honesto: la salida pública está cacheada, así que contar en el render cuenta renders
     * —una vez por minuto por muy leída que sea la pieza— y de paso cuenta a los robots, que
     * no ejecutan JavaScript y por eso aquí no aparecen.
     *
     * Responde 202: se ha aceptado el aviso, no se ha creado nada que el cliente pueda pedir.
     */
    public function view(Request $request, string $year, string $month, string $day, string $slug): JsonResponse
    {
        $data = $request->validate(['event' => 'required|in:open,read']);

        return $this->public->recordView($year, $month, $day, $slug, $data['event'], $request)
            ? response()->json(['ok' => true], 202)
            : response()->json(['error' => 'Not found'], 404);
    }

    /** Una sección, o la redirección si la URL pedida es una que dejó atrás. */
    public function category(string $slug): JsonResponse
    {
        $category = $this->public->category($slug);
        if (is_string($category)) {
            return response()->json(['redirect_to' => $category]);
        }

        return $category
            ? response()->json($category)
            : response()->json(['error' => 'Not found'], 404);
    }

    /**
     * Perfil público de una firma, por slug. La ficha ya viaja anidada en cada post, pero
     * la PÁGINA de autor la necesita suelta: existe aunque la firma no tenga publicados.
     *
     * Se sirve recortada a lo que se pinta —nada de `user_id` ni timestamps— y con el
     * avatar ya resuelto a URL: `avatar_media_id` es un id interno que el lector no puede
     * resolver.
     */
    public function author(string $slug): JsonResponse
    {
        $author = $this->public->author($slug);
        // Cadena = la firma cambió de URL y esta es la de ahora, igual que en `post()`.
        if (is_string($author)) {
            return response()->json(['redirect_to' => $author]);
        }
        if ($author === null) {
            return response()->json(['error' => 'Not found'], 404);
        }

        return response()->json($author);
    }

    public function post(string $year, string $month, string $day, string $slug): JsonResponse
    {
        $post = $this->public->post($year, $month, $day, $slug);
        if (is_string($post)) {
            return response()->json(['redirect_to' => $post]);
        }

        return $post
            ? response()->json($post)
            : response()->json(['error' => 'Not found'], 404);
    }

    /** Comentarios aprobados de un artículo publicado (planos; parent_id arma el hilo). */
    public function comments(string $year, string $month, string $day, string $slug): JsonResponse
    {
        $comments = $this->public->comments($year, $month, $day, $slug);

        return $comments === null
            ? response()->json(['error' => 'Not found'], 404)
            : response()->json($comments);
    }

    public function layout(string $layout): JsonResponse
    {
        return response()->json($this->public->layout($layout));
    }

    /** Alta pública al boletín: idempotente por email; el estado nace pending y sale el correo. */
    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => 'required|email|max:255']);
        $this->public->subscribe($data['email'], $request);

        // Respuesta uniforme exista o no: no revela si un email está suscrito.
        return response()->json(['ok' => true], 202);
    }

    /**
     * Confirma la suscripción desde el enlace del correo.
     *
     * `GET` que cambia estado, a sabiendas: se pulsa desde un buzón, donde no hay formulario
     * que mande un POST. La contrapartida es que un escáner corporativo de enlaces puede
     * confirmar solo; la alternativa —página con botón— exige una pantalla que la app todavía
     * no tiene, y hasta entonces esto no funcionaría en absoluto. La firma es lo que impide
     * confirmar suscripciones ajenas recorriendo ids.
     */
    public function confirmSubscription(SnkKilkanaSubscriberModel $subscriber, Request $request): Response|RedirectResponse
    {
        $this->public->confirm($subscriber, $request);

        return $this->subscriptionLanding('confirmed', 'Subscription confirmed. Thanks for reading.');
    }

    /** Baja desde el enlace del correo. Idempotente: pulsar dos veces aterriza igual. */
    public function unsubscribe(SnkKilkanaSubscriberModel $subscriber): Response|RedirectResponse
    {
        $this->public->unsubscribe($subscriber);

        return $this->subscriptionLanding('unsubscribed', 'You have been unsubscribed. You will not receive the newsletter again.');
    }

    /**
     * Devuelve al lector al periódico. Si no hay URL configurada, texto plano.
     *
     * Quien pulsa está en un navegador, así que un JSON sería una respuesta a la cara; y una
     * redirección a la nada es peor que una frase que confirma lo que acaba de pasar.
     */
    private function subscriptionLanding(string $desenlace, string $mensaje): Response|RedirectResponse
    {
        $destino = $this->public->subscriptionLanding($desenlace);

        return $destino
            ? redirect()->away($destino)
            : response($mensaje)->header('Content-Type', 'text/plain; charset=utf-8');
    }

    /** Identidad + tema, lo mínimo para renderizar la salida pública. */
    public function site(): JsonResponse
    {
        return response()->json($this->public->site());
    }
}
