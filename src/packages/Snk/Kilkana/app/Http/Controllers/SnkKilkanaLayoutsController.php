<?php

namespace Packages\Snk\Kilkana\Http\Controllers;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packages\Snk\Kilkana\Services\SnkKilkanaLayoutService;

/**
 * Documentos LayoutSchema del builder (portada/seccion), uno por fila, con concurrencia
 * optimista: el PUT exige la version leida y devuelve 409 con el estado actual si otro
 * guardado gano. La validacion estructural profunda del schema vive en el cliente
 * (normalizeLayoutSchema); aqui solo forma minima.
 */
#[Group('Layouts')]
class SnkKilkanaLayoutsController extends Controller
{
    public function __construct(private readonly SnkKilkanaLayoutService $layouts) {}

    public function show(string $layout): JsonResponse
    {
        return response()->json($this->layouts->payload($this->layouts->get($layout)));
    }

    public function update(Request $request, string $layout): JsonResponse
    {
        $data = $request->validate([
            'schema' => 'required|array',
            'schema.version' => 'required|integer',
            'schema.sections' => 'present|array',
            'version' => 'required|integer',
        ]);

        $result = $this->layouts->update(
            $layout,
            $data['schema'],
            (int) $data['version'],
            auth()->id(),
        );
        if ($result['conflict']) {
            return response()->json([
                'error' => 'version_conflict',
                'current' => $this->layouts->payload($result['row']),
            ], 409);
        }

        return response()->json($this->layouts->payload($result['row']));
    }

    /** Historial del documento (más reciente primero). */
    public function revisions(string $layout): JsonResponse
    {
        return response()->json($this->layouts->revisions($layout));
    }

    /** Restaura una revisión del documento (el estado actual se snapshotea antes). */
    public function restore(string $layout, $revisionId): JsonResponse
    {
        $row = $this->layouts->restore($layout, $revisionId, auth()->id());
        if ($row === null) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        return response()->json($this->layouts->payload($row));
    }
}
