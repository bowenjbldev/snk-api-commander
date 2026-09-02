<?php

namespace Packages\Kilkana\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Kilkana\Services\SnkKilkanaTrashService;

/**
 * La papelera: lo que se borró y todavía se puede recuperar.
 *
 * Cada clase entra con el permiso de borrarla: quien no puede borrar medios no ve los medios
 * tirados ni puede purgarlos. Vaciar purga lo que quien pulsa puede purgar, y lo dice.
 */
#[Group('Trash')]
class SnkKilkanaTrashController extends CoreBaseApiController
{
    public function __construct(private readonly SnkKilkanaTrashService $trash) {}

    protected string $modelClass = SnkKilkanaPostModel::class;

    public function index(): JsonResponse
    {
        $kinds = $this->kinds();
        if ($kinds === []) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        if (request()->boolean('counts')) {
            return response()->json(['count' => $this->trash->count($kinds)]);
        }

        return response()->json([
            'data' => $this->trash->rows($kinds),
            'retention_days' => SnkKilkanaTrashService::RETENTION_DAYS,
            'kinds' => $kinds,
        ]);
    }

    public function restore(string $kind, $id = null): JsonResponse
    {
        if ($response = $this->allowed($kind)) {
            return $response;
        }

        return $this->trash->restore($kind, (int) $id)
            ? response()->json(['restored' => (string) $id])
            : response()->json(['error' => 'Record not found'], 404);
    }

    public function purge(string $kind, $id = null): JsonResponse
    {
        if ($response = $this->allowed($kind)) {
            return $response;
        }

        return $this->trash->purge($kind, (int) $id)
            ? response()->json(['purged' => (string) $id])
            : response()->json(['error' => 'Record not found'], 404);
    }

    public function empty(): JsonResponse
    {
        $kinds = $this->kinds();
        if ($kinds === []) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return response()->json(['purged' => $this->trash->empty($kinds), 'kinds' => $kinds]);
    }

    /** Las clases que quien pregunta puede borrar, que son las que ve y las que puede purgar. */
    private function kinds(): array
    {
        $user = auth()->user();
        if ($user === null) {
            return [];
        }

        return array_values(array_filter(
            SnkKilkanaTrashService::KINDS,
            fn (string $kind) => $user->can("delete {$kind}".($kind === 'post' ? 's' : '')),
        ));
    }

    private function allowed(string $kind): ?JsonResponse
    {
        if (! in_array($kind, SnkKilkanaTrashService::KINDS, true)) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        return in_array($kind, $this->kinds(), true) ? null : response()->json(['error' => 'Forbidden'], 403);
    }
}
