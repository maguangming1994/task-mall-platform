<?php

namespace App\Common\Exceptions;

use RuntimeException;

class IdempotencyConflictException extends RuntimeException
{
    public const ERROR_CODE = 'IDEMPOTENCY_KEY_REUSED';

    public const STATUS = 409;

    public function __construct(string $message = '相同幂等键对应不同请求，请更换幂等键。')
    {
        parent::__construct($message);
    }
}
