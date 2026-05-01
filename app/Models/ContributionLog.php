<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContributionLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'points',
        'reason',
        'total_after',
    ];

    protected function casts(): array
    {
        return [
            'points'      => 'integer',
            'total_after' => 'integer',
        ];
    }

    // ─── Relasi ───────────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
