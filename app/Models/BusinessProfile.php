<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BusinessProfile extends Model
{
    /** @use HasFactory<\Database\Factories\BusinessProfileFactory> */
    use HasFactory;

    // Kolom yang boleh diisi secara massal
    protected $fillable = [
        'phone_number_id',
        'business_name',
        'category',
        'description',
        'website',
        'email',
        'address',
        'city',
        'province',
        'is_verified',
        'verified_at',
        'operating_hours',
        'rating',
        'maps_url',
        'is_claimed',
        'claimed_by_user_id',
    ];

    // Konversi tipe data kolom secara otomatis
    protected $casts = [
        'is_verified'     => 'boolean',
        'is_claimed'      => 'boolean',
        'verified_at'     => 'datetime',
        'operating_hours' => 'array',  // JSON → PHP array otomatis
        'rating'          => 'float',
    ];

    // ──────────────────────────────────────────────
    // Relasi
    // ──────────────────────────────────────────────

    // Profil bisnis dimiliki oleh satu nomor telepon
    public function phoneNumber(): BelongsTo
    {
        return $this->belongsTo(PhoneNumber::class);
    }

    // User yang mengklaim profil bisnis ini
    public function claimedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by_user_id');
    }

    // ──────────────────────────────────────────────
    // Accessor
    // ──────────────────────────────────────────────

    // Menggabungkan kota dan provinsi menjadi lokasi lengkap
    protected function location(): Attribute
    {
        return Attribute::make(
            get: fn (): string => implode(', ', array_filter([
                $this->city,
                $this->province,
            ]))
        );
    }

    // ──────────────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────────────

    // Filter hanya profil bisnis yang sudah terverifikasi
    #[Scope]
    protected function verified(Builder $query): void
    {
        $query->where('is_verified', true);
    }

    // Filter profil bisnis berdasarkan kategori
    #[Scope]
    protected function byCategory(Builder $query, string $category): void
    {
        $query->where('category', $category);
    }

    // Filter profil bisnis berdasarkan kota
    #[Scope]
    protected function byCity(Builder $query, string $city): void
    {
        $query->where('city', $city);
    }
}

