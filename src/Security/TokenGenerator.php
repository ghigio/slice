<?php

namespace App\Security;

final class TokenGenerator
{
    /** Token crittograficamente sicuro di 64 caratteri esadecimali. */
    public static function generate(): string
    {
        return bin2hex(random_bytes(32));
    }
}
