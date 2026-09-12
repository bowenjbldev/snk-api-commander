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

    public function __construct(private readonly SnkKilkanaActivityService $activityLog) {}

    /** Un borrador nuevo, arriba. */
    public function create(?int $userId): SnkKilkanaNewsletterIssueModel
    {
        return SnkKilkanaNewsletterIssueModel::create([
            'subject' => 'Número sin título',
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
    public function postsOf(SnkKilkanaNewsletterIssueModel $issue): Collection
    {
        return $this->postsForIssues(collect([$issue]))->first();
    }

    /**
     * Lo mismo para varios números y en su misma posición, en tres consultas como mucho en vez
     * de una por número: el panel los pinta todos a la vez.
     *
     * @param  Collection<int, SnkKilkanaNewsletterIssueModel>  $issues
     * @return Collection<int, Collection<int, SnkKilkanaPostModel>>
     */
    public function postsForIssues(Collection $issues): Collection
    {
        $ordered = $issues->values();
        $picked = $ordered->map(fn (SnkKilkanaNewsletterIssueModel $i) => is_array($i->post_ids) ? array_values(array_filter($i->post_ids)) : []);
        $ids = fn (bool $isSent) => $picked
            ->filter(fn (array $picks, int $p) => $ordered[$p]->sent() === $isSent)
            ->flatten()->unique()->values()->all();
        $byId = fn (Builder $query, array $wanted) => $wanted === []
            ? collect()
            : $this->row($query)->whereKey($wanted)->get()->keyBy('id');

        // Un número enviado enseña lo que llevó, aunque una pieza se haya tirado después; un
        // borrador con piezas elegidas enseña las que sigan publicadas, en el orden elegido.
        // Son dos consultas distintas, pero dos para todos los números, no dos por número.
        $archived = $byId(SnkKilkanaPostModel::withTrashed(), $ids(true));
        $published = $byId(SnkKilkanaPostModel::query()->published(), $ids(false));
        $latest = null;

        return $ordered->map(function (SnkKilkanaNewsletterIssueModel $issue, int $p) use ($picked, $archived, $published, &$latest) {
            if ($picked[$p] === []) {
                return $latest ??= $this->recent(SnkKilkanaNewsletterIssueModel::POSTS_PER_ISSUE);
            }
            $pool = $issue->sent() ? $archived : $published;

            return collect($picked[$p])->map(fn ($id) => $pool->get($id))->filter()->values();
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
        return $this->row(SnkKilkanaPostModel::query()->published())
            ->orderByDesc('publish_at')->orderByDesc('id')->limit($limit)->get();
    }

    /**
     * De una pieza aquí solo se lee esto: el panel pinta el titular, y el correo el titular, la
     * entradilla y el enlace —que sale de `url_date` y `slug`—. Nadie serializa la pieza entera,
     * así que las cuatro relaciones que `$with` carga sola eran cuatro consultas por llamada
     * para nada.
     */
    private function row(Builder $query): Builder
    {
        return $query->select(['id', 'title', 'excerpt', 'url_date', 'slug'])
            ->without(['category', 'author', 'coverMedia', 'tags']);
    }

    /**
     * Manda los números con hora cumplida. Uno por uno y dentro de una transacción con la
     * fila bloqueada: dos ticks del scheduler solapados no pueden mandar el mismo dos veces.
     */
    public function sendDue(string $database, string $client): int
    {
        $sent = 0;
        $ids = SnkKilkanaNewsletterIssueModel::query()
            ->where('status', SnkKilkanaNewsletterIssueModel::DRAFT)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->pluck('id');

        foreach ($ids as $id) {
            $isSent = DB::transaction(function () use ($id, $database, $client) {
                $issue = SnkKilkanaNewsletterIssueModel::query()->whereKey($id)->lockForUpdate()->first();
                if ($issue === null || $issue->sent()) {
                    return false;
                }
                $this->send($issue, $database, $client);

                return true;
            });
            $sent += $isSent ? 1 : 0;
        }

        return $sent;
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
                'post_ids' => $this->postsOf($issue)->pluck('id')->map(fn ($id) => (int) $id)->all(),
                'sent_at' => now(),
                'recipients_count' => $ids->count(),
            ])->save();
        });

        foreach ($ids->chunk(self::BATCH) as $batch) {
            SnkKilkanaNewsletterSendJob::dispatch($database, $client, (int) $issue->getKey(), $batch->values()->all());
        }

        $this->activityLog->log('newsletter', 'issue_sent', $issue->subject, '/newsletter');

        return $ids->count();
    }

    /** Una tanda, ya dentro del periódico: un correo por suscriptor, y el que falla se apunta. */
    public function deliver(int $issueId, string $client, array $subscriberIds): int
    {
        $issue = SnkKilkanaNewsletterIssueModel::query()->find($issueId);
        if ($issue === null || ! $issue->sent()) {
            return 0;
        }
        $newspaper = $this->newspaper();
        $posts = $this->postsOf($issue);
        $sent = 0;
        foreach (SnkKilkanaSubscriberModel::query()->whereKey($subscriberIds)->get() as $subscriber) {
            // Solo a quien sigue confirmado: una baja entre el «Send» y la tanda no recibe nada.
            if ($subscriber->status !== SnkKilkanaSubscriberStatusEnum::Confirmed) {
                continue;
            }
            // Y solo una vez: el worker se reinicia por diseño y una tanda en vuelo se vuelve a
            // ejecutar entera. La marca va ANTES del correo, por suscriptor: un boletín
            // duplicado cuesta bajas, y un correo perdido por un fallo entre la marca y el
            // envío es el lado bueno por el que equivocarse.
            if (! SnkKilkanaNewsletterEventModel::record((int) $issue->getKey(), (int) $subscriber->getKey(), SnkKilkanaNewsletterEventModel::SENT)) {
                continue;
            }
            try {
                Mail::to($subscriber->email)->send($this->mail($issue, $posts, $newspaper, $client, $subscriber));
                $sent++;
            } catch (\Throwable $e) {
                Log::error('kilkana: could not send the newsletter', ['issue' => $issueId, 'subscriber' => $subscriber->id, 'error' => $e->getMessage()]);
            }
        }

        return $sent;
    }

    /** Una copia a una dirección, sin píxel ni baja de nadie: es una prueba. */
    public function sendTest(SnkKilkanaNewsletterIssueModel $issue, string $to, string $client): void
    {
        $posts = $this->postsOf($issue);
        $mail = new SnkKilkanaNewsletterMail(
            $this->newspaper(),
            $issue->subject,
            $issue->preheader,
            $posts->map(fn (SnkKilkanaPostModel $p) => ['title' => $p->title, 'excerpt' => (string) $p->excerpt, 'url' => $this->articleUrl($p)])->all(),
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
        $eventKinds = [
            SnkKilkanaNewsletterEventModel::OPEN => 'opens',
            SnkKilkanaNewsletterEventModel::CLICK => 'clicks',
            SnkKilkanaNewsletterEventModel::UNSUB => 'unsubs',
        ];
        $byIssue = array_fill_keys($issueIds, ['opens' => 0, 'clicks' => 0, 'unsubs' => 0]);

        $rows = SnkKilkanaNewsletterEventModel::query()
            ->whereIn('issue_id', $issueIds)
            ->whereIn('kind', array_keys($eventKinds))
            ->selectRaw('issue_id, kind, count(distinct subscriber_id) as total')
            ->groupBy('issue_id', 'kind')
            ->get();

        foreach ($rows as $row) {
            $byIssue[(int) $row->issue_id][$eventKinds[$row->kind]] = (int) $row->total;
        }

        return $byIssue;
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
        $byStatus = SnkKilkanaSubscriberModel::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $bySource = SnkKilkanaSubscriberModel::query()->selectRaw('consent_source, count(*) as total')->groupBy('consent_source')->pluck('total', 'consent_source');
        $windowStart = now()->subDays(self::WINDOW_DAYS);
        $sent = SnkKilkanaNewsletterIssueModel::query()->where('status', SnkKilkanaNewsletterIssueModel::SENT)->where('sent_at', '>=', $windowStart)->get(['id', 'recipients_count']);
        $opens = $sent->isEmpty() ? 0 : SnkKilkanaNewsletterEventModel::query()
            ->whereIn('issue_id', $sent->modelKeys())->where('kind', SnkKilkanaNewsletterEventModel::OPEN)
            ->count();
        $recipients = (int) $sent->sum('recipients_count');
        $unsubscribed = SnkKilkanaSubscriberModel::query()->where('status', SnkKilkanaSubscriberStatusEnum::Unsubscribed)->where('updated_at', '>=', $windowStart)->count();
        $thisWeek = SnkKilkanaSubscriberModel::query()->where('status', SnkKilkanaSubscriberStatusEnum::Confirmed)->where('consent_at', '>=', now()->subDays(7))->count();

        return [
            'confirmed' => (int) $byStatus->get(SnkKilkanaSubscriberStatusEnum::Confirmed->value, 0),
            'pending' => (int) $byStatus->get(SnkKilkanaSubscriberStatusEnum::Pending->value, 0),
            'unsubscribed' => (int) $byStatus->get(SnkKilkanaSubscriberStatusEnum::Unsubscribed->value, 0),
            'confirmed_this_week' => $thisWeek,
            'open_rate' => $recipients > 0 ? round($opens / $recipients, 3) : null,
            'unsubscribed_in_window' => $unsubscribed,
            'window_days' => self::WINDOW_DAYS,
            'sources' => [
                'public_form' => (int) $bySource->get(SnkKilkanaConsentSourceEnum::PublicForm->value, 0),
                'import' => (int) $bySource->get(SnkKilkanaConsentSourceEnum::Import->value, 0),
                'admin' => (int) $bySource->get(SnkKilkanaConsentSourceEnum::Admin->value, 0),
            ],
        ];
    }

    private function mail(SnkKilkanaNewsletterIssueModel $issue, Collection $posts, string $newspaper, string $client, SnkKilkanaSubscriberModel $subscriber): SnkKilkanaNewsletterMail
    {
        $signedUrl = fn (string $path, array $params) => URL::signedRoute($path, ['client' => $client, ...$params]);

        return new SnkKilkanaNewsletterMail(
            $newspaper,
            $issue->subject,
            $issue->preheader,
            $posts->map(fn (SnkKilkanaPostModel $p) => [
                'title' => $p->title,
                'excerpt' => (string) $p->excerpt,
                'url' => $signedUrl('kilkana.newsletter.click', ['issue' => $issue->getKey(), 'subscriber' => $subscriber->getKey(), 'post' => $p->getKey()]),
            ])->all(),
            $signedUrl('kilkana.newsletter.open', ['issue' => $issue->getKey(), 'subscriber' => $subscriber->getKey()]),
            $signedUrl('kilkana.subscribe.unsubscribe', ['subscriber' => $subscriber->getKey(), 'issue' => $issue->getKey()]),
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
