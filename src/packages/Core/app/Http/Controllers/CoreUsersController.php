<?php

namespace Packages\Core\Http\Controllers;

use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packages\Core\Models\CoreUserModel;

#[Group('Users')]
class CoreUsersController extends CoreBaseApiController
{
    protected string $modelClass = CoreUserModel::class;
    protected string $indexPermission   = 'index users';
    protected string $showPermission    = 'show users';
    protected string $storePermission   = 'create users';
    protected string $updatePermission  = 'update users';
    protected string $destroyPermission = 'delete users';
    protected array $allowedIncludes = [
        'roles',
    ];
    protected array $validationRulesStore = [
        'email' => 'required|email|max:255|unique:users,email',
        'full_name' => 'required|max:255',
        'name' => 'nullable|max:255',
        'role' => 'required|string|exists:roles,name',
        'company' => 'nullable|max:255',
        'phone' => 'nullable|max:50',
        'password' => 'required',
    ];
    protected array $validationRulesUpdate = [
        'email' => 'nullable|email|max:255|unique:users,email,{id}',
        'full_name' => 'nullable|max:255',
        'name' => 'nullable|max:255',
        'role' => 'nullable|string|exists:roles,name',
        'company' => 'nullable|max:255',
        'phone' => 'nullable|max:50',
        'password' => 'nullable',
    ];

    #[Endpoint(
        title: '1. List users',
        description: 'Returns a list of all users.',
    )]
    #[QueryParameter('include', description: 'Relationships to include (e.g. roles).', type: 'string', example: 'roles')]
    #[Response(status: 200, description: 'Success', type: 'array<Packages\\Core\\Models\\CoreUserModel>')]
    public function index(): JsonResponse
    {
        return parent::index();
    }

    #[Endpoint(
        title: '2. Create user',
        description: 'Creates a new user.',
    )]
    #[BodyParameter('email', description: 'User email.', type: 'string', required: true, example: 'user@example.com')]
    #[BodyParameter('full_name', description: 'Full name.', type: 'string', required: true, example: 'Jane Doe')]
    #[BodyParameter('name', description: 'Short name.', type: 'string', example: 'jdoe')]
    #[BodyParameter('role', description: 'Role name.', type: 'string', required: true, example: 'admin')]
    #[BodyParameter('company', description: 'Company name.', type: 'string', example: 'Company Ltd.')]
    #[BodyParameter('phone', description: 'Phone number.', type: 'string', example: '+1 555 0100')]
    #[BodyParameter('password', description: 'User password.', type: 'string', required: true, example: 'secret')]
    #[Response(status: 201, description: 'Created', type: 'Packages\\Core\\Models\\CoreUserModel')]
    #[Response(422, 'Validation')]
    public function store(Request $request): JsonResponse
    {
        if ($response = $this->requirePermission($this->storePermission)) {
            return $response;
        }

        $data = $request->all();
        if ($this->validationRulesStore !== []) {
            $validator = validator($data, $this->validationRulesStore, $this->validationMessages);
            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }
            $data = $validator->validated();
        }

        $role = $data['role'];
        unset($data['role']);

        $user = CoreUserModel::create($data);
        $user->assignRole($role);

        return response()->json($user->load('roles'), 201);
    }

    #[Endpoint(
        title: '3. Show user',
        description: 'Returns details for a specific user.',
    )]
    #[PathParameter('id', description: 'User ID.', type: 'int', example: 1)]
    #[QueryParameter('include', description: 'Relationships to include (roles).', type: 'string', example: 'roles')]
    #[Response(status: 200, description: 'Success', type: 'Packages\\Core\\Models\\CoreUserModel')]
    #[Response(404, 'Not found')]
    public function show($id = null): JsonResponse
    {
        return parent::show($id);
    }

    #[Endpoint(
        title: '4. Update user',
        description: 'Updates an existing user.',
    )]
    #[PathParameter('id', description: 'User ID.', type: 'int', example: 1)]
    #[BodyParameter('email', description: 'User email.', type: 'string', example: 'user@example.com')]
    #[BodyParameter('full_name', description: 'Full name.', type: 'string', example: 'Jane Doe')]
    #[BodyParameter('name', description: 'Short name.', type: 'string', example: 'jdoe')]
    #[BodyParameter('role', description: 'Role name.', type: 'string', example: 'admin')]
    #[BodyParameter('company', description: 'Company name.', type: 'string', example: 'Company Ltd.')]
    #[BodyParameter('phone', description: 'Phone number.', type: 'string', example: '+1 555 0100')]
    #[BodyParameter('password', description: 'User password.', type: 'string', example: 'secret')]
    #[Response(status: 200, description: 'Updated', type: 'Packages\\Core\\Models\\CoreUserModel')]
    #[Response(404, 'Not found')]
    #[Response(422, 'Validation')]
    public function update(Request $request, $id = null): JsonResponse
    {
        if ($response = $this->requirePermission($this->updatePermission)) {
            return $response;
        }

        if ($id === null) {
            return response()->json(['error' => 'Missing id'], 422);
        }

        $record = $this->findModel($id);

        if ($record === null) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        $data = $request->all();
        if ($this->validationRulesUpdate !== []) {
            $rules = $this->interpolateRules($this->validationRulesUpdate, $id);
            $validator = validator($data, $rules, $this->validationMessages);
            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }
            $data = $validator->validated();
        }

        $role = $data['role'] ?? null;
        unset($data['role']);

        $record->fill($data)->save();

        if ($role !== null) {
            $record->syncRoles([$role]);
        }

        return response()->json($record->fresh());
    }

    #[Endpoint(
        title: '5. Delete user',
        description: 'Deletes a user.',
    )]
    #[PathParameter('id', description: 'User ID.', type: 'int', example: 1)]
    #[Response(200, 'Deleted')]
    #[Response(404, 'Not found')]
    public function destroy($id = null): JsonResponse
    {
        return parent::destroy($id);
    }
}
