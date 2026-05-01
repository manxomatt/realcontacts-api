<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\UserContact;

/**
 * UserContactPolicy
 *
 * Policy untuk authorization UserContact actions.
 * Memastikan user hanya bisa mengakses kontak miliknya sendiri.
 *
 * Registered di AppServiceProvider via Gate::policy()
 */
class UserContactPolicy
{
    /**
     * Apakah user authorized untuk view kontak ini.
     *
     * Hanya owner (user_id) yang bisa view.
     */
    public function view(User $user, UserContact $contact): bool
    {
        return $user->id === $contact->user_id;
    }

    /**
     * Apakah user authorized untuk update kontak ini.
     *
     * Hanya owner (user_id) yang bisa update.
     */
    public function update(User $user, UserContact $contact): bool
    {
        return $user->id === $contact->user_id;
    }

    /**
     * Apakah user authorized untuk delete kontak ini.
     *
     * Hanya owner (user_id) yang bisa delete.
     */
    public function delete(User $user, UserContact $contact): bool
    {
        return $user->id === $contact->user_id;
    }
}
