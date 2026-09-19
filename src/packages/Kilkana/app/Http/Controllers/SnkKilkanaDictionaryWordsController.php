<?php

namespace Packages\Kilkana\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Kilkana\Http\Resources\SnkKilkanaDictionaryWordResource;
use Packages\Kilkana\Models\SnkKilkanaDictionaryWordModel;

#[Group('Dictionary')]
class SnkKilkanaDictionaryWordsController extends CoreBaseApiController
{
    protected string $modelClass = SnkKilkanaDictionaryWordModel::class;

    protected string $indexPermission = 'index dictionary';

    protected string $showPermission = 'show dictionary';

    protected string $storePermission = 'create dictionary';

    protected string $updatePermission = 'update dictionary';

    protected string $destroyPermission = 'delete dictionary';

    // Una palabra y solo una: letras, con el apóstrofo, el punto volado y el guion que algunas
    // llevan dentro. Es lo que el corrector del panel parte como palabra.
    protected array $validationRulesStore = [
        'word' => ['required', 'string', 'max:'.SnkKilkanaDictionaryWordModel::WORD_MAX, 'regex:/^[\p{L}\p{M}\'’·-]+$/u'],
    ];

    protected ?string $resourceClass = SnkKilkanaDictionaryWordResource::class;

    protected array $allowedFields = ['id', 'word'];

    /**
     * Añadir una palabra que ya está no es un error: es lo que pasa cuando dos redactores
     * tropiezan con el mismo apellido la misma tarde. Devuelve la que hay.
     */
    public function store(Request $request): JsonResponse
    {
        if ($response = $this->requirePermission($this->storePermission)) {
            return $response;
        }

        $validator = validator($request->all(), $this->validationRulesStore);
        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $record = SnkKilkanaDictionaryWordModel::firstOrCreate($validator->validated());

        return response()->json($record, $record->wasRecentlyCreated ? 201 : 200);
    }
}
