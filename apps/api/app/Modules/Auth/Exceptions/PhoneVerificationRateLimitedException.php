<?php

namespace App\Modules\Auth\Exceptions;

use RuntimeException;

class PhoneVerificationRateLimitedException extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct('Phone verification requests are rate limited.');
    }
}
