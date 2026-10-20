<?php

namespace App\Discount\Exception;

/** In modalità «uguale» una pizza andrebbe a un prezzo negativo: suggerire la modalità proporzionale. */
final class NegativeDiscountedPriceException extends \DomainException
{
    public function __construct(
        public readonly int|string $pizzaKey,
        public readonly int $listPriceCents,
        public readonly int $discountedPriceCents,
    ) {
        parent::__construct(sprintf('La pizza %s da %d centesimi andrebbe a %d centesimi.', $pizzaKey, $listPriceCents, $discountedPriceCents));
    }
}
