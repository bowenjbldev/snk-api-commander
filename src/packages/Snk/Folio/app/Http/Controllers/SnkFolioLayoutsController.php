<?php

namespace Packages\Snk\Folio\Http\Controllers;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packages\Snk\Folio\Models\SnkFolioLayoutModel;

/**
 * Documentos LayoutSchema del builder (portada/seccion), uno por fila, con concurrencia
 * optimista: el PUT exige la version leida y devuelve 409 con el estado actual si otro
 * guardado gano. La validacion estructural profunda del schema vive en el cliente
 * (normalizeLayoutSchema); aqui solo forma minima.
 */
#[Group('Layouts')]
class SnkFolioLayoutsController extends Controller
{
    private const DEFAULT_SCHEMA = ['version' => 1, 'sections' => []];

    public function show(string $layout): JsonResponse
    {
        $row = SnkFolioLayoutModel::firstOrCreate(
            ['layout' => $layout],
            ['schema' => self::DEFAULT_SCHEMA, 'version' => 1],
        );

        return response()->json($this->payload($row));
    }

    public function update(Request $request, string $layout): JsonResponse
    {
        $data = $request->validate([
            'schema' => 'required|array',
            'schema.version' => 'required|integer',
            'schema.sections' => 'present|array',
            'version' => 'required|integer',
        ]);

        $row = SnkFolioLayoutModel::firstOrCreate(
            ['layout' => $layout],
            ['schema' => self::DEFAULT_SCHEMA, 'version' => 1],
        );

        if ((int) $data['version'] !== (int) $row->version) {
            return response()->json([
                'error' => 'version_conflict',
                'current' => $this->payload($row),
            ], 409);
        }

        $row->schema = $data['schema'];
        $row->version = $row->version + 1;
        $row->save();

        return response()->json($this->payload($row));
    }

    private function payload(SnkFolioLayoutModel $row): array
    {
        return [
            'layout' => $row->layout,
            'schema' => $row->schema,
            'version' => $row->version,
            'updated_at' => $row->updated_at,
        ];
    }
}
