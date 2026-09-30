<?php

namespace App\Policies;

use App\Models\Scholarship;
use App\Models\User;

class ScholarshipPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('admin.access');
    }

    public function view(User $user, Scholarship $scholarship): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Scholarship $scholarship): bool
    {
        return $this->viewAny($user);
    }

    public function manage(User $user, Scholarship $scholarship): bool
    {
        return $this->viewAny($user);
    }
}
