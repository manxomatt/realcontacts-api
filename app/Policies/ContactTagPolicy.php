<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ContactTag;
use App\Models\User;

/**
 * ContactTagPolicy
 *
 * Policy untuk authorization ContactTag actions.
 * Memastikan user hanya bisa mengakses tag miliknya sendiri.
 *
 * Registered di AppServiceProvider via Gate::policy()
 */
class ContactTagPolicy
{
    /**
     * Apakah user authorized untuk view tag ini.
     *
     * Hanya owner (user_id) yang bisa view.
     */
    public function view(User $user, ContactTag $tag): bool
    {
        return $user->id === $tag->user_id;
    }

    /**
     * Apakah user authorized untuk update tag ini.
     *
     * Hanya owner (user_id) yang bisa update.
     * System tags bisa diupdate (warna/icon), hanya delete yang dicegah.
     */
    public function update(User $user, ContactTag $tag): bool
    {
        return $user->id === $tag->user_id;
    }

    /**
     * Apakah user authorized untuk delete tag ini.
     *
     * Hanya owner (user_id) yang bisa delete.
     * System tags tidak boleh dihapus (dicegah di service layer).
     */
    public function delete(User $user, ContactTag $tag): bool
    {
        return $user->id === $tag->user_id;
    }
}
