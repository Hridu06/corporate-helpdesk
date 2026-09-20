<?php

namespace App\Policies;

use App\Models\User;

class LoginActivityPolicy
{
    /** Sign-in history contains IP addresses and devices, so it needs an explicit permission. */
    public function viewAny(User $actor): bool
    {
        return $actor->can('activity.view');
    }
}
