<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * Dilempar saat nomor yang ingin dilaporkan masuk dalam daftar
 * whitelist (nomor resmi, hotline darurat, dsb.) yang tidak boleh
 * dilaporkan sebagai spam.
 */
class NumberWhitelistedException extends Exception
{
    public function __construct(
        string $message = 'Nomor ini tidak dapat dilaporkan karena merupakan nomor resmi atau terverifikasi.',
        private readonly string $whitelistedNumber = '',
    ) {
        parent::__construct($message, 422);
    }

    public function getWhitelistedNumber(): string
    {
        return $this->whitelistedNumber;
    }

    public function getStatusCode(): int
    {
        return 422;
    }
}
