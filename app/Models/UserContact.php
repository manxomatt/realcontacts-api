<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Cast;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * UserContact Model
 *
 * Merepresentasikan kontak yang disimpan oleh user.
 * Setiap user bisa punya banyak kontak dengan nomor telepon berbeda.
 *
 * Relasi:
 *   - Belongs to User
 *   - Belongs to PhoneNumber (via phone_number string, bukan FK)
 *   - Belongs to Many ContactTags (via pivot table)
 */
#[Table('user_contacts')]
#[Fillable([
    'user_id',
    'phone_number',
    'custom_name',
    'notes',
    'is_favorite',
    'contact_source',
    'last_interacted_at',
])]
#[Cast([
    'is_favorite'        => 'boolean',
    'last_interacted_at' => 'datetime',
    'contact_source'     => 'string',
    'created_at'         => 'datetime',
    'updated_at'         => 'datetime',
])]
class UserContact extends Model
{
    use HasFactory;

    // ─── Relationships ─────────────────────────────────────────────────────

    /**
     * User yang memiliki kontak ini.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * PhoneNumber berdasarkan nomor telepon (string lookup).
     * Bukan FK relationship, tapi lookup via phone_number.
     */
    public function phoneNumber(): BelongsTo
    {
        return $this->belongsTo(PhoneNumber::class, 'phone_number', 'phone_number');
    }

    /**
     * Tag-tag yang diterapkan ke kontak ini.
     * Relasi many-to-many via contact_tag_user_contact.
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
            ->withPivot('tagged_at');
    }

    // ─── Accessors ────────────────────────────────────────────────────────

    /**
     * Get display name untuk kontak ini.
     *
     * Priority:
     *   1. custom_name (jika user memberikan nama custom)
     *   2. owner_name dari PhoneNumber (jika terdaftar sebagai bisnis)
     *   3. phone_number (masked format: +6281****7890)
     *
     * @return string
     */
    public function getDisplayNameAttribute(): string
    {
        // Jika ada custom name dari user, gunakan itu
        if ($this->custom_name) {
            return $this->custom_name;
        }

        // Coba ambil owner_name dari phone number
        if ($this->phoneNumber && $this->phoneNumber->owner_name) {
            return $this->phoneNumber->owner_name;
        }

        // Fallback ke nomor telepon yang di-mask
        return $this->getMaskedPhoneNumber();
    }

    /**
     * Format nomor telepon dengan mask untuk privacy.
     * Format: +6281****7890 (show first 5 & last 4 chars).
     *
     * @return string
     */
    private function getMaskedPhoneNumber(): string
    {
        $phone = $this->phone_number;

        if (strlen($phone) < 10) {
            return $phone;
        }

        // Mask tengah: show first 5 & last 4
        $first = substr($phone, 0, 5);
        $last = substr($phone, -4);
        $masked = str_repeat('*', strlen($phone) - 9);

        return $first . $masked . $last;
    }

    // ─── Scopes ───────────────────────────────────────────────────────────

    /**
     * Filter kontak favorit saja.
     *
     * Usage: UserContact::favorites()->get();
     */
    public function scopeFavorites(Builder $query): Builder
    {
        return $query->where('is_favorite', true);
    }

    /**
     * Filter kontak berdasarkan tag tertentu.
     *
     * Usage: UserContact::byTag($tagId)->get();
     */
    public function scopeByTag(Builder $query, int $tagId): Builder
    {
        return $query->whereHas('tags', fn ($q) => $q->where('contact_tags.id', $tagId));
    }

    /**
     * Sort kontak berdasarkan interaksi terakhir (DESC).
     *
     * Usage: UserContact::recentlyInteracted()->get();
     */
    public function scopeRecentlyInteracted(Builder $query): Builder
    {
        return $query->whereNotNull('last_interacted_at')
            ->orderByDesc('last_interacted_at');
    }

    /**
     * Search kontak berdasarkan custom_name atau phone_number.
     *
     * Usage: UserContact::search('pizza')->get();
     */
    public function scopeSearch(Builder $query, string $searchQuery): Builder
    {
        return $query->where(function (Builder $q) use ($searchQuery): void {
            $q->where('custom_name', 'like', "%{$searchQuery}%")
                ->orWhere('phone_number', 'like', "%{$searchQuery}%");
        });
    }

    /**
     * Scope untuk user tertentu.
     *
     * Usage: UserContact::forUser($user)->get();
     */
    public function scopeForUser(Builder $query, int|User $user): Builder
    {
        $userId = $user instanceof User ? $user->id : $user;

        return $query->where('user_id', $userId);
    }

    // ─── Methods ───────────────────────────────────────────────────────────

    /**
     * Cari atau buat user contact untuk user dan nomor tertentu.
     *
     * Jika sudah ada UserContact dengan user_id & phone_number yang sama,
     * return yang existing. Jika tidak ada, create baru dengan default values.
     *
     * @param User $user User pemilik kontak
     * @param string $phoneNumber Nomor telepon kontak
     * @return self User contact yang ditemukan atau dibuat
     */
    public static function findOrCreateForUser(User $user, string $phoneNumber): self
    {
        return self::firstOrCreate(
            [
                'user_id'      => $user->id,
                'phone_number' => $phoneNumber,
            ],
            [
                'is_favorite'     => false,
                'contact_source'  => 'manual',
                'last_interacted_at' => null,
            ],
        );
    }

    /**
     * Update waktu interaksi terakhir ke sekarang.
     *
     * Biasanya dipanggil saat ada call log atau SMS dari nomor ini.
     */
    public function recordInteraction(): void
    {
        $this->update(['last_interacted_at' => now()]);
    }

    /**
     * Toggle favorite status kontak.
     */
    public function toggleFavorite(): void
    {
        $this->update(['is_favorite' => !$this->is_favorite]);
    }
}
