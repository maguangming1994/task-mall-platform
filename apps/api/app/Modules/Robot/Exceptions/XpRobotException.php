<?php

namespace App\Modules\Robot\Exceptions;

use RuntimeException;
use Throwable;

class XpRobotException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly bool $indeterminate = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function indeterminate(): bool
    {
        return $this->indeterminate;
    }
}
