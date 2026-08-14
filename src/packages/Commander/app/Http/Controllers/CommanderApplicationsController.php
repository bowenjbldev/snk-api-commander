<?php

namespace Packages\Commander\Http\Controllers;

use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Packages\Commander\Models\CommanderApplicationActivityDailyModel;
use Packages\Commander\Models\CommanderApplicationCommitModel;
use Packages\Commander\Models\CommanderApplicationModel;
use Packages\Core\Http\Controllers\CoreBaseApiController;

#[Group('Applications')]
class CommanderApplicationsController extends CoreBaseApiController
{
    protected string $modelClass = CommanderApplicationModel::class;

    protected string $indexPermission = 'index applications';

    protected string $showPermission = 'show applications';

    protected string $storePermission = 'create applications';

    protected string $updatePermission = 'update applications';

    protected string $destroyPermission = 'delete applications';

    protected array $allowedWith = ['metrics', 'plans', 'plans.limits.metric', 'instances'];

    protected array $validationRulesUpdate = [
        'name' => 'sometimes|required|string|max:255',
        'description' => 'nullable|string',
        'status' => 'sometimes|required|in:active,inactive',
    ];

    public function index(): JsonResponse
    {
        if ($response = $this->requirePermission($this->indexPermission)) {
            return $response;
        }

        return response()->json($this->applyWith(
            CommanderApplicationModel::query()->withCount(['instances', 'plans'])
        )->orderBy('name')->get());
    }

    public function commits(Request $request): JsonResponse
    {
        if ($response = $this->requirePermission($this->indexPermission)) {
            return $response;
        }

        $data = $request->validate(['limit' => 'nullable|integer|min:1|max:200']);
        $rows = CommanderApplicationCommitModel::query()
            ->with('application:id,slug,name')
            ->latest('committed_at')
            ->limit($data['limit'] ?? 30)
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function codeFrequency(Request $request): JsonResponse
    {
        if ($response = $this->requirePermission($this->indexPermission)) {
            return $response;
        }

        $data = $request->validate(['days' => 'nullable|integer|min:1|max:730']);
        $rows = CommanderApplicationActivityDailyModel::query()
            ->where('day', '>=', CarbonImmutable::now()->subDays(($data['days'] ?? 90) - 1)->toDateString())
            ->groupBy('day')
            ->orderBy('day')
            ->get([
                'day',
                DB::raw('SUM(commit_count) as commit_count'),
                DB::raw('SUM(additions) as additions'),
                DB::raw('SUM(deletions) as deletions'),
                DB::raw('SUM(files_changed) as files_changed'),
            ]);

        return response()->json(['data' => $rows]);
    }

    public function insights(int $application): JsonResponse
    {
        if ($response = $this->requirePermission($this->showPermission)) {
            return $response;
        }

        $record = CommanderApplicationModel::query()->find($application);
        if ($record === null) {
            return response()->json(['error' => 'Application not found'], 404);
        }

        return response()->json([
            'application' => $record,
            'activity' => $record->activityDaily()->orderBy('day')->get(),
            'commits' => $record->commits()->latest('committed_at')->limit(50)->get(),
        ]);
    }
}
