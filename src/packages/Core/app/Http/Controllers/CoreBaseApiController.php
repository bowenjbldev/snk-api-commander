<?php

namespace Packages\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\QueryBuilder;

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

    /**
     * Lo que este recurso deja pedir por query string, con los nombres de
     * `spatie/laravel-query-builder`: `?include=`, `?fields=`, `?sort=` y `?filter[x]=`.
     *
     * Se adoptan **sus** nombres y no los que había aquí. Lo que estaba escrito a mano
     * —resolver `?with=`, recortar columnas, leer el `-` del orden— lo hace el paquete, y con
     * su vocabulario: quien conoce spatie conoce esta API sin leer nada, y no hay una segunda
     * convención de la casa que mantener.
     */
    protected array $allowedIncludes = [];

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

    protected array $allowedSorts = [];

    protected array $allowedFilters = [];

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

        return $applyWith
            ? $this->queryBuilder($class::query())->find($id)
            : $class::query()->find($id);
    }

    /**
     * La consulta de este recurso con lo que la query string pida, dentro de lo declarado.
     *
     * Todo lo declarado es lista blanca: lo que no esté no se aplica, y el paquete no lo
     * inventa. `newQuery()` sigue siendo el gancho por el que un recurso acota lo suyo —por
     * propiedad, por permiso— **antes** de que nadie pida nada.
     */
    protected function queryBuilder(?Builder $query = null): QueryBuilder
    {
        // Desempaquetados: las cuatro son variádicas y una lista suelta las revienta con un 500.
        return QueryBuilder::for($query ?? $this->newQuery())
            ->allowedIncludes(...$this->allowedIncludes)
            ->allowedFields(...$this->allowedFields)
            ->allowedSorts(...$this->allowedSorts)
            ->allowedFilters(...$this->allowedFilters);
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

        return response()->json($this->queryBuilder()->get());
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
