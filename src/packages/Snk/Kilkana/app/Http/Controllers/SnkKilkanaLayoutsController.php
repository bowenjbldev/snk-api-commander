<?php

namespace Packages\Snk\Kilkana\Http\Controllers;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packages\Snk\Kilkana\Models\SnkKilkanaLayoutModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaLayoutRevisionModel;

/**
 * Documentos LayoutSchema del builder (portada/seccion), uno por fila, con concurrencia
 * optimista: el PUT exige la version leida y devuelve 409 con el estado actual si otro
 * guardado gano. La validacion estructural profunda del schema vive en el cliente
 * (normalizeLayoutSchema); aqui solo forma minima.
 */
#[Group('Layouts')]
class SnkKilkanaLayoutsController extends Controller
{
    private const DEFAULT_SCHEMA = ['version' => 1, 'sections' => []];

    public function show(string $layout): JsonResponse
    {
        $row = SnkKilkanaLayoutModel::firstOrCreate(
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

        $row = SnkKilkanaLayoutModel::firstOrCreate(
            ['layout' => $layout],
            ['schema' => self::DEFAULT_SCHEMA, 'version' => 1],
        );

        if ((int) $data['version'] !== (int) $row->version) {
            return response()->json([
                'error' => 'version_conflict',
                'current' => $this->payload($row),
            ], 409);
        }

        // Historial: snapshot del documento ANTES de pisarlo (deshace «Use composition»).
        SnkKilkanaLayoutRevisionModel::create([
            'layout_id' => $row->id,
            'user_id' => auth()->id(),
            'schema' => $row->schema,
        ]);

        $row->schema = $data['schema'];
        $row->version = $row->version + 1;
        $row->save();

        return response()->json($this->payload($row));
    }

    /** Historial del documento (más reciente primero). */
    public function revisions(string $layout): JsonResponse
    {
        $layoutId = SnkKilkanaLayoutModel::query()->where('layout', $layout)->value('id');

        return response()->json(
            SnkKilkanaLayoutRevisionModel::query()
                ->where('layout_id', $layoutId ?? 0)
                ->with('user:id,name')
                ->orderByDesc('created_at')
                ->get()
        );
    }

    /** Restaura una revisión del documento (el estado actual se snapshotea antes). */
    public function restore(string $layout, $revisionId): JsonResponse
    {
        $row = SnkKilkanaLayoutModel::query()->where('layout', $layout)->first();
        $revision = $row
            ? SnkKilkanaLayoutRevisionModel::query()->whereKey($revisionId)->where('layout_id', $row->id)->first()
            : null;
        if (! $row || ! $revision) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        SnkKilkanaLayoutRevisionModel::create([
            'layout_id' => $row->id,
            'user_id' => auth()->id(),
            'schema' => $row->schema,
        ]);

        $row->schema = $revision->schema;
        $row->version = $row->version + 1;
        $row->save();

        return response()->json($this->payload($row));
    }

    private function payload(SnkKilkanaLayoutModel $row): array
    {
        return [
            'layout' => $row->layout,
            'schema' => $row->schema,
            'version' => $row->version,
            'updated_at' => $row->updated_at,
        ];
    }
}
