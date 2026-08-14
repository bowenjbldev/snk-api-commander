<?php

namespace Packages\Commander\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Packages\Commander\Models\CommanderQuotaModel;
use Packages\Core\Http\Controllers\CoreBaseApiController;

/**
 * commander_quotas es la tabla UNICA de cuotas: la edita la API y la mide/
 * aplica el motor (CommanderQuotaMeasurerService) sobre la MISMA fila. Por eso editar un limite o una
 * politica desde aqui llega directamente al enforcement, sin propagacion a una tabla espejo.
 */
#[Group('Quotas')]
class CommanderQuotasController extends CoreBaseApiController
{
    protected string $modelClass = CommanderQuotaModel::class;

    protected string $indexPermission = 'index quotas';

    protected string $showPermission = 'show quotas';

    protected string $storePermission = 'create quotas';

    protected string $updatePermission = 'update quotas';

    protected string $destroyPermission = 'delete quotas';

    protected array $allowedWith = ['instance', 'instance.client', 'instance.application', 'metric', 'measurements'];

    protected array $validationRulesUpdate = [
        'limit_value' => 'nullable|integer|min:0',
        'warning_percent' => 'sometimes|integer|min:1|max:100',
        'action' => 'sometimes|in:none,warn,suspend,recommend',
        'consecutive_periods' => 'sometimes|integer|min:1|max:12',
    ];
}
