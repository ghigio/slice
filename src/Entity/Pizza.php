<?php

namespace App\Entity;

use App\Repository\PizzaRepository;
use App\Text\NameNormalizer;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Una pizza ordinata da una persona di un gruppo; prezzo di listino in centesimi, facoltativo finché non si inserisce il conto.
 */
#[ORM\Entity(repositoryClass: PizzaRepository::class)]
#[ORM\Table(name: 'pizzas')]
#[ORM\Index(name: 'idx_pizzas_normalized_name', columns: ['normalized_name'])]
#[ORM\HasLifecycleCallbacks]
class Pizza
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Eater::class, inversedBy: 'pizzas')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Eater $eater;

    #[ORM\Column(length: 60)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 60)]
    private string $person;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 80)]
    private string $pizzaName;

    #[ORM\Column(length: 80)]
    private string $normalizedName;

    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 1, max: 10000)]
    private ?int $priceCents = null;

    public function __construct(Eater $eater, string $person, string $pizzaName, ?int $priceCents)
    {
        $this->eater = $eater;
        $this->setPerson($person);
        $this->setPizzaName($pizzaName);
        $this->setPriceCents($priceCents);
        $eater->addPizza($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEater(): Eater
    {
        return $this->eater;
    }

    public function getPerson(): string
    {
        return $this->person;
    }

    public function setPerson(string $person): void
    {
        $this->person = NameNormalizer::clean($person);
    }

    public function getPizzaName(): string
    {
        return $this->pizzaName;
    }

    /** Salva il nome come digitato (ripulito dagli spazi) e la chiave normalizzata per l'aggregazione. */
    public function setPizzaName(string $pizzaName): void
    {
        $this->pizzaName = NameNormalizer::clean($pizzaName);
        $this->normalizedName = NameNormalizer::normalize($pizzaName);
    }

    public function getNormalizedName(): string
    {
        return $this->normalizedName;
    }

    public function getPriceCents(): ?int
    {
        return $this->priceCents;
    }

    public function hasPrice(): bool
    {
        return null !== $this->priceCents;
    }

    public function setPriceCents(?int $priceCents): void
    {
        $this->priceCents = $priceCents;
    }
}
