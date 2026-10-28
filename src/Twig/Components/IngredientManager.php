<?php

namespace App\Twig\Components;

use App\Entity\Ingredient;
use App\Entity\Pizzeria;
use App\Pizzeria\Exception\CatalogRuleException;
use App\Pizzeria\PizzeriaCatalog;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Ingredienti di una pizzeria (area di gestione): aggiunta, rinomina ed eliminazione.
 * Avvisa MenuPizzaManager con l'evento ingredientsChanged.
 */
#[AsLiveComponent(route: 'gestione_live_component')]
final class IngredientManager
{
    use AdminOnlyTrait;
    use ComponentToolsTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public Pizzeria $pizzeria;

    #[LiveProp(writable: true)]
    public string $newName = '';

    #[LiveProp(writable: true)]
    public ?int $editingId = null;

    #[LiveProp(writable: true)]
    public string $editingName = '';

    public ?string $error = null;
    public ?string $notice = null;

    public function __construct(private readonly PizzeriaCatalog $catalog)
    {
    }

    #[LiveAction]
    public function add(): void
    {
        $this->apply(function () {
            $ingredient = $this->catalog->addIngredient($this->pizzeria, $this->newName);
            $this->newName = '';

            return sprintf('Ingrediente «%s» aggiunto.', $ingredient->getName());
        });
    }

    #[LiveAction]
    public function startEdit(#[LiveArg] int $ingredientId): void
    {
        foreach ($this->pizzeria->getIngredients() as $ingredient) {
            if ($ingredient->getId() === $ingredientId) {
                $this->editingId = $ingredientId;
                $this->editingName = $ingredient->getName();
            }
        }
    }

    #[LiveAction]
    public function cancelEdit(): void
    {
        $this->editingId = null;
        $this->editingName = '';
    }

    #[LiveAction]
    public function rename(): void
    {
        if (null === $this->editingId) {
            return;
        }
        $this->apply(function () {
            $this->catalog->renameIngredient($this->pizzeria, $this->editingId, $this->editingName);
            $this->cancelEdit();

            return 'Ingrediente rinominato.';
        });
    }

    #[LiveAction]
    public function delete(#[LiveArg] int $ingredientId): void
    {
        $this->apply(function () use ($ingredientId) {
            $pizzas = $this->catalog->deleteIngredient($this->pizzeria, $ingredientId);

            return 0 === $pizzas ? 'Ingrediente eliminato.' : sprintf('Ingrediente eliminato e tolto da %d %s.', $pizzas, 1 === $pizzas ? 'pizza' : 'pizze');
        });
    }

    public function countPizzasWith(Ingredient $ingredient): int
    {
        return $this->catalog->countPizzasWith($this->pizzeria, $ingredient);
    }

    /** @param callable(): string $operation restituisce il messaggio di conferma */
    private function apply(callable $operation): void
    {
        try {
            $this->notice = $operation();
            $this->emit('ingredientsChanged');
        } catch (CatalogRuleException $e) {
            $this->error = $e->getMessage();
        }
    }
}
