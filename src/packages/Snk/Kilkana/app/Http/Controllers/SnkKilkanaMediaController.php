<?php

namespace Packages\Snk\Kilkana\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Snk\Kilkana\Models\SnkKilkanaMediaModel;

#[Group('Media')]
class SnkKilkanaMediaController extends CoreBaseApiController
{
    protected string $modelClass = SnkKilkanaMediaModel::class;

    protected string $indexPermission = 'index media';

    protected string $showPermission = 'show media';

    protected string $storePermission = 'create media';

    protected string $updatePermission = 'update media';

    protected string $destroyPermission = 'delete media';

    protected array $validationRulesStore = [
        'url' => 'required|string|max:2048',
        'alt' => 'nullable|string|max:500',
        'caption' => 'nullable|string|max:500',
        'mime' => 'nullable|string|max:64',
        'width' => 'nullable|integer|min:0',
        'height' => 'nullable|integer|min:0',
    ];

    protected array $validationRulesUpdate = [
        'url' => 'sometimes|required|string|max:2048',
        'alt' => 'nullable|string|max:500',
        'caption' => 'nullable|string|max:500',
        'mime' => 'nullable|string|max:64',
        'width' => 'nullable|integer|min:0',
        'height' => 'nullable|integer|min:0',
    ];

    /**
     * Sube una imagen al disco compartido, en carpeta por tenant (la BD del par
     * cliente-paquete). La url guardada es RELATIVA (/storage/…): el mismo host
     * del tenant la sirve vía nginx (public/storage → storage/app/public).
     */
    public function upload(Request $request): JsonResponse
    {
        if ($response = $this->requirePermission($this->storePermission)) {
            return $response;
        }

        $data = $request->validate([
            'file' => 'required|image|mimes:jpeg,png,webp,avif,gif|max:10240', // 10 MB
            'alt' => 'nullable|string|max:500',
            'caption' => 'nullable|string|max:500',
        ]);

        $file = $data['file'];
        // Carpeta por tenant: el disco es compartido entre pares cliente-paquete.
        $dir = 'media/'.DB::connection()->getDatabaseName();
        $path = $file->store($dir, 'public');
        [$width, $height] = @getimagesize($file->getRealPath()) ?: [null, null];

        $media = SnkKilkanaMediaModel::create([
            'url' => '/storage/'.$path,
            'alt' => $data['alt'] ?? null,
            'caption' => $data['caption'] ?? null,
            'mime' => $file->getMimeType(),
            'width' => $width,
            'height' => $height,
            'size_bytes' => $file->getSize(),
            'disk' => 'public',
            'path' => $path,
            'uploaded_by' => auth()->id(),
        ]);

        return response()->json($media, 201);
    }
}
