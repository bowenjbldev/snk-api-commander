<?php

namespace Packages\Kilkana\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Packages\Kilkana\Enums\SnkKilkanaConsentSourceEnum;
use Packages\Kilkana\Enums\SnkKilkanaSubscriberStatusEnum;
use Packages\Kilkana\Jobs\SnkKilkanaNewsletterSendJob;
use Packages\Kilkana\Mail\SnkKilkanaNewsletterMail;
use Packages\Kilkana\Models\SnkKilkanaNewsletterEventModel;
use Packages\Kilkana\Models\SnkKilkanaNewsletterIssueModel;
use Packages\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Kilkana\Models\SnkKilkanaSettingModel;
use Packages\Kilkana\Models\SnkKilkanaSubscriberModel;

/**
 * El boletín: componer un número, mandarlo y contar qué pasó con él.
 *
 * Enviar es irreversible, y de ahí sale todo: un número se fija al enviarlo —las piezas que
 * lleva, a cuántos salió— y a partir de ahí solo se lee. El envío va por tandas a la cola, y
 * cada tanda se conecta a su periódico antes de mandar nada.
 */
class SnkKilkanaNewsletterService
{
    /** Cuántos suscriptores por tanda: cada tanda es un trabajo de cola y un fallo suyo no arrastra al resto. */
    public const BATCH = 50;

    /** La ventana de las cifras de salud de la lista. */
    public const WINDOW_DAYS = 90;

    public function __construct(private readonly SnkKilkanaActivityService $registro) {}

    /** Un borrador nuevo, arriba. */
    public function create(?int $userId): SnkKilkanaNewsletterIssueModel
    {
        return SnkKilkanaNewsletterIssueModel::create([
            'subject' => 'Untitled issue',
            'preheader' => null,
            'status' => SnkKilkanaNewsletterIssueModel::DRAFT,
            'created_by' => $userId,
        ]);
    }

    /**
     * Lo que va dentro: las últimas piezas publicadas al enviarlo, o las que quedaron fijadas.
     *
     * @return Collection<int, SnkKilkanaPostModel>
     */
    public function pieces(SnkKilkanaNewsletterIssueModel $issue): Collection
    {
        return $this->piecesFor(collect([$issue]))->first();
    }

    /**
     * Lo mismo para varios números y en su misma posición, en tres consultas como mucho en vez
     * de una por número: el panel los pinta todos a la vez y antes preguntaba uno a uno.
     *
     * @param  Collection<int, SnkKilkanaNewsletterIssueModel>  $issues
     * @return Collection<int, Collection<int, SnkKilkanaPostModel>>
     */
    public function piecesFor(Collection $issues): Collection
    {
        $numeros = $issues->values();
        $elegidas = $numeros->map(fn (SnkKilkanaNewsletterIssueModel $i) => is_array($i->post_ids) ? array_values(array_filter($i->post_ids)) : []);
        $ids = fn (bool $enviado) => $elegidas
            ->filter(fn (array $suyas, int $p) => $numeros[$p]->sent() === $enviado)
            ->flatten()->unique()->values()->all();
        $porId = fn (Builder $consulta, array $suyos) => $suyos === []
            ? collect()
            : $this->fila($consulta)->whereKey($suyos)->get()->keyBy('id');

        // Un número enviado enseña lo que llevó, aunque una pieza se haya tirado después; un
        // borrador con piezas elegidas enseña las que sigan publicadas, en el orden elegido.
        // Son dos consultas distintas, pero dos para todos los números, no dos por número.
        $delArchivo = $porId(SnkKilkanaPostModel::withTrashed(), $ids(true));
        $publicadas = $porId(SnkKilkanaPostModel::query()->published(), $ids(false));
        $ultimas = null;

        return $numeros->map(function (SnkKilkanaNewsletterIssueModel $issue, int $p) use ($elegidas, $delArchivo, $publicadas, &$ultimas) {
            if ($elegidas[$p] === []) {
                return $ultimas ??= $this->recent(SnkKilkanaNewsletterIssueModel::PIECES);
            }
            $pool = $issue->sent() ? $delArchivo : $publicadas;

            return collect($elegidas[$p])->map(fn ($id) => $pool->get($id))->filter()->values();
        });
    }

    /**
     * Las últimas publicadas: lo que lleva un número que no eligió piezas, y lo que se ofrece
     * para elegirlas. Con desempate por id, o dos piezas del mismo minuto se turnan.
     *
     * @return Collection<int, SnkKilkanaPostModel>
     */
    public function recent(int $limit): Collection
    {
        return $this->fila(SnkKilkanaPostModel::query()->published())
            ->orderByDesc('publish_at')->orderByDesc('id')->limit($limit)->get();
    }

    /**
     * De una pieza aquí solo se lee esto: el panel pinta el titular, y el correo el titular, la
     * entradilla y el enlace —que sale de `url_date` y `slug`—. Nadie serializa la pieza entera,
     * así que las cuatro relaciones que `$with` carga sola eran cuatro consultas por llamada
     * para nada.
     */
    private function fila(Builder $consulta): Builder
    {
        return $consulta->select(['id', 'title', 'excerpt', 'url_date', 'slug'])
            ->without(['category', 'author', 'coverMedia', 'tags']);
    }

    /**
     * Manda los números con hora cumplida. Uno por uno y dentro de una transacción con la
     * fila bloqueada: dos ticks del scheduler solapados no pueden mandar el mismo dos veces.
     */
    public function sendDue(string $database, string $client): int
    {
        $enviados = 0;
        $ids = SnkKilkanaNewsletterIssueModel::query()
            ->where('status', SnkKilkanaNewsletterIssueModel::DRAFT)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->pluck('id');

        foreach ($ids as $id) {
            $enviado = DB::transaction(function () use ($id, $database, $client) {
                $issue = SnkKilkanaNewsletterIssueModel::query()->whereKey($id)->lockForUpdate()->first();
                if ($issue === null || $issue->sent()) {
                    return false;
                }
                $this->send($issue, $database, $client);

                return true;
            });
            $enviados += $enviado ? 1 : 0;
        }

        return $enviados;
    }

    /**
     * Manda el número a todos los confirmados: lo fija, lo marca enviado y encola las tandas.
     * Devuelve a cuántos sale. La cola es la de la instalación, no la del periódico: por eso
     * cada tanda lleva la base y el subdominio con los que volver.
     */
    public function send(SnkKilkanaNewsletterIssueModel $issue, string $database, string $client): int
    {
        $ids = SnkKilkanaSubscriberModel::query()
            ->where('status', SnkKilkanaSubscriberStatusEnum::Confirmed)
            ->orderBy('id')
            ->pluck('id')->map(fn ($id) => (int) $id);

        DB::transaction(function () use ($issue, $ids) {
            $issue->forceFill([
                'status' => SnkKilkanaNewsletterIssueModel::SENT,
                'post_ids' => $this->pieces($issue)->pluck('id')->map(fn ($id) => (int) $id)->all(),
                'sent_at' => now(),
                'recipients_count' => $ids->count(),
            ])->save();
        });

        foreach ($ids->chunk(self::BATCH) as $tanda) {
            SnkKilkanaNewsletterSendJob::dispatch($database, $client, (int) $issue->getKey(), $tanda->values()->all());
        }

        $this->registro->log('newsletter', 'issue_sent', $issue->subject, '/newsletter');

        return $ids->count();
    }

    /** Una tanda, ya dentro del periódico: un correo por suscriptor, y el que falla se apunta. */
    public function deliver(int $issueId, string $client, array $subscriberIds): int
    {
        $issue = SnkKilkanaNewsletterIssueModel::query()->find($issueId);
        if ($issue === null || ! $issue->sent()) {
            return 0;
        }
        $periodico = $this->newspaper();
        $piezas = $this->pieces($issue);
        $enviados = 0;
        foreach (SnkKilkanaSubscriberModel::query()->whereKey($subscriberIds)->get() as $suscriptor) {
            // Solo a quien sigue confirmado: una baja entre el «Send» y la tanda no recibe nada.
            if ($suscriptor->status !== SnkKilkanaSubscriberStatusEnum::Confirmed) {
                continue;
            }
            // Y solo una vez: el worker se reinicia por diseño y una tanda en vuelo se vuelve a
            // ejecutar entera. La marca va ANTES del correo, por suscriptor: un boletín
            // duplicado cuesta bajas, y un correo perdido por un fallo entre la marca y el
            // envío es el lado bueno por el que equivocarse.
            if (! SnkKilkanaNewsletterEventModel::record((int) $issue->getKey(), (int) $suscriptor->getKey(), SnkKilkanaNewsletterEventModel::SENT)) {
                continue;
            }
            try {
                Mail::to($suscriptor->email)->send($this->mail($issue, $piezas, $periodico, $client, $suscriptor));
                $enviados++;
            } catch (\Throwable $e) {
                Log::error('kilkana: no se pudo enviar el boletín', ['issue' => $issueId, 'subscriber' => $suscriptor->id, 'error' => $e->getMessage()]);
            }
        }

        return $enviados;
    }

    /** Una copia a una dirección, sin píxel ni baja de nadie: es una prueba. */
    public function sendTest(SnkKilkanaNewsletterIssueModel $issue, string $to, string $client): void
    {
        $piezas = $this->pieces($issue);
        $mail = new SnkKilkanaNewsletterMail(
            $this->newspaper(),
            $issue->subject,
            $issue->preheader,
            $piezas->map(fn (SnkKilkanaPostModel $p) => ['title' => $p->title, 'excerpt' => (string) $p->excerpt, 'url' => $this->articleUrl($p)])->all(),
            null,
            $this->siteUrl().'/',
        );
        Mail::to($to)->send($mail);
        $issue->forceFill(['test_sent_to' => $to, 'test_sent_at' => now()])->save();
    }

    /**
     * Las cifras de un número tal como el panel las lee: cuánta gente abrió, pulsó y se fue.
     *
     * @return array{opens: int, clicks: int, unsubs: int}
     */
    public function counts(SnkKilkanaNewsletterIssueModel $issue): array
    {
        $id = (int) $issue->getKey();

        return $this->countsFor([$id])[$id];
    }

    /**
     * Las cifras de varios números en una sola consulta, que es como las pide el listado.
     *
     * @param  list<int>  $issueIds
     * @return array<int, array{opens: int, clicks: int, unsubs: int}>
     */
    public function countsFor(array $issueIds): array
    {
        if ($issueIds === []) {
            return [];
        }
        $clase = [
            SnkKilkanaNewsletterEventModel::OPEN => 'opens',
            SnkKilkanaNewsletterEventModel::CLICK => 'clicks',
            SnkKilkanaNewsletterEventModel::UNSUB => 'unsubs',
        ];
        $porNumero = array_fill_keys($issueIds, ['opens' => 0, 'clicks' => 0, 'unsubs' => 0]);

        $filas = SnkKilkanaNewsletterEventModel::query()
            ->whereIn('issue_id', $issueIds)
            ->whereIn('kind', array_keys($clase))
            ->selectRaw('issue_id, kind, count(distinct subscriber_id) as total')
            ->groupBy('issue_id', 'kind')
            ->get();

        foreach ($filas as $fila) {
            $porNumero[(int) $fila->issue_id][$clase[$fila->kind]] = (int) $fila->total;
        }

        return $porNumero;
    }

    /** Cuántos borradores esperan, que es la cifra del raíl. */
    public function drafts(): int
    {
        return SnkKilkanaNewsletterIssueModel::query()->where('status', SnkKilkanaNewsletterIssueModel::DRAFT)->count();
    }

    /**
     * La salud de la lista y de dónde salieron las altas: la pestaña de suscriptores.
     *
     * @return array<string, mixed>
     */
    public function listStats(): array
    {
        $porEstado = SnkKilkanaSubscriberModel::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $porOrigen = SnkKilkanaSubscriberModel::query()->selectRaw('consent_source, count(*) as total')->groupBy('consent_source')->pluck('total', 'consent_source');
        $desde = now()->subDays(self::WINDOW_DAYS);
        $enviados = SnkKilkanaNewsletterIssueModel::query()->where('status', SnkKilkanaNewsletterIssueModel::SENT)->where('sent_at', '>=', $desde)->get(['id', 'recipients_count']);
        $aperturas = $enviados->isEmpty() ? 0 : SnkKilkanaNewsletterEventModel::query()
            ->whereIn('issue_id', $enviados->modelKeys())->where('kind', SnkKilkanaNewsletterEventModel::OPEN)
            ->count();
        $destinatarios = (int) $enviados->sum('recipients_count');
        $bajas = SnkKilkanaSubscriberModel::query()->where('status', SnkKilkanaSubscriberStatusEnum::Unsubscribed)->where('updated_at', '>=', $desde)->count();
        $semana = SnkKilkanaSubscriberModel::query()->where('status', SnkKilkanaSubscriberStatusEnum::Confirmed)->where('consent_at', '>=', now()->subDays(7))->count();

        return [
            'confirmed' => (int) $porEstado->get(SnkKilkanaSubscriberStatusEnum::Confirmed->value, 0),
            'pending' => (int) $porEstado->get(SnkKilkanaSubscriberStatusEnum::Pending->value, 0),
            'unsubscribed' => (int) $porEstado->get(SnkKilkanaSubscriberStatusEnum::Unsubscribed->value, 0),
            'confirmed_this_week' => $semana,
            'open_rate' => $destinatarios > 0 ? round($aperturas / $destinatarios, 3) : null,
            'unsubscribed_in_window' => $bajas,
            'window_days' => self::WINDOW_DAYS,
            'sources' => [
                'public_form' => (int) $porOrigen->get(SnkKilkanaConsentSourceEnum::PublicForm->value, 0),
                'import' => (int) $porOrigen->get(SnkKilkanaConsentSourceEnum::Import->value, 0),
                'admin' => (int) $porOrigen->get(SnkKilkanaConsentSourceEnum::Admin->value, 0),
            ],
        ];
    }

    private function mail(SnkKilkanaNewsletterIssueModel $issue, Collection $piezas, string $periodico, string $client, SnkKilkanaSubscriberModel $suscriptor): SnkKilkanaNewsletterMail
    {
        $firmado = fn (string $ruta, array $params) => URL::signedRoute($ruta, ['client' => $client, ...$params]);

        return new SnkKilkanaNewsletterMail(
            $periodico,
            $issue->subject,
            $issue->preheader,
            $piezas->map(fn (SnkKilkanaPostModel $p) => [
                'title' => $p->title,
                'excerpt' => (string) $p->excerpt,
                'url' => $firmado('kilkana.newsletter.click', ['issue' => $issue->getKey(), 'subscriber' => $suscriptor->getKey(), 'post' => $p->getKey()]),
            ])->all(),
            $firmado('kilkana.newsletter.open', ['issue' => $issue->getKey(), 'subscriber' => $suscriptor->getKey()]),
            $firmado('kilkana.subscribe.unsubscribe', ['subscriber' => $suscriptor->getKey(), 'issue' => $issue->getKey()]),
        );
    }

    private function newspaper(): string
    {
        return SnkKilkanaSettingModel::query()->find(1)?->tenant_name ?: config('app.name');
    }

    /** La dirección pública del periódico, sin barra final. */
    public function siteUrl(): string
    {
        return rtrim((string) (SnkKilkanaSettingModel::query()->find(1)?->tenant_url ?: config('app.url')), '/');
    }

    public function articleUrl(SnkKilkanaPostModel $post): string
    {
        return $this->siteUrl().'/'.ltrim((string) $post->url_path, '/');
    }
}
