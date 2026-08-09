<?php

namespace App\Modules\Auth\Services;

class IdentityHasher
{
    public function hash(string $value): string
    {
        return hash_hmac(
            'sha256',
            $value,
            hash('sha256', (string) config('auth_tokens.identity_hash_secret')),
        );
    }
}
