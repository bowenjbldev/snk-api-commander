<?php

namespace Packages\Commander\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Commander\Models\CommanderQuotaMetricModel;
use Packages\Commander\Models\CommanderQuotaModel;

#[Group('Quotas')]
class CommanderQuotasController extends CoreBaseApiController
{
    protected string $modelClass = CommanderQuotaModel::class;

    protected string $indexPermission = 'index quotas';
    protected string $showPermission = 'show quotas';
    protected string $storePermission = 'create quotas';
    protected string $updatePermission = 'update quotas';
    protected string $destroyPermission = 'delete quotas';

    protected array $allowedWith = ['deployment'];

    protected array $validationRulesUpdate = [
        'limit_value' => 'nullable|integer|min:0',
        'on_exceed' => 'nullable|in:none,warn,suspend',
    ];

    /**
     * commander_quotas es solo el ESPEJO que pinta el SPA. El motor que mide y suspende
     * (CommanderQuotaMeasurerService) lee/escribe EXCLUSIVAMENTE commander_quota_metrics por
     * package_id. Tras guardar el espejo, propaga limit_value/on_exceed a la metrica real para
     * que la edicion del operador llegue al enforcement; si no, ambas tablas divergen y editar
     * un limite/politica desde el panel no cambia el comportamiento de suspension.
     */
    public function update(Request $request, $id = null): JsonResponse
    {
        $response = parent::update($request, $id);

        if ($response->getStatusCode() !== 200) {
            return $response;
        }

        $quota = CommanderQuotaModel::with('deployment')->find($id);
        $packageId = $quota?->deployment?->package_id;

        if ($quota !== null && $packageId !== null) {
            CommanderQuotaMetricModel::query()
                ->where('package_id', $packageId)
                ->where('metric_key', $quota->metric_key)
                ->update([
                    'limit_value' => $quota->limit_value,
                    'on_exceed' => $quota->on_exceed,
                ]);
        }

        return $response;
    }
}
