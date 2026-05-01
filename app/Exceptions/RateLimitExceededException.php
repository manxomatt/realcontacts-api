<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * Dilempar saat user gratis melebihi batas lookup harian.
 * HTTP 429 Too Many Requests dengan informasi upgrade Premium.
 */
class RateLimitExceededException extends Exception
{
    public function __construct(
        string $message = 'Batas penggunaan harian tercapai.',
        private readonly string $limitType = 'daily_lookup',
        private readonly int    $retryAfter = 86_400,   // 24 jam dalam detik
    ) {
        parent::__construct($message, 429);
    }

    public function getLimitType(): string
    {
        return $this->limitType;
    }

    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }

    public function getStatusCode(): int
    {
        return 429;
    }
}
