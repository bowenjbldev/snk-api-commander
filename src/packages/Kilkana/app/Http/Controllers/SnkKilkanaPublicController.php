<?php

namespace Packages\Kilkana\Http\Controllers;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Packages\Kilkana\Http\Resources\SnkKilkanaCategoryResource;
use Packages\Kilkana\Http\Resources\SnkKilkanaPostResource;
use Packages\Kilkana\Models\SnkKilkanaNewsletterEventModel;
use Packages\Kilkana\Models\SnkKilkanaNewsletterIssueModel;
use Packages\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Kilkana\Models\SnkKilkanaSubscriberModel;
use Packages\Kilkana\Services\SnkKilkanaNewsletterService;
use Packages\Kilkana\Services\SnkKilkanaPublicService;

/**
 * Lectura pública del periódico (sin sesión): solo contenido `published`, resuelto por slug
 * (URL SEO). Un slug antiguo responde `{redirect_to}` para que el consumidor emita el 301. El
 * interruptor por paquete (`CoreEnsureActiveMiddleware`) sigue aplicando.
 */
#[Group('Public')]
class SnkKilkanaPublicController extends Controller
{
    public function __construct(private readonly SnkKilkanaPublicService $street) {}

    public function posts(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category' => 'nullable|string|max:32',
            'author' => 'nullable|string|max:255',
            'tag' => 'nullable|string|max:64',
            'q' => 'nullable|string|min:2|max:128',
            // `per_page`, como el resto de la API, y no `count`: dos ideas separadas por una
            // letra de `counts`, que es «solo las cifras». Un nombre para cada cosa.
            'per_page' => 'nullable|integer|min:1|max:'.SnkKilkanaPublicService::PER_PAGE_MAX,
            'page' => 'nullable|integer|min:1|max:1000',
        ]);

        // Los campos y las relaciones los lee el paquete de la query, dentro del techo que
        // declara el servicio. Aquí solo viajan los filtros de la calle, que sí se validan:
        // `q` con menos de dos letras recorre la tabla entera.
        return response()->json(SnkKilkanaPostResource::collection($this->street->posts($data)));
    }

    /** Una etiqueta por su slug: lo que `/tag/{slug}` necesita para rotularse. */
    public function tag(string $slug): JsonResponse
    {
        $tag = $this->street->tag($slug);

        return $tag === null
            ? response()->json(['error' => 'Not found'], 404)
            : response()->json($tag);
    }

    /** Todas las direcciones indexables del periódico, para el sitemap de la calle. */
    public function sitemap(): JsonResponse
    {
        return response()->json($this->street->sitemap());
    }

    public function categories(): JsonResponse
    {
        return response()->json(SnkKilkanaCategoryResource::collection($this->street->categories()));
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

        return $this->street->recordView($year, $month, $day, $slug, $data['event'], $request)
            ? response()->json(['ok' => true], 202)
            : response()->json(['error' => 'Not found'], 404);
    }

    /** Una sección, o la redirección si la URL pedida es una que dejó atrás. */
    public function category(string $slug): JsonResponse
    {
        $category = $this->street->category($slug);
        if (is_string($category)) {
            return response()->json(['redirect_to' => $category]);
        }

        return $category
            ? response()->json(new SnkKilkanaCategoryResource($category))
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
        $author = $this->street->author($slug);
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
        $post = $this->street->post($year, $month, $day, $slug);
        if (is_string($post)) {
            return response()->json(['redirect_to' => $post]);
        }

        return $post
            ? response()->json(new SnkKilkanaPostResource($post))
            : response()->json(['error' => 'Not found'], 404);
    }

    /** La previa por enlace: la pieza entera si la firma vale y no ha caducado; si no, como si no existiera. */
    public function preview(Request $request, int $post): JsonResponse
    {
        $post = $this->street->preview($post, (int) $request->query('until', '0'), (string) $request->query('sig', ''));

        return $post
            ? response()->json(new SnkKilkanaPostResource($post))
            : response()->json(['error' => 'Not found'], 404);
    }

    /** Comentarios aprobados de un artículo publicado (planos; parent_id arma el hilo). */
    public function comments(string $year, string $month, string $day, string $slug): JsonResponse
    {
        $comments = $this->street->comments($year, $month, $day, $slug);

        return $comments === null
            ? response()->json(['error' => 'Not found'], 404)
            : response()->json($comments);
    }

    public function layout(string $layout): JsonResponse
    {
        return response()->json($this->street->layout($layout));
    }

    /** Alta pública al boletín: idempotente por email; el estado nace pending y sale el correo. */
    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => 'required|email|max:255']);
        $this->street->subscribe($data['email'], $request);

        // Respuesta uniforme exista o no: no revela si un email está suscrito.
        return response()->json(['ok' => true], 202);
    }

    /**
     * Confirma la suscripción desde el enlace del correo.
     *
     * `GET` que cambia estado, a sabiendas: se pulsa desde un buzón, donde no hay formulario
     * que mande un POST. La contrapartida es que un escáner corporativo de enlaces puede
     * confirmar solo; la alternativa exige una interacción adicional que no forma parte del
     * contrato. La firma es lo que impide confirmar suscripciones ajenas recorriendo ids.
     */
    public function confirmSubscription(SnkKilkanaSubscriberModel $subscriber, Request $request): Response|RedirectResponse
    {
        $this->street->confirm($subscriber, $request);

        return $this->subscriptionLanding('confirmed', 'Subscription confirmed. Thanks for reading.');
    }

    /** Baja desde el enlace del correo. Idempotente: pulsar dos veces aterriza igual. */
    public function unsubscribe(SnkKilkanaSubscriberModel $subscriber, Request $request): Response|RedirectResponse
    {
        $this->street->unsubscribe($subscriber);
        // Si el enlace venía dentro de un número, ese número se apunta la baja: es la cifra
        // que decide si el siguiente sale con el mismo tono.
        if ($request->query('issue') !== null && SnkKilkanaNewsletterIssueModel::query()->whereKey((int) $request->query('issue'))->exists()) {
            SnkKilkanaNewsletterEventModel::record((int) $request->query('issue'), (int) $subscriber->getKey(), SnkKilkanaNewsletterEventModel::UNSUB);
        }

        return $this->subscriptionLanding('unsubscribed', 'You have been unsubscribed. You will not receive the newsletter again.');
    }

    /** El píxel: un gif de un píxel, y la apertura apuntada una vez por suscriptor. */
    public function newsletterOpen(int $issue, int $subscriber): Response
    {
        if (SnkKilkanaNewsletterIssueModel::query()->whereKey($issue)->exists()) {
            SnkKilkanaNewsletterEventModel::record($issue, $subscriber, SnkKilkanaNewsletterEventModel::OPEN);
        }

        return response(base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'), 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    /** Un enlace del boletín: apunta el clic y lleva a la pieza. Una pieza que ya no está lleva a la portada. */
    public function newsletterClick(int $issue, int $subscriber, int $post): RedirectResponse
    {
        if (SnkKilkanaNewsletterIssueModel::query()->whereKey($issue)->exists()) {
            SnkKilkanaNewsletterEventModel::record($issue, $subscriber, SnkKilkanaNewsletterEventModel::CLICK, $post);
        }
        $post = SnkKilkanaPostModel::query()->published()->find($post);
        $newsletter = app(SnkKilkanaNewsletterService::class);

        return redirect()->away($post ? $newsletter->articleUrl($post) : $newsletter->siteUrl().'/');
    }

    /**
     * Devuelve al lector al periódico. Si no hay URL configurada, texto plano.
     *
     * Quien pulsa está en un navegador, así que un JSON sería una respuesta a la cara; y una
     * redirección a la nada es peor que una frase que confirma lo que acaba de pasar.
     */
    private function subscriptionLanding(string $outcome, string $message): Response|RedirectResponse
    {
        $target = $this->street->subscriptionLanding($outcome);

        return $target
            ? redirect()->away($target)
            : response($message)->header('Content-Type', 'text/plain; charset=utf-8');
    }

    /** Identidad + tema, lo mínimo para renderizar la salida pública. */
    public function site(): JsonResponse
    {
        return response()->json($this->street->site());
    }
}
