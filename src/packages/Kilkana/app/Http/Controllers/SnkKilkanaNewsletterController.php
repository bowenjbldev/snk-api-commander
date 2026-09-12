<?php

namespace Packages\Kilkana\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Core\Support\CorePackageSelectorSupport;
use Packages\Kilkana\Http\Resources\SnkKilkanaNewsletterIssueResource;
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

        $issues = SnkKilkanaNewsletterIssueModel::query()->orderByRaw("case when status = 'draft' then 0 else 1 end")->orderByDesc('sent_at')->orderByDesc('id')->get()->values();

        // Las piezas y las cifras de TODOS los números de una vez. Pedirlas número a número
        // serían seis consultas por fila —una por las cifras y cinco por las piezas, que se
        // traerían las cuatro relaciones del modelo—, y el listado no tiene cota de números.
        // El borrador en blanco va en la misma tanda porque pregunta lo mismo que un borrador
        // sin piezas elegidas: qué llevaría un número nuevo.
        $posts = $this->newsletter->postsForIssues($issues->collect()->push(new SnkKilkanaNewsletterIssueModel(['status' => SnkKilkanaNewsletterIssueModel::DRAFT])));
        $counts = $this->newsletter->countsFor($issues->modelKeys());

        return response()->json([
            'data' => SnkKilkanaNewsletterIssueResource::collection($issues->each(
                function (SnkKilkanaNewsletterIssueModel $i, int $p) use ($posts, $counts) {
                    $i->setAttribute('pieces', $posts[$p]);
                    $i->setAttribute('stats', $counts[(int) $i->getKey()]);
                },
            )),
            'list' => $this->newsletter->listStats(),
            // Lo que se puede elegir para un número: las veinte publicadas más recientes.
            'candidates' => $this->titles($this->newsletter->recent(20)),
            'pieces' => $this->titles($posts->last()),
            'sender' => config('mail.from.address'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($response = $this->requirePermission($this->storePermission)) {
            return $response;
        }

        return response()->json(new SnkKilkanaNewsletterIssueResource($this->withCounts($this->newsletter->create(auth()->id() ? (int) auth()->id() : null))), 201);
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
            // Lo que va dentro, elegido: vacío vuelve a «las últimas publicadas».
            'post_ids' => 'sometimes|nullable|array|max:12',
            'post_ids.*' => 'integer|exists:kilkana_posts,id',
            // La hora de salida; nula es «cuando alguien pulse». Siempre por delante del reloj.
            'scheduled_at' => 'sometimes|nullable|date|after:now',
        ]);
        $issue->fill($data)->save();

        return response()->json(new SnkKilkanaNewsletterIssueResource($this->withCounts($issue->fresh())));
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

        return response()->json(['recipients' => $recipients, 'issue' => new SnkKilkanaNewsletterIssueResource($this->withCounts($issue->fresh()))]);
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
        } catch (\Throwable $error) {
            report($error);

            return response()->json(['error' => 'Test could not be sent'], 502);
        }

        return response()->json(new SnkKilkanaNewsletterIssueResource($this->withCounts($issue->fresh())));
    }

    /**
     * Las piezas y las cifras de UN número, para las cuatro puertas que devuelven uno solo.
     *
     * El listado no pasa por aquí: las pide en una tanda para todos, que es lo que evita seis
     * consultas por fila. Aquí son dos, y es una fila.
     */
    private function withCounts(SnkKilkanaNewsletterIssueModel $issue): SnkKilkanaNewsletterIssueModel
    {
        $issue->setAttribute('pieces', $this->newsletter->postsOf($issue));
        $issue->setAttribute('stats', $this->newsletter->counts($issue));

        return $issue;
    }

    /**
     * Lo único que el panel necesita de una pieza en esta pantalla.
     *
     * @param  Collection<int, SnkKilkanaPostModel>  $posts
     * @return list<array{id: string, title: string}>
     */
    private function titles(Collection $posts): array
    {
        return $posts->map(fn (SnkKilkanaPostModel $p) => ['id' => (string) $p->getKey(), 'title' => $p->title])->values()->all();
    }
}
