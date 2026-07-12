<?php

namespace Packages\Snk\Folio\Http\Controllers;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packages\Snk\Folio\Models\SnkFolioCategoryModel;
use Packages\Snk\Folio\Models\SnkFolioCommentModel;
use Packages\Snk\Folio\Models\SnkFolioLayoutModel;
use Packages\Snk\Folio\Models\SnkFolioPostModel;
use Packages\Snk\Folio\Models\SnkFolioPostSlugModel;
use Packages\Snk\Folio\Models\SnkFolioPostViewModel;
use Packages\Snk\Folio\Models\SnkFolioSettingModel;

/**
 * Lectura pública del periódico (sin auth): solo contenido `published`, resuelto por
 * slug (URL SEO). Un slug antiguo responde `{redirect_to}` para que el frontend emita
 * el 301. El kill-switch por paquete (CoreEnsureActiveMiddleware) sigue aplicando.
 */
#[Group('Public')]
class SnkFolioPublicController extends Controller
{
    public function posts(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cat' => 'nullable|string|max:32',
            'count' => 'nullable|integer|min:1|max:100',
        ]);

        $query = SnkFolioPostModel::query()
            ->where('status', 'published')
            ->orderByDesc('published_at');
        if (! empty($data['cat'])) {
            $query->whereHas('category', fn ($q) => $q->where('slug', $data['cat']));
        }

        return response()->json($query->limit($data['count'] ?? 50)->get());
    }

    public function categories(): JsonResponse
    {
        return response()->json(SnkFolioCategoryModel::query()->orderBy('position')->orderBy('id')->get());
    }

    public function post(string $slug): JsonResponse
    {
        $post = SnkFolioPostModel::query()
            ->where('slug', $slug)
            ->where('status', 'published')
            ->first();
        if ($post) {
            SnkFolioPostViewModel::hit($post->id); // lectura de hoy (más leído real)

            return response()->json($post);
        }

        // Slug antiguo → apunta al slug actual (el frontend emite el 301).
        $history = SnkFolioPostSlugModel::query()->where('slug', $slug)->first();
        if ($history) {
            $current = SnkFolioPostModel::query()
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
        $postId = SnkFolioPostModel::query()
            ->where('slug', $slug)
            ->where('status', 'published')
            ->value('id');
        if ($postId === null) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $comments = SnkFolioCommentModel::query()
            ->where('post_id', $postId)
            ->where('status', 'approved')
            ->with('user:id,name') // solo el nombre: el email del lector no sale al público
            ->orderBy('created_at')
            ->get(['id', 'post_id', 'user_id', 'parent_id', 'body', 'created_at']);

        return response()->json($comments);
    }

    public function layout(string $layout): JsonResponse
    {
        $row = SnkFolioLayoutModel::query()->where('layout', $layout)->first();

        return response()->json([
            'layout' => $layout,
            'schema' => $row?->schema ?? ['version' => 1, 'sections' => []],
        ]);
    }

    /** Identidad + tema, lo mínimo para renderizar la salida pública. */
    public function site(): JsonResponse
    {
        $settings = SnkFolioSettingModel::query()->find(1)?->settings ?? [];

        return response()->json([
            'tenant' => $settings['tenant'] ?? null,
            'theme' => $settings['theme'] ?? null,
            'theme_overrides' => $settings['theme_overrides'] ?? [],
        ]);
    }
}
