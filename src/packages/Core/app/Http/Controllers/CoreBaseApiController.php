<?php

namespace Packages\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class CoreBaseApiController extends Controller
{
    protected string $modelClass;

    protected string $indexPermission;

    protected string $showPermission;

    protected string $storePermission;

    protected string $updatePermission;

    protected string $destroyPermission;

    protected array $validationRulesStore = [];

    protected array $validationRulesUpdate = [];

    protected array $validationMessages = [];

    protected array $allowedWith = [];

    /** Bloques de agregados que este recurso sabe calcular, para `?summary=`. */
    protected array $allowedSummary = [];

    /**
     * Los campos que este recurso deja pedir con `?fields=`, y el techo de lo que puede salir.
     *
     * **Es una lista de contención antes que de rendimiento.** Quien llama elige DENTRO de
     * ella y nunca fuera: un endpoint público es anónimo, y sin techo elegir campos libremente
     * reparte `created_by`, `deleted_at` o el correo de acceso de la redacción. Vacía significa
     * que este recurso no admite elegir y devuelve lo suyo de siempre.
     */
    protected array $allowedFields = [];

    protected array $withMap = [];

    protected ?string $storeRequestClass = null;

    protected ?string $updateRequestClass = null;

    protected function buildFormRequest(string $class, Request $request): FormRequest
    {
        /** @var FormRequest $formRequest */
        $formRequest = $class::createFrom($request);
        $formRequest->setContainer(app());
        $formRequest->setRedirector(app('redirect'));
        $formRequest->setRouteResolver($request->getRouteResolver());
        $formRequest->setUserResolver($request->getUserResolver());

        return $formRequest;
    }

    protected function validatedFromFormRequest(string $class, Request $request): array
    {
        $formRequest = $this->buildFormRequest($class, $request);
        $formRequest->validateResolved();

        return $formRequest->validated();
    }

    protected function newQuery()
    {
        $class = $this->modelClass;

        return $class::query();
    }

    protected function findModel($id, bool $applyWith = false): ?Model
    {
        $class = $this->modelClass;

        $query = $class::query();

        if ($applyWith) {
            $query = $this->applyWith($query);
        }

        return $query->find($id);
    }

    protected function applyWith($query)
    {
        $with = $this->parseWithParam();

        if ($with !== []) {
            $query->with($with);

            $class = $this->modelClass;
            $withCount = [];
            foreach ($with as $relationPath) {
                $relation = explode('.', $relationPath)[0] ?? '';
                if ($relation !== '' && method_exists($class, $relation)) {
                    $withCount[] = $relation;
                }
            }

            if ($withCount !== []) {
                $query->withCount(array_values(array_unique($withCount)));
            }
        }

        return $query;
    }

    protected function parseWithParam(): array
    {
        $raw = request()->query('with');

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $requested = array_filter(array_map('trim', explode(',', $raw)));

        if ($requested === [] || $this->allowedWith === []) {
            return [];
        }

        $with = [];

        foreach ($requested as $item) {
            if ($item === '') {
                continue;
            }

            if (isset($this->withMap[$item])) {
                $mapped = $this->withMap[$item];
                foreach ((array) $mapped as $value) {
                    if (in_array($value, $this->allowedWith, true)) {
                        $with[] = $value;
                    }
                }

                continue;
            }

            if (in_array($item, $this->allowedWith, true)) {
                $with[] = $item;
            }
        }

        return array_values(array_unique($with));
    }

    /**
     * Qué agregados de los que ofrece este recurso se han pedido con `?summary=`.
     *
     * Los agregados de un listado —las cifras de las pestañas, la banda de la cabecera— cuentan
     * el archivo entero y **no la página**, así que no son parte de una página: son otra
     * pregunta que da la casualidad de que se responde en el mismo viaje. Hacerlos opcionales
     * es lo que permite que una llamada que solo quiere filas no los pague.
     *
     * No es `with`, que carga relaciones de cada fila, ni `include`, que en JSON:API significa
     * justo eso: son cifras SOBRE la colección. Lo que no esté declarado se ignora, como el
     * resto de la query: nunca un 422.
     *
     * @return list<string>
     */
    protected function summary(): array
    {
        $raw = request()->query('summary');

        if (! is_string($raw) || trim($raw) === '' || $this->allowedSummary === []) {
            return [];
        }

        $asked = array_filter(array_map('trim', explode(',', $raw)));

        return array_values(array_intersect($asked, $this->allowedSummary));
    }

    /**
     * Qué campos se han pedido, de los que este recurso ofrece. Vacío es «los de siempre».
     *
     * Que lo elija quien llama y no el servidor cierra un acoplamiento que ya costó caro: la
     * lista de campos del listado y el parser del panel eran gemelas mantenidas a mano, y
     * divergieron —seis campos pasaron a `undefined` sin que fallara nada—. Quien parsea es
     * ahora quien pide.
     *
     * @return list<string>
     */
    protected function fields(): array
    {
        $raw = request()->query('fields');

        if (! is_string($raw) || trim($raw) === '' || $this->allowedFields === []) {
            return [];
        }

        $asked = array_filter(array_map('trim', explode(',', $raw)));

        return array_values(array_intersect($asked, $this->allowedFields));
    }

    protected function wants(string $block): bool
    {
        return in_array($block, $this->summary(), true);
    }

    protected function requirePermission(string $permission): ?JsonResponse
    {
        $user = auth()->user();

        if ($user === null) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        if (! $user->can($permission)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return null;
    }

    protected function interpolateRules(array $rules, $id): array
    {
        if ($id === null) {
            return $rules;
        }

        foreach ($rules as $field => $rule) {
            if (is_string($rule)) {
                $rules[$field] = str_replace('{id}', (string) $id, $rule);

                continue;
            }

            if (is_array($rule) && isset($rule['rules']) && is_string($rule['rules'])) {
                $rules[$field]['rules'] = str_replace('{id}', (string) $id, $rule['rules']);
            }
        }

        return $rules;
    }

    public function index(): JsonResponse
    {
        if ($response = $this->requirePermission($this->indexPermission)) {
            return $response;
        }

        $query = $this->applyWith($this->newQuery());

        return response()->json($query->get());
    }

    public function show($id = null): JsonResponse
    {
        if ($response = $this->requirePermission($this->showPermission)) {
            return $response;
        }

        if ($id === null) {
            return response()->json(['error' => 'Missing id'], 422);
        }

        $record = $this->findModel($id, true);

        if ($record === null) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        return response()->json($record);
    }

    public function store(Request $request): JsonResponse
    {
        if ($response = $this->requirePermission($this->storePermission)) {
            return $response;
        }

        if ($this->storeRequestClass !== null) {
            $data = $this->validatedFromFormRequest($this->storeRequestClass, $request);
        } else {
            $data = $request->all();
            if ($this->validationRulesStore !== []) {
                $validator = validator($data, $this->validationRulesStore, $this->validationMessages);
                if ($validator->fails()) {
                    return response()->json($validator->errors(), 422);
                }
                $data = $validator->validated();
            }
        }

        $class = $this->modelClass;
        $record = $class::create($data);

        return response()->json($record, 201);
    }

    public function update(Request $request, $id = null): JsonResponse
    {
        if ($response = $this->requirePermission($this->updatePermission)) {
            return $response;
        }

        if ($id === null) {
            return response()->json(['error' => 'Missing id'], 422);
        }

        $record = $this->findModel($id);

        if ($record === null) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        if ($this->updateRequestClass !== null) {
            $data = $this->validatedFromFormRequest($this->updateRequestClass, $request);
        } else {
            $data = $request->all();
            if ($this->validationRulesUpdate !== []) {
                $rules = $this->interpolateRules($this->validationRulesUpdate, $id);
                $validator = validator($data, $rules, $this->validationMessages);
                if ($validator->fails()) {
                    return response()->json($validator->errors(), 422);
                }
                $data = $validator->validated();
            }
        }

        $record->fill($data);
        $record->save();

        return response()->json($record);
    }

    public function destroy($id = null): JsonResponse
    {
        if ($response = $this->requirePermission($this->destroyPermission)) {
            return $response;
        }

        if ($id === null) {
            return response()->json(['error' => 'Missing id'], 422);
        }

        $record = $this->findModel($id);

        if ($record === null) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        $record->delete();

        return response()->json(['id' => $id]);
    }
}
