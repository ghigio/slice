<?php

namespace App\Entity;

use App\Repository\PizzeriaRepository;
use App\Text\NameNormalizer;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Pizzeria nota, con i suoi ingredienti e il menu; gestita dall'area protetta /gestione.
 */
#[ORM\Entity(repositoryClass: PizzeriaRepository::class)]
#[ORM\Table(name: 'pizzerias')]
#[ORM\HasLifecycleCallbacks]
class Pizzeria
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $name;

    /** Unico nell'archivio: nomi uguali senza distinzione di maiuscole, accenti e spazi. */
    #[ORM\Column(length: 100, unique: true)]
    private string $normalizedName;

    /** @var Collection<int, Ingredient> */
    #[ORM\OneToMany(targetEntity: Ingredient::class, mappedBy: 'pizzeria', cascade: ['persist'])]
    #[ORM\OrderBy(['normalizedName' => 'ASC'])]
    private Collection $ingredients;

    /** @var Collection<int, MenuPizza> */
    #[ORM\OneToMany(targetEntity: MenuPizza::class, mappedBy: 'pizzeria', cascade: ['persist'])]
    #[ORM\OrderBy(['normalizedName' => 'ASC'])]
    private Collection $menuPizzas;

    public function __construct(string $name)
    {
        $this->ingredients = new ArrayCollection();
        $this->menuPizzas = new ArrayCollection();
        $this->setName($name);
    }

    public function getId(): ?int
    {
        return $this->id;
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

    /** @return Collection<int, Ingredient> */
    public function getIngredients(): Collection
    {
        return $this->ingredients;
    }

    /** @return Collection<int, MenuPizza> */
    public function getMenuPizzas(): Collection
    {
        return $this->menuPizzas;
    }

    /** @internal Chiamato dal costruttore di Ingredient. */
    public function addIngredient(Ingredient $ingredient): void
    {
        if (!$this->ingredients->contains($ingredient)) {
            $this->ingredients->add($ingredient);
        }
    }

    public function removeIngredient(Ingredient $ingredient): void
    {
        $this->ingredients->removeElement($ingredient);
        foreach ($this->menuPizzas as $pizza) {
            $pizza->getIngredients()->removeElement($ingredient);
        }
    }

    /** @internal Chiamato dal costruttore di MenuPizza. */
    public function addMenuPizza(MenuPizza $pizza): void
    {
        if (!$this->menuPizzas->contains($pizza)) {
            $this->menuPizzas->add($pizza);
        }
    }

    public function removeMenuPizza(MenuPizza $pizza): void
    {
        $this->menuPizzas->removeElement($pizza);
    }
}
