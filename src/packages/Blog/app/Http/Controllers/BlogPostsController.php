<?php

namespace Packages\Blog\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Packages\Blog\Models\PostModel;
use Packages\Core\Http\Controllers\CoreBaseApiController;

#[Group('Posts')]
class BlogPostsController extends CoreBaseApiController
{
    protected string $modelClass = PostModel::class;

    protected string $indexPermission = 'index posts';
    protected string $showPermission = 'show posts';
    protected string $storePermission = 'create posts';
    protected string $updatePermission = 'update posts';
    protected string $destroyPermission = 'delete posts';

    protected array $allowedWith = ['author', 'comments'];

    protected array $validationRulesStore = [
        'author_id' => 'required|integer|exists:blog_authors,id',
        'title' => 'required|string|max:255',
        'slug' => 'required|string|max:255|unique:blog_posts,slug',
        'body' => 'nullable|string',
        'status' => 'nullable|in:draft,published',
        'views' => 'nullable|integer|min:0',
    ];

    protected array $validationRulesUpdate = [
        'author_id' => 'nullable|integer|exists:blog_authors,id',
        'title' => 'nullable|string|max:255',
        'slug' => 'nullable|string|max:255|unique:blog_posts,slug,{id}',
        'body' => 'nullable|string',
        'status' => 'nullable|in:draft,published',
        'views' => 'nullable|integer|min:0',
    ];
}
