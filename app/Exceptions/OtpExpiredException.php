<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * OtpExpiredException
 *
 * Dilempar ketika OTP tidak ditemukan di Redis (sudah expired atau belum di-generate).
 */
class OtpExpiredException extends Exception
{
    public function __construct(string $message = 'Kode OTP sudah kedaluwarsa atau tidak valid.')
    {
        parent::__construct($message, 422);
    }
}
