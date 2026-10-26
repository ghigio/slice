<?php

namespace App\Money;

/**
 * Converte il prezzo digitato (virgola o punto come separatore decimale) in centesimi, senza passare da float.
 */
final class PriceParser
{
    public const int MIN_CENTS = 1;
    public const int MAX_CENTS = 10000;

    public static function parseCents(string $input): int
    {
        if (!preg_match('/^\s*(\d{1,6})(?:[.,](\d{1,2}))?\s*€?\s*$/u', $input, $matches)) {
            throw new InvalidPriceException('Inserisci un prezzo valido, per esempio 7,50.');
        }

        $cents = (int) $matches[1] * 100 + (int) str_pad($matches[2] ?? '0', 2, '0');

        if ($cents < self::MIN_CENTS || $cents > self::MAX_CENTS) {
            throw new InvalidPriceException('Il prezzo deve essere tra 0,01 € e 100,00 €.');
        }

        return $cents;
    }
}
