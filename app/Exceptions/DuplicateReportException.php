<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * Dilempar saat user mencoba melaporkan nomor yang sudah pernah
 * dilaporkan dalam 24 jam terakhir (cek via hasDuplicateReport()).
 */
class DuplicateReportException extends Exception
{
    public function __construct(
        string $message = 'Anda sudah melaporkan nomor ini dalam 24 jam terakhir.',
    ) {
        parent::__construct($message, 422);
    }

    public function getStatusCode(): int
    {
        return 422;
    }
}
