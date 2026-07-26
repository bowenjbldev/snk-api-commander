<?php

namespace Packages\Snk\Kilkana\Services;

use Packages\Snk\Kilkana\Models\SnkKilkanaCommentModel;

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
            'status' => 'pending',
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

        return $query->update([
            'status' => $action === 'approve' ? 'approved' : $action,
        ]);
    }
}
