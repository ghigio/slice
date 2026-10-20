<?php

namespace App\Discount;

use App\Enum\DiscountMode;

/**
 * Esito del calcolo dello sconto; tutti gli importi sono in centesimi.
 */
final readonly class DiscountResult
{
    /**
     * @param array<array-key, int> $discountedPrices prezzi scontati, con le stesse chiavi dei prezzi di listino
     */
    public function __construct(
        public array $discountedPrices,
        public int $listTotalCents,
        public int $paidTotalCents,
        public int $discountCents,
        public int $discountedTotalCents,
        public int $roundingDifferenceCents,
        public DiscountMode $mode,
    ) {
    }
}
