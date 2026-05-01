<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

// Field sensitif tersembunyi di seluruh response — id tidak di-hidden di sini
// karena dibutuhkan untuk relasi, tetapi TIDAK boleh di-expose di UserResource
#[Fillable(['name', 'email', 'phone_number', 'password', 'badge_level', 'contribution_points', 'is_premium', 'is_admin', 'premium_expires_at', 'notification_preferences'])]
#[Hidden(['password', 'remember_token', 'email'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    // Konversi tipe data kolom secara otomatis
    protected function casts(): array
    {
        return [
            'email_verified_at'   => 'datetime',
            'premium_expires_at'  => 'datetime',
            'password'            => 'hashed',
            'is_premium'               => 'boolean',
            'is_admin'                 => 'boolean',
            'contribution_points'      => 'integer',
            'notification_preferences' => 'array',
        ];
    }

    // ──────────────────────────────────────────────
    // Relasi
    // ──────────────────────────────────────────────

    // Satu user bisa memiliki banyak laporan spam
    public function spamReports(): HasMany
    {
        return $this->hasMany(SpamReport::class);
    }

    // ──────────────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────────────

    // Filter hanya user dengan akun premium aktif
    #[Scope]
    protected function premium(Builder $query): void
    {
        $query->where('is_premium', true)
              ->where(fn (Builder $q) => $q
                  ->whereNull('premium_expires_at')
                  ->orWhere('premium_expires_at', '>', now())
              );
    }
}
