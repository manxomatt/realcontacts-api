<?php

namespace App\Models;

use Database\Factories\BlockedNumberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'phone_number_id', 'reason'])]
class BlockedNumber extends Model
{
    /** @use HasFactory<BlockedNumberFactory> */
    use HasFactory;

    // ─────────────────────────────────────────────
    // Relasi
    // ─────────────────────────────────────────────

    // Nomor yang diblokir merujuk ke tabel phone_numbers
    public function phoneNumber(): BelongsTo
    {
        return $this->belongsTo(PhoneNumber::class);
    }

    // User yang melakukan pemblokiran
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
