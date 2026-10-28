<?php

namespace App\Pizzeria;

use App\Entity\Evening;
use App\Entity\Ingredient;
use App\Entity\MenuPizza;
use App\Entity\Pizzeria;
use App\Money\InvalidPriceException;
use App\Money\PriceParser;
use App\Pizzeria\Exception\CatalogRuleException;
use App\Repository\PizzeriaRepository;
use App\Text\NameNormalizer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Archivio delle pizzerie (area /gestione): pizzerie, ingredienti e menu, con nomi unici senza distinzione
 * di maiuscole, accenti e spazi. Gli id ricevuti dal browser sono verificati contro la pizzeria della pagina.
 */
final class PizzeriaCatalog
{
    private const int MAX_PIZZERIA_NAME_LENGTH = 100;
    private const int MAX_INGREDIENT_NAME_LENGTH = 60;
    private const int MAX_PIZZA_NAME_LENGTH = 80;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PizzeriaRepository $pizzerias,
    ) {
    }

    public function addPizzeria(string $name): Pizzeria
    {
        $name = $this->validPizzeriaName($name, null);
        $pizzeria = new Pizzeria($name);
        $this->em->persist($pizzeria);
        $this->em->flush();

        return $pizzeria;
    }

    public function renamePizzeria(Pizzeria $pizzeria, string $name): void
    {
        $pizzeria->setName($this->validPizzeriaName($name, $pizzeria));
        $this->em->flush();
    }

    /** Le serate che la usavano perdono il menu ma conservano il nome della pizzeria. */
    public function deletePizzeria(Pizzeria $pizzeria): void
    {
        $this->em->remove($pizzeria);
        $this->em->flush();
    }

    public function countEvenings(Pizzeria $pizzeria): int
    {
        return $this->em->getRepository(Evening::class)->count(['knownPizzeria' => $pizzeria]);
    }

    public function addIngredient(Pizzeria $pizzeria, string $name): Ingredient
    {
        $ingredient = new Ingredient($pizzeria, $this->validIngredientName($pizzeria, $name, null));
        $this->em->persist($ingredient);
        $this->em->flush();

        return $ingredient;
    }

    public function renameIngredient(Pizzeria $pizzeria, int $ingredientId, string $name): void
    {
        $ingredient = $this->ingredient($pizzeria, $ingredientId);
        $ingredient->setName($this->validIngredientName($pizzeria, $name, $ingredient));
        $this->em->flush();
    }

    /** @return int numero di pizze del menu da cui l'ingrediente viene tolto */
    public function deleteIngredient(Pizzeria $pizzeria, int $ingredientId): int
    {
        $ingredient = $this->ingredient($pizzeria, $ingredientId);
        $pizzas = $this->countPizzasWith($pizzeria, $ingredient);
        $pizzeria->removeIngredient($ingredient);
        $this->em->remove($ingredient);
        $this->em->flush();

        return $pizzas;
    }

    public function countPizzasWith(Pizzeria $pizzeria, Ingredient $ingredient): int
    {
        return \count(array_filter($pizzeria->getMenuPizzas()->toArray(), fn (MenuPizza $p) => $p->getIngredients()->contains($ingredient)));
    }

    /** @param list<int> $ingredientIds */
    public function addMenuPizza(Pizzeria $pizzeria, string $name, string $price, array $ingredientIds): MenuPizza
    {
        [$name, $priceCents, $ingredients] = $this->validMenuPizza($pizzeria, $name, $price, $ingredientIds, null);
        $pizza = new MenuPizza($pizzeria, $name, $priceCents);
        $pizza->setIngredients($ingredients);
        $this->em->persist($pizza);
        $this->em->flush();

        return $pizza;
    }

    /** @param list<int> $ingredientIds */
    public function updateMenuPizza(Pizzeria $pizzeria, int $pizzaId, string $name, string $price, array $ingredientIds): void
    {
        $pizza = $this->menuPizza($pizzeria, $pizzaId);
        [$name, $priceCents, $ingredients] = $this->validMenuPizza($pizzeria, $name, $price, $ingredientIds, $pizza);
        $pizza->setName($name);
        $pizza->setPriceCents($priceCents);
        $pizza->setIngredients($ingredients);
        $this->em->flush();
    }

    public function deleteMenuPizza(Pizzeria $pizzeria, int $pizzaId): void
    {
        $pizza = $this->menuPizza($pizzeria, $pizzaId);
        $pizzeria->removeMenuPizza($pizza);
        $this->em->remove($pizza);
        $this->em->flush();
    }

    private function validPizzeriaName(string $name, ?Pizzeria $current): string
    {
        $name = $this->validName($name, self::MAX_PIZZERIA_NAME_LENGTH, 'Il nome della pizzeria è obbligatorio.', 'Il nome della pizzeria può avere al massimo %d caratteri.');
        $existing = $this->pizzerias->findOneBy(['normalizedName' => NameNormalizer::normalize($name)]);
        if (null !== $existing && $existing !== $current) {
            throw new CatalogRuleException('Esiste già una pizzeria con questo nome.');
        }

        return $name;
    }

    private function validIngredientName(Pizzeria $pizzeria, string $name, ?Ingredient $current): string
    {
        $name = $this->validName($name, self::MAX_INGREDIENT_NAME_LENGTH, "Il nome dell'ingrediente è obbligatorio.", "Il nome dell'ingrediente può avere al massimo %d caratteri.");
        foreach ($pizzeria->getIngredients() as $ingredient) {
            if ($ingredient !== $current && $ingredient->getNormalizedName() === NameNormalizer::normalize($name)) {
                throw new CatalogRuleException('Esiste già un ingrediente con questo nome.');
            }
        }

        return $name;
    }

    /**
     * @param list<int> $ingredientIds
     *
     * @return array{string, ?int, list<Ingredient>}
     */
    private function validMenuPizza(Pizzeria $pizzeria, string $name, string $price, array $ingredientIds, ?MenuPizza $current): array
    {
        $name = $this->validName($name, self::MAX_PIZZA_NAME_LENGTH, 'Il nome della pizza è obbligatorio.', 'Il nome della pizza può avere al massimo %d caratteri.');
        foreach ($pizzeria->getMenuPizzas() as $pizza) {
            if ($pizza !== $current && $pizza->getNormalizedName() === NameNormalizer::normalize($name)) {
                throw new CatalogRuleException('Esiste già una pizza con questo nome nel menu.');
            }
        }

        try {
            $priceCents = PriceParser::parseOptionalCents($price);
        } catch (InvalidPriceException $e) {
            throw new CatalogRuleException($e->getMessage(), 0, $e);
        }

        return [$name, $priceCents, array_map(fn (int $id) => $this->ingredient($pizzeria, $id), array_values(array_unique($ingredientIds)))];
    }

    private function validName(string $name, int $maxLength, string $requiredMessage, string $lengthMessage): string
    {
        $name = NameNormalizer::clean($name);
        if ('' === $name) {
            throw new CatalogRuleException($requiredMessage);
        }
        if (mb_strlen($name) > $maxLength) {
            throw new CatalogRuleException(sprintf($lengthMessage, $maxLength));
        }

        return $name;
    }

    private function ingredient(Pizzeria $pizzeria, int $ingredientId): Ingredient
    {
        foreach ($pizzeria->getIngredients() as $ingredient) {
            if ($ingredient->getId() === $ingredientId) {
                return $ingredient;
            }
        }

        throw new CatalogRuleException('Ingrediente non trovato.');
    }

    private function menuPizza(Pizzeria $pizzeria, int $pizzaId): MenuPizza
    {
        foreach ($pizzeria->getMenuPizzas() as $pizza) {
            if ($pizza->getId() === $pizzaId) {
                return $pizza;
            }
        }

        throw new CatalogRuleException('Pizza non trovata.');
    }
}
