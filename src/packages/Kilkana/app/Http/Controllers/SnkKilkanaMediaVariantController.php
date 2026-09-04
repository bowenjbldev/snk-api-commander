<?php

namespace Packages\Kilkana\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Packages\Kilkana\Models\SnkKilkanaMediaModel;
use Packages\Kilkana\Services\SnkKilkanaImageService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * La derivada que nginx no encontró: se genera, se escribe y se devuelve; la siguiente vez la
 * sirve nginx sin pasar por aquí. De la ruta no se fía nada: la proporción, el ancho y el
 * formato van contra lista, la base tiene que ser la del inquilino que resolvió el `Host`, y la
 * huella tiene que ser la vigente del medio; lo demás es 404 y no una generación.
 */
class SnkKilkanaMediaVariantController extends Controller
{
    public function __construct(private readonly SnkKilkanaImageService $images) {}

    public function show(Request $request, string $base, int $id, string $key, string $ratio, int $width, string $format): BinaryFileResponse
    {
        if ($base !== DB::connection()->getDatabaseName()) {
            abort(404);
        }
        $media = SnkKilkanaMediaModel::withTrashed()->find($id);
        if ($media === null || $media->variants_key !== $key) {
            abort(404);
        }
        $path = $this->images->variant($media, $ratio, $width, $format);
        if ($path === null) {
            abort(404);
        }

        return response()->file(Storage::disk('public')->path($path), [
            'Content-Type' => match ($format) {
                'avif' => 'image/avif', 'webp' => 'image/webp', default => 'image/jpeg'
            },
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
