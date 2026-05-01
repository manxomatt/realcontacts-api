<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * ContactTag Model
 *
 * Merepresentasikan tag yang dibuat user untuk kategorisasi kontak.
 * Tag per-user: setiap user punya set tag masing-masing.
 *
 * Tag bawaan (system tags) tidak bisa dihapus tapi bisa diubah warna/icon.
 *
 * Relasi:
 *   - Belongs to User
 *   - Belongs to Many UserContacts
 */
class ContactTag extends Model
{
    use HasFactory;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'name',
        'color',
        'icon',
        'is_system',
        'usage_count',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'is_system'   => 'boolean',
        'usage_count' => 'integer',
        'created_at'  => 'datetime',
        'updated_at'  => 'datetime',
    ];

    // ─── Relationships ─────────────────────────────────────────────────────

    /**
     * User yang memiliki tag ini.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Kontak yang memiliki tag ini.
     *
     * @return BelongsToMany<UserContact>
     */
    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(
            UserContact::class,
            'contact_tag_user_contact',
            'contact_tag_id',
            'user_contact_id',
        )
            ->withTimestamps('tagged_at');
    }

    // ─── Scopes ────────────────────────────────────────────────────────────

    /**
     * Scope untuk query tag sistem saja.
     */
    public function scopeSystem($query)
    {
        return $query->where('is_system', true);
    }

    /**
     * Scope untuk query tag custom saja (bukan sistem).
     */
    public function scopeCustom($query)
    {
        return $query->where('is_system', false);
    }

    /**
     * Scope untuk query tag untuk user spesifik.
     */
    public function scopeForUser($query, int|User $user)
    {
        $userId = $user instanceof User ? $user->id : $user;

        return $query->where('user_id', $userId);
    }
}
