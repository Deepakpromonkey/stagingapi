<?php

namespace App\Http\Controllers\Api\V1\Role;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Resources\RoleResource;
use App\Services\RoleService;
use Illuminate\Http\Request;

class RoleController extends BaseController
{
    public function __construct(
        protected RoleService $roleService
    ) {}

    /**
     * Roles available for the role picker.
     *
     * By default only the roles the authenticated user is allowed to assign
     * are returned, so the invite screen can render the list as-is.
     * Pass ?scope=all to list every active role (e.g. for filters / labels).
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $roles = $request->query('scope') === 'all'
            ? $this->roleService->all()
            : $this->roleService->assignableBy($user);

        return $this->success([
            'roles' => RoleResource::collection($roles),
            'assignable_slugs' => $this->roleService->assignableSlugs($user),
        ], 'Roles retrieved successfully.');
    }
}
