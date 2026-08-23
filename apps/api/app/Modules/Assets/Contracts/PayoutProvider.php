<?php

namespace App\Modules\Assets\Contracts;

use App\Modules\Assets\Models\WalletWithdrawal;

interface PayoutProvider
{
    /** @return array<string,mixed> */
    public function submit(WalletWithdrawal $withdrawal): array;

    /** @return array<string,mixed> */
    public function query(WalletWithdrawal $withdrawal): array;

    /** @param array<string,string|null> $headers
     * @return array<string,mixed>
     */
    public function parseNotification(array $headers, string $body): array;

    public function name(): string;
}
