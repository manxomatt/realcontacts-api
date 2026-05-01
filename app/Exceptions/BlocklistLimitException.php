<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * Dilempar saat user sudah mencapai batas maksimal nomor yang dapat diblokir (500).
 * User perlu upgrade ke Premium atau hapus blokir lama untuk menambah baru.
 */
class BlocklistLimitException extends Exception
{
    public function __construct(
        string $message = 'Anda sudah mencapai batas maksimal nomor yang dapat diblokir.',
        private readonly int $limitReached = 500,
    ) {
        parent::__construct($message, 422);
    }

    public function getLimitReached(): int
    {
        return $this->limitReached;
    }

    public function getStatusCode(): int
    {
        return 422;
    }
}
