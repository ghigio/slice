<?php

namespace App\Entity;

use App\Text\NameNormalizer;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ingrediente di una pizzeria, assegnabile alle pizze del suo menu.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ingredients')]
#[ORM\UniqueConstraint(name: 'uniq_ingredients_pizzeria_name', columns: ['pizzeria_id', 'normalized_name'])]
#[ORM\HasLifecycleCallbacks]
class Ingredient
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Pizzeria::class, inversedBy: 'ingredients')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Pizzeria $pizzeria;

    #[ORM\Column(length: 60)]
    private string $name;

    #[ORM\Column(length: 60)]
    private string $normalizedName;

    public function __construct(Pizzeria $pizzeria, string $name)
    {
        $this->pizzeria = $pizzeria;
        $this->setName($name);
        $pizzeria->addIngredient($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPizzeria(): Pizzeria
    {
        return $this->pizzeria;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = NameNormalizer::clean($name);
        $this->normalizedName = NameNormalizer::normalize($name);
    }

    public function getNormalizedName(): string
    {
        return $this->normalizedName;
    }
}
