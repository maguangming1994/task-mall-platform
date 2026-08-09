<?php

namespace App\Modules\Settings\Exceptions;

use RuntimeException;

class SettingException extends RuntimeException
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
