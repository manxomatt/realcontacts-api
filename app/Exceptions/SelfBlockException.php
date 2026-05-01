<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * Dilempar saat user mencoba memblokir nomor mereka sendiri.
 * Pemblokiran nomor sendiri tidak diperbolehkan secara semantik.
 */
class SelfBlockException extends Exception
{
    public function __construct(
        string $message = 'Anda tidak dapat memblokir nomor telepon Anda sendiri.',
    ) {
        parent::__construct($message, 422);
    }

    public function getStatusCode(): int
    {
        return 422;
    }
}
