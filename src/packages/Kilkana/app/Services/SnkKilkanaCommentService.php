<?php

namespace Packages\Kilkana\Services;

use Packages\Kilkana\Enums\SnkKilkanaCommentStatusEnum;
use Packages\Kilkana\Models\SnkKilkanaCommentModel;

class SnkKilkanaCommentService
{
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

        // La accion `approve` no se llama igual que el estado al que lleva; las otras dos si.
        $estado = SnkKilkanaCommentStatusEnum::from($action === 'approve' ? 'approved' : $action);

        return $query->update(['status' => $estado]);
    }
}
