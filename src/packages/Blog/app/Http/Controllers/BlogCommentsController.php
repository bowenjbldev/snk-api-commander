<?php

namespace Packages\Blog\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Packages\Blog\Models\CommentModel;
use Packages\Core\Http\Controllers\CoreBaseApiController;

#[Group('Comments')]
class BlogCommentsController extends CoreBaseApiController
{
    protected string $modelClass = CommentModel::class;

    protected string $indexPermission = 'index comments';
    protected string $showPermission = 'show comments';
    protected string $storePermission = 'create comments';
    protected string $updatePermission = 'update comments';
    protected string $destroyPermission = 'delete comments';

    protected array $allowedWith = ['post'];

    protected array $validationRulesStore = [
        'post_id' => 'required|integer|exists:blog_posts,id',
        'author_name' => 'required|string|max:255',
        'body' => 'required|string',
        'approved' => 'nullable|boolean',
    ];

    protected array $validationRulesUpdate = [
        'author_name' => 'nullable|string|max:255',
        'body' => 'nullable|string',
        'approved' => 'nullable|boolean',
    ];
}
