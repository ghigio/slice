<?php

namespace App\Form\Data;

use App\Entity\Pizzeria;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Dati del form di creazione della serata.
 */
final class NewEveningData
{
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 255)]
    public ?string $name = null;

    #[Assert\NotNull]
    public ?\DateTimeImmutable $date = null;

    /** Pizzeria nota scelta dall'elenco; null = «Altra pizzeria», con il nome libero in $pizzeria. */
    public ?Pizzeria $knownPizzeria = null;

    #[Assert\Length(max: 255)]
    public ?string $pizzeria = null;

    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 255)]
    public ?string $organizerName = null;

    #[Assert\Length(max: 1000)]
    public ?string $refundNotes = null;

    public function __construct()
    {
        $this->date = new \DateTimeImmutable('today');
    }
}
