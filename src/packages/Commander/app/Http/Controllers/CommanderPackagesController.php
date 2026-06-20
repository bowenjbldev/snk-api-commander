<?php

namespace Packages\Commander\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packages\Commander\Models\CommanderDeploymentModel;
use Packages\Commander\Models\CommanderPlanModel;
use Packages\Commander\Models\CommanderRegisteredPackageModel;
use Packages\Commander\Services\CommanderPackageRegistryService;
use Packages\Core\Http\Controllers\CoreBaseApiController;

/**
 * Registro de packages de negocio (tipos): hub del panel package-centrico. index() sincroniza
 * desde disco y enriquece cada fila con on_disk + conteos de deployments/planes. Los metadatos
 * (label/description/status) son editables.
 */
#[Group('Packages')]
class CommanderPackagesController extends CoreBaseApiController
{
    protected string $modelClass = CommanderRegisteredPackageModel::class;

    protected string $indexPermission = 'index plans';
    protected string $showPermission = 'show plans';
    protected string $updatePermission = 'update plans';

    protected array $validationRulesUpdate = [
        'label' => 'nullable|string|max:255',
        'description' => 'nullable|string',
        'status' => 'nullable|in:active,inactive',
    ];

    public function __construct(private readonly CommanderPackageRegistryService $registry)
    {
    }

    public function index(): JsonResponse
    {
        if ($response = $this->requirePermission($this->indexPermission)) {
            return $response;
        }

        $this->registry->syncFromDisk();
        $onDisk = $this->registry->availableOnDisk();

        $deploymentCounts = CommanderDeploymentModel::query()
            ->selectRaw('package, COUNT(*) as c')->groupBy('package')->pluck('c', 'package');
        $planCounts = CommanderPlanModel::query()
            ->selectRaw('package, COUNT(*) as c')->groupBy('package')->pluck('c', 'package');

        $rows = CommanderRegisteredPackageModel::query()->orderBy('name')->get()
            ->map(function (CommanderRegisteredPackageModel $p) use ($onDisk, $deploymentCounts, $planCounts) {
                return array_merge($p->toArray(), [
                    'on_disk' => in_array($p->name, $onDisk, true),
                    'deployment_count' => (int) ($deploymentCounts[$p->name] ?? 0),
                    'has_deployment' => (int) ($deploymentCounts[$p->name] ?? 0) > 0,
                    'plan_count' => (int) ($planCounts[$p->name] ?? 0),
                ]);
            });

        return response()->json($rows);
    }

    public function show($package = null): JsonResponse
    {
        $this->registry->syncFromDisk();

        return parent::show($package);
    }

    public function update(Request $request, $package = null): JsonResponse
    {
        return parent::update($request, $package);
    }
}
