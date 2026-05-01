<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * Dilempar saat user mencoba mengklaim profil bisnis yang bukan miliknya
 * atau tidak memiliki otorisasi untuk klaim.
 */
class UnauthorizedBusinessClaimException extends Exception
{
    public function __construct(
        string $message = 'Anda tidak memiliki otorisasi untuk mengklaim profil bisnis ini.',
    ) {
        parent::__construct($message, 403);
    }

    public function getStatusCode(): int
    {
        return 403;
    }
}
