<?php

namespace App\Discount;

use App\Discount\Exception\EmptyOrderException;
use App\Discount\Exception\NegativeDiscountedPriceException;
use App\Enum\DiscountMode;

/**
 * Ridistribuisce sulle pizze lo sconto (o la maggiorazione) tra totale da listino e totale pagato.
 * Solo aritmetica intera; arrotondamento half up verso +∞. Vedi «Algoritmo dello sconto» in docs/REQUISITI.md.
 */
final class DiscountCalculator
{
    /**
     * @param array<array-key, int> $listPrices prezzi di listino in centesimi, chiavi preservate nel risultato
     *
     * @throws EmptyOrderException              se non ci sono pizze
     * @throws NegativeDiscountedPriceException in modalità uguale, se una pizza andrebbe sotto zero
     */
    public function calculate(array $listPrices, int $paidTotalCents, DiscountMode $mode): DiscountResult
    {
        if ([] === $listPrices) {
            throw new EmptyOrderException('Nessuna pizza: il conto non si può inserire.');
        }
        if ($paidTotalCents <= 0) {
            throw new \InvalidArgumentException('Il totale pagato deve essere positivo.');
        }
        foreach ($listPrices as $price) {
            if ($price <= 0) {
                throw new \InvalidArgumentException('I prezzi di listino devono essere positivi.');
            }
        }

        $listTotal = array_sum($listPrices);
        $discount = $listTotal - $paidTotalCents;
        $count = count($listPrices);

        $discounted = [];
        foreach ($listPrices as $key => $price) {
            $discounted[$key] = match ($mode) {
                // round(p · T / S)
                DiscountMode::Proportional => self::roundHalfUp($price * $paidTotalCents, $listTotal),
                // round((n · p − D) / n)
                DiscountMode::Equal => self::roundHalfUp($count * $price - $discount, $count),
            };

            if ($discounted[$key] < 0) {
                throw new NegativeDiscountedPriceException($key, $price, $discounted[$key]);
            }
        }

        $discountedTotal = array_sum($discounted);

        return new DiscountResult(
            discountedPrices: $discounted,
            listTotalCents: $listTotal,
            paidTotalCents: $paidTotalCents,
            discountCents: $discount,
            discountedTotalCents: $discountedTotal,
            roundingDifferenceCents: $discountedTotal - $paidTotalCents,
            mode: $mode,
        );
    }

    /** floor(numerator / denominator + 1/2) con denominatore positivo: intdiv(2·num + den, 2·den) arrotondato verso −∞. */
    private static function roundHalfUp(int $numerator, int $denominator): int
    {
        $dividend = 2 * $numerator + $denominator;
        $divisor = 2 * $denominator;
        $quotient = intdiv($dividend, $divisor);

        return ($dividend % $divisor < 0) ? $quotient - 1 : $quotient;
    }
}
