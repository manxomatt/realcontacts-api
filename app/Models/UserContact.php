<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * UserContact Model
 *
 * Merepresentasikan kontak yang disimpan oleh user.
 * Setiap user bisa punya banyak kontak dengan nomor berbeda.
 *
 * Relasi:
 *   - Belongs to User
 *   - Belongs to Many ContactTags
 */
class UserContact extends Model
{
    use HasFactory;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'phone_number',
        'custom_name',
        'notes',
        'is_favorite',
        'contact_source',
        'last_interacted_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'is_favorite'         => 'boolean',
        'last_interacted_at'  => 'datetime',
        'created_at'          => 'datetime',
        'updated_at'          => 'datetime',
    ];

    // ─── Relationships ─────────────────────────────────────────────────────

    /**
     * User yang memiliki kontak ini.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Tag yang diterapkan ke kontak ini.
     *
     * @return BelongsToMany<ContactTag>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(
            ContactTag::class,
            'contact_tag_user_contact',
            'user_contact_id',
            'contact_tag_id',
        )
            ->withTimestamps('tagged_at');
    }
}
