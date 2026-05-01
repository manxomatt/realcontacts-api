<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpamReportVote extends Model
{
    protected $fillable = [
        'spam_report_id',
        'user_id',
        'type',
    ];

    // ─── Relasi ───────────────────────────────────────────────────────────────

    public function spamReport(): BelongsTo
    {
        return $this->belongsTo(SpamReport::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
