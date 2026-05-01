<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
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
#[Table('contact_tags')]
#[Fillable([
    'user_id',
    'name',
    'color',
    'icon',
    'is_system',
    'usage_count',
])]
class ContactTag extends Model
{
    use HasFactory;

    /**
     * Tipe data cast untuk kolom model.
     *
     * @var array
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
            ->withPivot('tagged_at');
    }

    // ─── Scopes ────────────────────────────────────────────────────────────

    /**
     * Scope untuk query tag sistem saja.
     *
     * Usage: ContactTag::system()->get();
     */
    public function scopeSystem(Builder $query): Builder
    {
        return $query->where('is_system', true);
    }

    /**
     * Scope untuk query tag custom saja (bukan sistem).
     *
     * Usage: ContactTag::custom()->get();
     */
    public function scopeCustom(Builder $query): Builder
    {
        return $query->where('is_system', false);
    }

    /**
     * Scope untuk query tag untuk user spesifik.
     *
     * Usage: ContactTag::byUser($user)->get();
     */
    public function scopeByUser(Builder $query, int|User $user): Builder
    {
        $userId = $user instanceof User ? $user->id : $user;

        return $query->where('user_id', $userId);
    }

    // ─── Methods ───────────────────────────────────────────────────────────

    /**
     * Increment usage count untuk tag ini.
     *
     * Biasanya dipanggil saat menambah tag ke kontak.
     */
    public function incrementUsage(): void
    {
        $this->increment('usage_count');
    }

    /**
     * Decrement usage count untuk tag ini (minimum 0).
     *
     * Biasanya dipanggil saat menghapus tag dari kontak.
     */
    public function decrementUsage(): void
    {
        // Ensure usage_count tidak jadi negatif
        if ($this->usage_count > 0) {
            $this->decrement('usage_count');
        }
    }

    /**
     * Override delete() untuk prevent penghapusan system tags.
     *
     * @throws \Exception Jika mencoba hapus system tag
     * @return bool
     */
    public function delete(): bool|null
    {
        // Jika tag ini adalah system tag, throw exception
        if ($this->is_system) {
            throw new \Exception(
                "Tidak dapat menghapus tag sistem '{$this->name}'. "
                . "Tag sistem tidak bisa dihapus."
            );
        }

        // Jika bukan system tag, lanjut dengan delete normal
        return parent::delete();
    }
}
