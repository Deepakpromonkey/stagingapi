<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Services\UserService;
use Illuminate\Http\Request;

class UserController extends BaseController
{
    public function __construct(
        protected UserService $userService
    ) {}

    public function index(Request $request)
    {
        $users = $this->userService->list(
            $request->user(),
            $request->integer('per_page') ?: null
        );

        return $this->success([
            'users' => UserResource::collection($users),
            'pagination' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    /**
     * Change a teammate's seat or contact details.
     */
    public function update(UpdateUserRequest $request, string $uuid)
    {
        $user = $this->userService->update(
            $request->user(),
            $uuid,
            $request->validated()
        );

        return $this->success(
            new UserResource($user),
            'User updated successfully.'
        );
    }

    /**
     * Remove a teammate from the company.
     */
    public function destroy(Request $request, string $uuid)
    {
        $this->userService->delete($request->user(), $uuid);

        return $this->success(null, 'User deleted successfully.');
    }
}
