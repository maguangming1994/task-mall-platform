<?php

namespace App\Modules\Notification\Exceptions;

use RuntimeException;

class PermanentNotificationDeliveryException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
