<?php

namespace Packages\Commander\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Packages\Commander\Models\CommanderPlanModel;
use Packages\Core\Http\Controllers\CoreBaseApiController;

#[Group('Plans')]
class CommanderPlansController extends CoreBaseApiController
{
    protected string $modelClass = CommanderPlanModel::class;

    protected string $indexPermission = 'index plans';
    protected string $showPermission = 'show plans';
    protected string $storePermission = 'create plans';
    protected string $updatePermission = 'update plans';
    protected string $destroyPermission = 'delete plans';

    protected array $validationRulesStore = [
        'id' => 'required|string|max:64|unique:commander_plans,id',
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

    /**
     * Un plan solo es provisionable si su package existe en disco. Validar aqui evita crear
     * ofertas en el catalogo que luego revientan con 422 al pulsar Provision (mismo criterio
     * is_dir que CommanderProvisioningController::store).
     *
     * @throws ValidationException si el package no esta desplegado.
     */
    private function guardPackageDeployed(Request $request): void
    {
        $package = $request->input('package');

        if (! is_string($package) || $package === '') {
            return; // las reglas base (required) gestionan el caso vacio
        }

        $studly = Str::studly($package);

        if (! is_dir(base_path("packages/{$studly}"))) {
            throw ValidationException::withMessages([
                'package' => ["Package code '{$studly}' is not deployed."],
            ]);
        }
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
