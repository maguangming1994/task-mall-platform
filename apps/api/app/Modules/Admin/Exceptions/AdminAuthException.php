<?php

namespace App\Modules\Admin\Exceptions;

use RuntimeException;

class AdminAuthException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }
}
