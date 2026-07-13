<?php

namespace Packages\Snk\Kilkana\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Snk\Kilkana\Models\SnkKilkanaSubscriberModel;

/**
 * Gestión de la lista del boletín (admin). El alta pública NO pasa por aquí:
 * es POST public/subscribe (sin auth, throttled). update = moderar status.
 */
#[Group('Subscribers')]
class SnkKilkanaSubscribersController extends CoreBaseApiController
{
    protected string $modelClass = SnkKilkanaSubscriberModel::class;

    protected string $indexPermission = 'index subscribers';

    protected string $showPermission = 'show subscribers';

    protected string $storePermission = 'create subscribers';

    protected string $updatePermission = 'update subscribers';

    protected string $destroyPermission = 'delete subscribers';

    protected array $validationRulesStore = [
        'email' => 'required|email|max:255|unique:kilkana_subscribers,email',
        'status' => 'nullable|in:pending,confirmed,unsubscribed',
    ];

    protected array $validationRulesUpdate = [
        'status' => 'required|in:pending,confirmed,unsubscribed',
    ];
}
