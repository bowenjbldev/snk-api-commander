<?php

namespace Packages\Kilkana\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Core\Support\CorePackageSelectorSupport;
use Packages\Kilkana\Models\SnkKilkanaNewsletterIssueModel;
use Packages\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Kilkana\Services\SnkKilkanaNewsletterService;

/**
 * Los números del boletín. Leer y escribir es de quien gobierna suscriptores: el boletín es
 * la lista, y quien puede tocar la lista puede escribirle.
 */
#[Group('Newsletter')]
class SnkKilkanaNewsletterController extends CoreBaseApiController
{
    public function __construct(private readonly SnkKilkanaNewsletterService $newsletter) {}

    protected string $modelClass = SnkKilkanaNewsletterIssueModel::class;

    protected string $indexPermission = 'index subscribers';

    protected string $storePermission = 'create subscribers';

    protected string $updatePermission = 'update subscribers';

    public function index(): JsonResponse
    {
        if ($response = $this->requirePermission($this->indexPermission)) {
            return $response;
        }
        if (request()->boolean('counts')) {
            return response()->json(['drafts' => SnkKilkanaNewsletterIssueModel::query()->where('status', SnkKilkanaNewsletterIssueModel::DRAFT)->count()]);
        }

        $issues = SnkKilkanaNewsletterIssueModel::query()->orderByRaw("case when status = 'draft' then 0 else 1 end")->orderByDesc('sent_at')->orderByDesc('id')->get();

        return response()->json([
            'data' => $issues->map(fn (SnkKilkanaNewsletterIssueModel $i) => $this->row($i))->values(),
            'list' => $this->newsletter->listStats(),
            'pieces' => $this->newsletter->pieces(new SnkKilkanaNewsletterIssueModel(['status' => SnkKilkanaNewsletterIssueModel::DRAFT]))
                ->map(fn (SnkKilkanaPostModel $p) => ['id' => (string) $p->getKey(), 'title' => $p->title])->values(),
            'sender' => config('mail.from.address'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($response = $this->requirePermission($this->storePermission)) {
            return $response;
        }

        return response()->json($this->row($this->newsletter->create(auth()->id() ? (int) auth()->id() : null)), 201);
    }

    /** Asunto y preheader, y solo en un borrador: lo que hay en las bandejas no cambia. */
    public function update(Request $request, $id = null): JsonResponse
    {
        if ($response = $this->requirePermission($this->updatePermission)) {
            return $response;
        }
        $issue = SnkKilkanaNewsletterIssueModel::query()->find($id);
        if ($issue === null) {
            return response()->json(['error' => 'Record not found'], 404);
        }
        if ($issue->sent()) {
            return response()->json(['error' => 'A sent issue cannot be edited'], 409);
        }
        $data = $request->validate([
            'subject' => 'sometimes|required|string|max:200',
            'preheader' => 'sometimes|nullable|string|max:300',
        ]);
        $issue->fill($data)->save();

        return response()->json($this->row($issue->fresh()));
    }

    /** Sale ahora o no sale. Una vez, y a los confirmados de este momento. */
    public function send(Request $request, $id = null): JsonResponse
    {
        if ($response = $this->requirePermission($this->updatePermission)) {
            return $response;
        }
        $issue = SnkKilkanaNewsletterIssueModel::query()->find($id);
        if ($issue === null) {
            return response()->json(['error' => 'Record not found'], 404);
        }
        if ($issue->sent()) {
            return response()->json(['error' => 'Already sent'], 409);
        }
        $client = (string) CorePackageSelectorSupport::subdomainFromHost($request->getHost());
        $recipients = $this->newsletter->send($issue, DB::connection()->getDatabaseName(), $client);

        return response()->json(['recipients' => $recipients, 'issue' => $this->row($issue->fresh())]);
    }

    public function test(Request $request, $id = null): JsonResponse
    {
        if ($response = $this->requirePermission($this->updatePermission)) {
            return $response;
        }
        $issue = SnkKilkanaNewsletterIssueModel::query()->find($id);
        if ($issue === null) {
            return response()->json(['error' => 'Record not found'], 404);
        }
        $data = $request->validate(['to' => 'required|email|max:255']);
        try {
            $this->newsletter->sendTest($issue, $data['to'], (string) CorePackageSelectorSupport::subdomainFromHost($request->getHost()));
        } catch (\Throwable $fallo) {
            report($fallo);

            return response()->json(['error' => 'Test could not be sent'], 502);
        }

        return response()->json($this->row($issue->fresh()));
    }

    /** @return array<string, mixed> */
    private function row(SnkKilkanaNewsletterIssueModel $issue): array
    {
        return [
            'id' => (string) $issue->getKey(),
            'subject' => $issue->subject,
            'preheader' => $issue->preheader,
            'status' => $issue->status,
            'sent_at' => $issue->sent_at?->toIso8601String(),
            'recipients' => (int) $issue->recipients_count,
            'test_sent_to' => $issue->test_sent_to,
            'test_sent_at' => $issue->test_sent_at?->toIso8601String(),
            'pieces' => $this->newsletter->pieces($issue)->map(fn (SnkKilkanaPostModel $p) => ['id' => (string) $p->getKey(), 'title' => $p->title])->values()->all(),
            ...$this->newsletter->counts($issue),
        ];
    }
}
