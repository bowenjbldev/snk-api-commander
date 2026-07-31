<?php

namespace Packages\Commander\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Packages\Commander\Models\CommanderPlanModel;
use Packages\Commander\Services\CommanderPackageRegistryService;
use Packages\Core\Http\Controllers\CoreBaseApiController;

#[Group('Plans')]
class CommanderPlansController extends CoreBaseApiController
{
    public function __construct(private readonly CommanderPackageRegistryService $registry) {}

    protected string $modelClass = CommanderPlanModel::class;

    protected string $indexPermission = 'index plans';

    protected string $showPermission = 'show plans';

    protected string $storePermission = 'create plans';

    protected string $updatePermission = 'update plans';

    protected string $destroyPermission = 'delete plans';

    protected array $validationRulesStore = [
        'slug' => 'required|string|max:64|unique:commander_plans,slug',
        'package' => 'required|string|max:255',
        'name' => 'required|string|max:255',
        'description' => 'nullable|string',
        'price_monthly' => 'required|numeric|min:0',
        'q_requests_day' => 'nullable|integer|min:0',
        'q_disk_db_mb' => 'nullable|integer|min:0',
        'q_disk_storage_mb' => 'nullable|integer|min:0',
        'q_users_total' => 'nullable|integer|min:0',
        'status' => 'nullable|in:active,inactive',
    ];

    public function store(Request $request): JsonResponse
    {
        if ($response = $this->requirePermission($this->storePermission)) {
            return $response;
        }

        $this->guardPackageDeployed($request);

        return parent::store($request);
    }

    public function update(Request $request, $id = null): JsonResponse
    {
        if ($response = $this->requirePermission($this->updatePermission)) {
            return $response;
        }

        if ($request->filled('package')) {
            $this->guardPackageDeployed($request);
        }

        return parent::update($request, $id);
    }

    /** @throws ValidationException si el producto no existe compartido ni para ningún cliente. */
    private function guardPackageDeployed(Request $request): void
    {
        $package = $request->input('package');

        if (! is_string($package) || $package === '') {
            return; // las reglas base (required) gestionan el caso vacio
        }

        $package = Str::studly($package);
        if (! in_array($package, $this->registry->availableOnDisk(), true)) {
            throw ValidationException::withMessages([
                'package' => ['Package code is not deployed as shared or client-specific code.'],
            ]);
        }

        $request->merge(['package' => $package]);
    }

    protected array $validationRulesUpdate = [
        'package' => 'nullable|string|max:255',
        'name' => 'nullable|string|max:255',
        'description' => 'nullable|string',
        'price_monthly' => 'nullable|numeric|min:0',
        'q_requests_day' => 'nullable|integer|min:0',
        'q_disk_db_mb' => 'nullable|integer|min:0',
        'q_disk_storage_mb' => 'nullable|integer|min:0',
        'q_users_total' => 'nullable|integer|min:0',
        'status' => 'nullable|in:active,inactive',
    ];
}
