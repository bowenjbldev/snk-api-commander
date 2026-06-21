<?php

namespace Packages\Commander\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Commander\Models\CommanderInvoiceModel;

#[Group('Invoices')]
class CommanderInvoicesController extends CoreBaseApiController
{
    protected string $modelClass = CommanderInvoiceModel::class;

    protected string $indexPermission = 'index invoices';
    protected string $showPermission = 'show invoices';
    protected string $storePermission = 'create invoices';
    protected string $updatePermission = 'update invoices';
    protected string $destroyPermission = 'delete invoices';

    protected array $allowedWith = ['client', 'package'];

    protected array $validationRulesStore = [
        'number' => 'required|string|max:64|unique:commander_invoices,number', // INV-... (handle de negocio)
        'client_id' => 'required|integer|exists:commander_clients,id',
        'package_id' => 'required|integer|exists:commander_packages,id',
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
