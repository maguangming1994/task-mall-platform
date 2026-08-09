<?php

namespace App\Modules\Legal\Exceptions;

use RuntimeException;

class LegalException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
        public readonly ?array $details = null,
    ) {
        parent::__construct($message);
    }
}
