<?php

namespace Packages\Snk\Kilkana\Http\Controllers;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packages\Snk\Kilkana\Models\SnkKilkanaCategoryModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaCommentModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaLayoutModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaPostSlugModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaPostViewModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaSettingModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaSubscriberModel;

/**
 * Lectura pública del periódico (sin auth): solo contenido `published`, resuelto por
 * slug (URL SEO). Un slug antiguo responde `{redirect_to}` para que el frontend emita
 * el 301. El kill-switch por paquete (CoreEnsureActiveMiddleware) sigue aplicando.
 */
#[Group('Public')]
class SnkKilkanaPublicController extends Controller
{
    public function posts(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cat' => 'nullable|string|max:32',
            'q' => 'nullable|string|min:2|max:128',
            'count' => 'nullable|integer|min:1|max:100',
        ]);

        $query = SnkKilkanaPostModel::query()
            ->where('status', 'published')
            // Vistas reales agregadas (kilkana_post_views): el «más leído» del frontend
            // ordena por ellas; hoy y últimos 7 días.
            ->withSum(['views as views_day' => fn ($q) => $q->where('date', now()->toDateString())], 'views')
            ->withSum(['views as views_week' => fn ($q) => $q->where('date', '>=', now()->subDays(6)->toDateString())], 'views')
            ->orderByDesc('published_at');
        if (! empty($data['cat'])) {
            $query->whereHas('category', fn ($q) => $q->where('slug', $data['cat']));
        }
        // Buscador del sitio: FULLTEXT sobre título y entradilla.
        if (! empty($data['q'])) {
            $query->whereFullText(['title', 'excerpt'], $data['q']);
        }

        return response()->json($query->limit($data['count'] ?? 50)->get());
    }

    public function categories(): JsonResponse
    {
        return response()->json(SnkKilkanaCategoryModel::query()->orderBy('position')->orderBy('id')->get());
    }

    public function post(string $slug): JsonResponse
    {
        $post = SnkKilkanaPostModel::query()
            ->where('slug', $slug)
            ->where('status', 'published')
            ->first();
        if ($post) {
            SnkKilkanaPostViewModel::hit($post->id); // lectura de hoy (más leído real)

            return response()->json($post);
        }

        // Slug antiguo → apunta al slug actual (el frontend emite el 301).
        $history = SnkKilkanaPostSlugModel::query()->where('slug', $slug)->first();
        if ($history) {
            $current = SnkKilkanaPostModel::query()
                ->whereKey($history->post_id)
                ->where('status', 'published')
                ->value('slug');
            if ($current) {
                return response()->json(['redirect_to' => $current]);
            }
        }

        return response()->json(['error' => 'Not found'], 404);
    }

    /** Comentarios aprobados de un artículo publicado (planos; parent_id arma el hilo). */
    public function comments(string $slug): JsonResponse
    {
        $postId = SnkKilkanaPostModel::query()
            ->where('slug', $slug)
            ->where('status', 'published')
            ->value('id');
        if ($postId === null) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $comments = SnkKilkanaCommentModel::query()
            ->where('post_id', $postId)
            ->where('status', 'approved')
            ->with('user:id,name') // solo el nombre: el email del lector no sale al público
            ->orderBy('created_at')
            ->get(['id', 'post_id', 'user_id', 'parent_id', 'body', 'created_at']);

        return response()->json($comments);
    }

    public function layout(string $layout): JsonResponse
    {
        $row = SnkKilkanaLayoutModel::query()->where('layout', $layout)->first();

        return response()->json([
            'layout' => $layout,
            'schema' => $row?->schema ?? ['version' => 1, 'sections' => []],
        ]);
    }

    /** Alta pública al boletín: idempotente por email; el estado nace pending. */
    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => 'required|email|max:255']);
        SnkKilkanaSubscriberModel::firstOrCreate(['email' => strtolower($data['email'])]);

        // Respuesta uniforme exista o no: no revela si un email está suscrito.
        return response()->json(['ok' => true], 202);
    }

    /** Identidad + tema, lo mínimo para renderizar la salida pública. */
    public function site(): JsonResponse
    {
        $settings = SnkKilkanaSettingModel::query()->find(1)?->settings ?? [];

        return response()->json([
            'tenant' => $settings['tenant'] ?? null,
            'theme' => $settings['theme'] ?? null,
            'theme_overrides' => $settings['theme_overrides'] ?? [],
        ]);
    }
}
