<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * Dilempar saat admin mencoba approve atau reject laporan
 * yang sudah diproses sebelumnya (status bukan 'pending').
 */
class AlreadyModeratedException extends Exception
{
    public function __construct(
        string $message = 'Laporan ini sudah diproses sebelumnya.',
    ) {
        parent::__construct($message, 422);
    }

    public function getStatusCode(): int
    {
        return 422;
    }
}
