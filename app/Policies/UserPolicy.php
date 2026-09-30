<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function view(User $actor, User $subject): bool
    {
        return $actor->is($subject);
    }
}
