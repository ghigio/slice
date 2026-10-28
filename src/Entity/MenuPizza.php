<?php

namespace App\Entity;

use App\Text\NameNormalizer;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Pizza del menu di una pizzeria, con i suoi ingredienti e un prezzo facoltativo.
 * Le pizze ordinate ne copiano solo il nome: modificare il menu non tocca le serate.
 */
#[ORM\Entity]
#[ORM\Table(name: 'menu_pizzas')]
#[ORM\UniqueConstraint(name: 'uniq_menu_pizzas_pizzeria_name', columns: ['pizzeria_id', 'normalized_name'])]
#[ORM\HasLifecycleCallbacks]
class MenuPizza
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Pizzeria::class, inversedBy: 'menuPizzas')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Pizzeria $pizzeria;

    #[ORM\Column(length: 80)]
    private string $name;

    #[ORM\Column(length: 80)]
    private string $normalizedName;

    /** Tra 1 e 10.000 centesimi; null = prezzo non indicato. */
    #[ORM\Column(nullable: true)]
    private ?int $priceCents;

    /** @var Collection<int, Ingredient> */
    #[ORM\ManyToMany(targetEntity: Ingredient::class)]
    #[ORM\JoinTable(name: 'menu_pizza_ingredients')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(onDelete: 'CASCADE')]
    #[ORM\OrderBy(['normalizedName' => 'ASC'])]
    private Collection $ingredients;

    public function __construct(Pizzeria $pizzeria, string $name, ?int $priceCents)
    {
        $this->pizzeria = $pizzeria;
        $this->ingredients = new ArrayCollection();
        $this->setName($name);
        $this->priceCents = $priceCents;
        $pizzeria->addMenuPizza($this);
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

    public function getPriceCents(): ?int
    {
        return $this->priceCents;
    }

    public function setPriceCents(?int $priceCents): void
    {
        $this->priceCents = $priceCents;
    }

    /** @return Collection<int, Ingredient> */
    public function getIngredients(): Collection
    {
        return $this->ingredients;
    }

    /** @param iterable<Ingredient> $ingredients sostituiscono quelli attuali */
    public function setIngredients(iterable $ingredients): void
    {
        $this->ingredients->clear();
        foreach ($ingredients as $ingredient) {
            if (!$this->ingredients->contains($ingredient)) {
                $this->ingredients->add($ingredient);
            }
        }
    }
}
