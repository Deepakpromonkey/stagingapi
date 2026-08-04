<?php

namespace App\Services;

use App\Models\User;

class UserService
{
    public function list($user)
    {
        return User::with('roles')
            ->where('company_id', $user->company_id)
            ->orderBy('first_name')
            ->paginate(10);
    }
}
