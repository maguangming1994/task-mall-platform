<?php

namespace App\Modules\Payment\Contracts;

use App\Models\User;
use App\Modules\Payment\Models\Payment;

interface WechatPaymentGateway
{
    /** @return array<string, mixed> */
    public function prepare(Payment $payment, User $user): array;

    /** @param array<string,string|null> $headers
     * @return array<string,mixed>
     */
    public function parseNotification(array $headers, string $body): array;

    /** @return array<string,mixed> */
    public function query(Payment $payment): array;

    public function close(Payment $payment): void;
}
