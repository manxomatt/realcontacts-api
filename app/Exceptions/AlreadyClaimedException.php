<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * Dilempar saat user mencoba mengklaim profil bisnis yang sudah
 * diklaim oleh user lain sebelumnya.
 */
class AlreadyClaimedException extends Exception
{
    public function __construct(
        string $message = 'Profil bisnis ini sudah diklaim oleh user lain.',
    ) {
        parent::__construct($message, 422);
    }

    public function getStatusCode(): int
    {
        return 422;
    }
}
