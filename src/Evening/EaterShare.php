<?php

namespace App\Evening;

use App\Entity\Eater;

/**
 * Quota di un gruppo dopo lo sconto; importi in centesimi.
 */
final readonly class EaterShare
{
    public function __construct(
        public Eater $eater,
        public int $pizzaCount,
        public int $listCents,
        public int $shareCents,
        /** Da rimborsare all'organizzatore: zero per il suo gruppo. */
        public int $dueCents,
        public bool $isOrganizer,
    ) {
    }

    public function owesNothing(): bool
    {
        return 0 === $this->dueCents;
    }

    public function isReimbursed(): bool
    {
        return null !== $this->eater->getReimbursedAt();
    }
}
