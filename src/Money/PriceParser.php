<?php

namespace App\Money;

/**
 * Converte il prezzo digitato (virgola o punto come separatore decimale) in centesimi, senza passare da float.
 */
final class PriceParser
{
    public const int MIN_CENTS = 1;
    public const int MAX_CENTS = 10000;
    public const int MAX_TOTAL_CENTS = 5000000;

    public static function parseCents(string $input): int
    {
        $cents = self::parseAmount($input);

        if ($cents < self::MIN_CENTS || $cents > self::MAX_CENTS) {
            throw new InvalidPriceException('Il prezzo deve essere tra 0,01 € e 100,00 €.');
        }

        return $cents;
    }

    /** Prezzo facoltativo: campo vuoto → null. */
    public static function parseOptionalCents(string $input): ?int
    {
        return '' === trim($input) ? null : self::parseCents($input);
    }

    /** Totale pagato dello scontrino: da 0,01 € a 50.000,00 €. */
    public static function parseTotalCents(string $input): int
    {
        try {
            $cents = self::parseAmount($input);
        } catch (InvalidPriceException) {
            throw new InvalidPriceException('Inserisci un importo valido, per esempio 30,00.');
        }

        if ($cents < self::MIN_CENTS || $cents > self::MAX_TOTAL_CENTS) {
            throw new InvalidPriceException('Il totale pagato deve essere tra 0,01 € e 50.000,00 €.');
        }

        return $cents;
    }

    private static function parseAmount(string $input): int
    {
        if (!preg_match('/^\s*(\d{1,6})(?:[.,](\d{1,2}))?\s*€?\s*$/u', $input, $matches)) {
            throw new InvalidPriceException('Inserisci un prezzo valido, per esempio 7,50.');
        }

        return (int) $matches[1] * 100 + (int) str_pad($matches[2] ?? '0', 2, '0');
    }
}
