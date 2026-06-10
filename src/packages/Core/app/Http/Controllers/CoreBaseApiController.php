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

        if (!is_string($raw) || trim($raw) === '') {
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

    protected function requirePermission(string $permission): ?JsonResponse
    {
        $user = auth()->user();

        if ($user === null) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        if (!$user->can($permission)) {
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
