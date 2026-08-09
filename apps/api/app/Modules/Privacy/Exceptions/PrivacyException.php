<?php

namespace App\Modules\Privacy\Exceptions;

use RuntimeException;

class PrivacyException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status, public readonly ?array $details = null)
    {
        parent::__construct($message);
    }
}
