<?php

namespace Packages\Commander\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Packages\Commander\Models\CommanderInvoiceModel;
use Packages\Commander\Models\CommanderSubscriptionItemModel;
use Packages\Commander\Models\CommanderSubscriptionModel;
use Packages\Core\Http\Controllers\CoreBaseApiController;

#[Group('Invoices')]
class CommanderInvoicesController extends CoreBaseApiController
{
    protected string $modelClass = CommanderInvoiceModel::class;

    protected string $indexPermission = 'index invoices';

    protected string $showPermission = 'show invoices';

    protected string $storePermission = 'create invoices';

    protected string $updatePermission = 'update invoices';

    protected string $destroyPermission = 'delete invoices';

    protected array $allowedIncludes = ['account', 'lines', 'lines.subscription', 'lines.subscriptionItem.instance'];

    protected array $validationRulesUpdate = [
        'status' => 'sometimes|required|in:draft,pending,paid,overdue,void',
        'issued_at' => 'nullable|date',
        'due_at' => 'nullable|date',
    ];

    public function store(Request $request): JsonResponse
    {
        if ($response = $this->requirePermission($this->storePermission)) {
            return $response;
        }

        $data = $request->validate([
            'account_id' => 'required|integer|exists:commander_accounts,id',
            'number' => 'required|string|max:64|unique:commander_invoices,number',
            'currency' => 'sometimes|required|string|size:3',
            'status' => 'sometimes|required|in:draft,pending,paid,overdue,void',
            'issued_at' => 'nullable|date',
            'due_at' => 'nullable|date',
            'lines' => 'required|array|min:1',
            'lines.*.subscription_id' => 'required|integer|exists:commander_subscriptions,id',
            'lines.*.subscription_item_id' => 'nullable|integer|exists:commander_subscription_items,id',
            'lines.*.description' => 'required|string|max:255',
            'lines.*.quantity' => 'sometimes|integer|min:1',
            'lines.*.unit_amount' => 'required|numeric|min:0',
        ]);
        $this->guardLines($data['account_id'], $data['lines']);

        $invoice = DB::connection('commander')->transaction(function () use ($data) {
            $lines = collect($data['lines'])->map(function (array $line): array {
                $line['quantity'] ??= 1;
                $line['amount'] = round($line['quantity'] * $line['unit_amount'], 2);

                return $line;
            });
            unset($data['lines']);
            $data['total_amount'] = $lines->sum('amount');
            $invoice = CommanderInvoiceModel::create($data);
            $invoice->lines()->createMany($lines->all());

            return $invoice;
        });

        return response()->json($invoice->load(['account', 'lines']), 201);
    }

    public function destroy($id = null): JsonResponse
    {
        if ($response = $this->requirePermission($this->destroyPermission)) {
            return $response;
        }

        $invoice = CommanderInvoiceModel::query()->find($id);
        if ($invoice === null) {
            return response()->json(['error' => 'Invoice not found'], 404);
        }
        if ($invoice->status !== 'draft') {
            return response()->json(['error' => 'Only draft invoices can be deleted.'], 409);
        }
        $invoice->delete();

        return response()->json(['id' => $invoice->id]);
    }

    /** @param array<int, array<string, mixed>> $lines */
    private function guardLines(int $accountId, array $lines): void
    {
        foreach ($lines as $index => $line) {
            $subscription = CommanderSubscriptionModel::query()->find($line['subscription_id']);
            if ($subscription?->account_id !== $accountId) {
                throw ValidationException::withMessages([
                    "lines.{$index}.subscription_id" => ['Subscription belongs to another account.'],
                ]);
            }
            if (! empty($line['subscription_item_id'])) {
                $belongs = CommanderSubscriptionItemModel::query()
                    ->whereKey($line['subscription_item_id'])
                    ->where('subscription_id', $subscription->id)
                    ->exists();
                if (! $belongs) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.subscription_item_id" => ['Item belongs to another subscription.'],
                    ]);
                }
            }
        }
    }
}
