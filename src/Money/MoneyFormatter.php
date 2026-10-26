<?php

namespace App\Money;

/**
 * Importi in centesimi nel formato italiano «1.234,56 €», solo aritmetica intera.
 */
final class MoneyFormatter
{
    public static function format(int $cents): string
    {
        return self::formatInput($cents).' €';
    }

    /** Senza simbolo, adatto al valore di un campo di input: «7,50». */
    public static function formatInput(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return sprintf('%s%s,%02d', $sign, number_format(intdiv($cents, 100), 0, ',', '.'), $cents % 100);
    }
}
