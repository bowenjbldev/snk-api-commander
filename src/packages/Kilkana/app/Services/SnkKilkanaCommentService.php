<?php

namespace Packages\Kilkana\Services;

use Illuminate\Database\Eloquent\Collection;
use Packages\Kilkana\Enums\SnkKilkanaCommentStatusEnum;
use Packages\Kilkana\Models\SnkKilkanaCommentModel;

class SnkKilkanaCommentService
{
    /** Las acciones masivas que cambian de estado, por su verbo. Borrar va aparte. */
    public const STATUS_OF = [
        'approve' => SnkKilkanaCommentStatusEnum::Approved,
        'reject' => SnkKilkanaCommentStatusEnum::Rejected,
        'pending' => SnkKilkanaCommentStatusEnum::Pending,
        'spam' => SnkKilkanaCommentStatusEnum::Spam,
    ];

    /**
     * Cuelga de la cuenta de cada comentario cuántos ha escrito y cuántos se le han rechazado.
     *
     * **Una consulta para la página entera, no una por fila.** Vive aquí porque es una
     * consulta; la forma de la fila la declara `SnkKilkanaCommentResource`.
     *
     * @param  Collection<int, SnkKilkanaCommentModel>  $comments
     * @return Collection<int, SnkKilkanaCommentModel>
     */
    public function withAuthorHistory(Collection $comments): Collection
    {
        $accounts = $comments->pluck('user')->filter()->unique(fn ($u) => $u->getKey());
        if ($accounts->isEmpty()) {
            return $comments;
        }

        $history = [];
        SnkKilkanaCommentModel::query()
            ->whereIn('user_id', $accounts->map->getKey()->all())
            ->selectRaw('user_id, status, count(*) as total')
            ->groupBy('user_id', 'status')
            ->get()
            ->each(function ($row) use (&$history) {
                $history[$row->user_id] ??= ['total' => 0, 'rejected' => 0];
                $history[$row->user_id]['total'] += (int) $row->total;
                if ($row->status === SnkKilkanaCommentStatusEnum::Rejected) {
                    $history[$row->user_id]['rejected'] += (int) $row->total;
                }
            });

        $accounts->each(function ($account) use ($history) {
            $account->setAttribute('comments_total', $history[$account->getKey()]['total'] ?? 0);
            $account->setAttribute('comments_rejected', $history[$account->getKey()]['rejected'] ?? 0);
        });

        return $comments;
    }

    /**
     * Cuántos hay de cada estado, y `all`. Vive aquí y no en el controlador porque lo
     * preguntan dos puertas —la pantalla de moderación y el raíl del panel— y una cifra
     * escrita dos veces acaba contando cosas distintas.
     *
     * @return array<string, int>
     */
    public function countsByStatus(): array
    {
        $byStatus = SnkKilkanaCommentModel::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');
        $counts = [];
        foreach (SnkKilkanaCommentStatusEnum::cases() as $case) {
            $counts[$case->value] = (int) $byStatus->get($case->value, 0);
        }
        $counts['all'] = array_sum($counts);

        return $counts;
    }

    /** Desde cuándo espera el más viejo de la cola: es lo que mide si la moderación va tarde. */
    public function oldestPendingAt(): ?string
    {
        return SnkKilkanaCommentModel::query()
            ->where('status', SnkKilkanaCommentStatusEnum::Pending)
            ->min('created_at');
    }

    public function create(array $data, int $userId): ?SnkKilkanaCommentModel
    {
        if (! empty($data['parent_id'])) {
            $parentPost = SnkKilkanaCommentModel::query()->whereKey($data['parent_id'])->value('post_id');
            if ((int) $parentPost !== (int) $data['post_id']) {
                return null;
            }
        }

        return SnkKilkanaCommentModel::create([
            ...$data,
            'user_id' => $userId,
            'status' => SnkKilkanaCommentStatusEnum::Pending,
        ]);
    }

    public function bulk(array $ids, string $action): int
    {
        $query = SnkKilkanaCommentModel::query()->whereIn('id', $ids);

        if ($action === 'delete') {
            $count = $query->count();
            $query->delete();

            return $count;
        }

        $status = self::STATUS_OF[$action] ?? SnkKilkanaCommentStatusEnum::from($action);

        return $query->update(['status' => $status]);
    }
}
