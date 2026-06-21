<?php

namespace Packages\Commander\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Commander\Models\CommanderQuotaModel;

/**
 * commander_quotas es la tabla UNICA de cuotas: la edita el panel (limit_value/on_exceed) y la mide/
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

    protected array $allowedWith = ['package'];

    protected array $validationRulesUpdate = [
        'limit_value' => 'nullable|integer|min:0',
        'on_exceed' => 'nullable|in:none,warn,suspend',
    ];
}
