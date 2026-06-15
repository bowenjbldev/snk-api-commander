<?php

namespace Packages\Cms\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Cms\Models\InvoiceModel;

#[Group('Invoices')]
class CmsInvoicesController extends CoreBaseApiController
{
    protected string $modelClass = InvoiceModel::class;

    protected string $indexPermission = 'index invoices';
    protected string $showPermission = 'show invoices';
    protected string $storePermission = 'create invoices';
    protected string $updatePermission = 'update invoices';
    protected string $destroyPermission = 'delete invoices';

    protected array $allowedWith = ['client', 'deployment'];

    protected array $validationRulesStore = [
        'id' => 'required|string|max:64|unique:cms_invoices,id',
        'client_id' => 'required|string|exists:cms_clients,id',
        'deployment_id' => 'required|string|exists:cms_deployments,id',
        'amount' => 'required|numeric|min:0',
        'issued_at' => 'nullable|date',
        'due_at' => 'nullable|date',
        'status' => 'nullable|in:paid,pending,overdue',
        'enforcement' => 'nullable|in:grace,suspended',
    ];

    protected array $validationRulesUpdate = [
        'amount' => 'nullable|numeric|min:0',
        'issued_at' => 'nullable|date',
        'due_at' => 'nullable|date',
        'status' => 'nullable|in:paid,pending,overdue',
        'enforcement' => 'nullable|in:grace,suspended',
    ];
}
