<?php

namespace Packages\Kilkana\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Kilkana\Enums\SnkKilkanaConsentSourceEnum;
use Packages\Kilkana\Enums\SnkKilkanaSubscriberStatusEnum;
use Packages\Kilkana\Http\Resources\SnkKilkanaSubscriberResource;
use Packages\Kilkana\Models\SnkKilkanaSubscriberModel;
use Packages\Kilkana\Services\SnkKilkanaActivityService;

/**
 * Gestión de la lista del boletín desde el panel. El alta pública NO pasa por aquí: es
 * `POST public/subscribe`, sin sesión y con límite de ritmo. `update` modera el estado.
 */
#[Group('Subscribers')]
class SnkKilkanaSubscribersController extends CoreBaseApiController
{
    public function __construct()
    {
        $this->validationRulesStore['status'] = 'nullable|'.SnkKilkanaSubscriberStatusEnum::rule();
        $this->validationRulesUpdate['status'] = 'required|'.SnkKilkanaSubscriberStatusEnum::rule();
    }

    protected string $modelClass = SnkKilkanaSubscriberModel::class;

    protected string $indexPermission = 'index subscribers';

    protected string $showPermission = 'show subscribers';

    protected string $storePermission = 'create subscribers';

    protected string $updatePermission = 'update subscribers';

    protected string $destroyPermission = 'delete subscribers';

    protected array $validationRulesStore = [
        'email' => 'required|email|max:255|unique:kilkana_subscribers,email',
    ];

    protected array $validationRulesUpdate = [];

    /** Filas que admite un fichero importado. Por encima se rechaza entero: se parte y se vuelve. */
    public const IMPORT_MAX = 20000;

    /** Las columnas que salen y las que se reconocen al entrar, en este orden cuando no hay cabecera. */
    private const COLUMNS = ['email', 'status', 'consent_at'];

    /**
     * La lista paginada, con la forma que ya usan lectores y comentarios.
     *
     * El `index()` del base devuelve la tabla entera, y este es el único listado del paquete
     * que crece sin techo: los demás que lo heredan son catálogos acotados y este son las
     * direcciones. Con cincuenta mil, la respuesta son megabytes de JSON para pintar una
     * página de veinticinco. Llevarse la lista completa ya tiene su puerta, que es `export`.
     */
    public function index(): JsonResponse
    {
        if ($response = $this->requirePermission($this->indexPermission)) {
            return $response;
        }

        // Por id y no por alta: es orden total, y sin él dos altas del mismo segundo pueden
        // salir dos veces en la página siguiente o no salir en ninguna.
        $page = $this->newQuery()->orderByDesc('id')->paginate($this->perPage());

        return response()->json([
            'data' => SnkKilkanaSubscriberResource::collection($page->getCollection()),
            'meta' => $this->paginationMeta($page),
        ]);
    }

    /**
     * La lista entera como CSV: correo, estado, origen y sello del consentimiento. Es lo que
     * permite llevarse la lista, y también demostrar de dónde salió cada dirección.
     */
    public function export(): Response|JsonResponse
    {
        if ($response = $this->requirePermission($this->indexPermission)) {
            return $response;
        }

        // Una dirección puede empezar por `=`, `+`, `-` o `@` y ser válida, y el fichero se
        // abre en una hoja de cálculo que la leería como fórmula. Un apóstrofo delante la deja
        // como texto, y quien la reimporte la recibe como dirección inválida en vez de ejecutarla.
        $safeCell = fn (string $v) => preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v;
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['email', 'status', 'consent_source', 'consent_at']);
        SnkKilkanaSubscriberModel::query()->orderBy('id')->lazy()->each(function (SnkKilkanaSubscriberModel $s) use ($out, $safeCell) {
            fputcsv($out, [$safeCell($s->email), $s->status->value, $s->consent_source?->value ?? '', $s->consent_at?->toIso8601String() ?? '']);
        });
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return response($csv, 200, ['Content-Type' => 'text/csv; charset=utf-8']);
    }

    /**
     * Una lista traída de fuera.
     *
     * El fichero es un CSV con `email` y, si las trae, `status` y `consent_at`; con cabecera
     * o sin ella, en ese orden. Todo lo importado se marca `import`, que es lo que permite
     * acreditarlo —y purgarlo— aparte de lo que la portada recogió. Quien la sube declara que
     * las direcciones consintieron: sin esa declaración no entra ninguna.
     *
     * Un correo que ya está en la lista se salta, esté como esté: una baja que vuelve en la
     * lista vieja no se vuelve a dar de alta. Una dirección que no lo es se cuenta y sigue.
     */
    public function import(Request $request): JsonResponse
    {
        if ($response = $this->requirePermission($this->storePermission)) {
            return $response;
        }
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:2048',
            'consented' => 'accepted',
        ]);

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $columns = self::COLUMNS;
        $rows = [];
        $invalid = 0;
        while (($row = fgetcsv($handle)) !== false) {
            if ($row === [null] || (count($row) === 1 && trim((string) $row[0]) === '')) {
                continue;
            }
            $row = array_map(fn ($v) => trim((string) $v), $row);
            if ($rows === [] && $invalid === 0 && strtolower($row[0]) === 'email') {
                $columns = array_map('strtolower', $row);

                continue;
            }
            if (count($rows) >= self::IMPORT_MAX) {
                fclose($handle);

                return response()->json(['error' => 'Too many rows', 'max' => self::IMPORT_MAX], 422);
            }
            $cell = fn (string $name) => $row[array_search($name, $columns, true)] ?? '';
            $email = mb_strtolower($cell('email'));
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 255) {
                $invalid++;

                continue;
            }
            $rows[$email] ??= [
                'status' => SnkKilkanaSubscriberStatusEnum::tryFrom($cell('status')) ?? SnkKilkanaSubscriberStatusEnum::Confirmed,
                'consent_at' => rescue(fn () => Carbon::parse($cell('consent_at')), now(), false),
            ];
        }
        fclose($handle);

        $imported = 0;
        $now = now();
        foreach (array_chunk(array_keys($rows), 500) as $emails) {
            $existing = SnkKilkanaSubscriberModel::query()->whereIn('email', $emails)->pluck('email')->all();
            $fresh = array_diff($emails, $existing);
            SnkKilkanaSubscriberModel::query()->insert(array_map(fn (string $email) => [
                'email' => $email,
                'status' => $rows[$email]['status']->value,
                'consent_source' => SnkKilkanaConsentSourceEnum::Import->value,
                'consent_at' => $rows[$email]['consent_at'],
                'created_at' => $now,
                'updated_at' => $now,
            ], $fresh));
            $imported += count($fresh);
        }

        app(SnkKilkanaActivityService::class)->log('newsletter', 'subscribers_imported', '', '/newsletter', ['params' => ['count' => $imported]]);

        return response()->json([
            'imported' => $imported,
            'skipped' => count($rows) - $imported,
            'invalid' => $invalid,
        ]);
    }
}
