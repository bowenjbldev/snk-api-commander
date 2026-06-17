<?php

namespace Packages\Blog\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Packages\Blog\Models\AuthorModel;
use Packages\Core\Http\Controllers\CoreBaseApiController;

#[Group('Authors')]
class BlogAuthorsController extends CoreBaseApiController
{
    protected string $modelClass = AuthorModel::class;

    protected string $indexPermission = 'index authors';
    protected string $showPermission = 'show authors';
    protected string $storePermission = 'create authors';
    protected string $updatePermission = 'update authors';
    protected string $destroyPermission = 'delete authors';

    protected array $allowedWith = ['posts'];

    protected array $validationRulesStore = [
        'name' => 'required|string|max:255',
        'email' => 'required|email|max:255|unique:blog_authors,email',
        'bio' => 'nullable|string',
    ];

    protected array $validationRulesUpdate = [
        'name' => 'nullable|string|max:255',
        'email' => 'nullable|email|max:255|unique:blog_authors,email,{id}',
        'bio' => 'nullable|string',
    ];
}
