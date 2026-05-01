<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * OtpBlacklistedException
 *
 * Dilempar ketika nomor telepon sedang dalam masa blacklist akibat
 * terlalu banyak percobaan verifikasi OTP yang gagal.
 */
class OtpBlacklistedException extends Exception
{
    public function __construct(
        string $message = 'Terlalu banyak percobaan. Coba lagi dalam 15 menit.',
        private readonly int $retryAfter = 900,
    ) {
        parent::__construct($message, 429);
    }

    /** Sisa waktu tunggu dalam detik */
    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }
}
