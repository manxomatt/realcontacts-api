<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * Dilempar saat format nomor telepon tidak valid
 * setelah normalisasi (bukan sekadar format, tapi secara semantik tidak bisa diproses).
 */
class InvalidPhoneNumberException extends Exception
{
    public function __construct(
        string $message = 'Format nomor telepon tidak valid.',
        private readonly string $invalidNumber = '',
    ) {
        parent::__construct($message, 422);
    }

    public function getInvalidNumber(): string
    {
        return $this->invalidNumber;
    }

    public function getStatusCode(): int
    {
        return 422;
    }
}
