<?php

namespace App\Twig\Components;

use App\Entity\MenuPizza;
use App\Entity\Pizzeria;
use App\Money\MoneyFormatter;
use App\Pizzeria\Exception\CatalogRuleException;
use App\Pizzeria\PizzeriaCatalog;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Menu di una pizzeria (area di gestione): pizze con nome, prezzo facoltativo e ingredienti scelti con caselle.
 */
#[AsLiveComponent(route: 'gestione_live_component')]
final class MenuPizzaManager
{
    use AdminOnlyTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public Pizzeria $pizzeria;

    #[LiveProp(writable: true)]
    public string $name = '';

    #[LiveProp(writable: true)]
    public string $price = '';

    /** @var list<string> id degli ingredienti selezionati (valori delle caselle) */
    #[LiveProp(writable: true)]
    public array $ingredientIds = [];

    #[LiveProp(writable: true)]
    public ?int $editingId = null;

    #[LiveProp(writable: true)]
    public string $editName = '';

    #[LiveProp(writable: true)]
    public string $editPrice = '';

    /** @var list<string> */
    #[LiveProp(writable: true)]
    public array $editIngredientIds = [];

    public ?string $error = null;
    public ?string $notice = null;

    public function __construct(private readonly PizzeriaCatalog $catalog)
    {
    }

    #[LiveAction]
    public function add(): void
    {
        $this->apply(function () {
            $pizza = $this->catalog->addMenuPizza($this->pizzeria, $this->name, $this->price, self::ids($this->ingredientIds));
            $this->name = $this->price = '';
            $this->ingredientIds = [];

            return sprintf('Pizza «%s» aggiunta al menu.', $pizza->getName());
        });
    }

    #[LiveAction]
    public function startEdit(#[LiveArg] int $pizzaId): void
    {
        foreach ($this->pizzeria->getMenuPizzas() as $pizza) {
            if ($pizza->getId() === $pizzaId) {
                $this->editingId = $pizzaId;
                $this->editName = $pizza->getName();
                $this->editPrice = null === $pizza->getPriceCents() ? '' : MoneyFormatter::formatInput($pizza->getPriceCents());
                $this->editIngredientIds = array_map(fn ($i) => (string) $i->getId(), $pizza->getIngredients()->getValues());
            }
        }
    }

    #[LiveAction]
    public function cancelEdit(): void
    {
        $this->editingId = null;
        $this->editName = $this->editPrice = '';
        $this->editIngredientIds = [];
    }

    #[LiveAction]
    public function save(): void
    {
        if (null === $this->editingId) {
            return;
        }
        $this->apply(function () {
            $this->catalog->updateMenuPizza($this->pizzeria, $this->editingId, $this->editName, $this->editPrice, self::ids($this->editIngredientIds));
            $this->cancelEdit();

            return 'Pizza modificata.';
        });
    }

    #[LiveAction]
    public function delete(#[LiveArg] int $pizzaId): void
    {
        $this->apply(function () use ($pizzaId) {
            $this->catalog->deleteMenuPizza($this->pizzeria, $pizzaId);

            return 'Pizza eliminata dal menu.';
        });
    }

    /** Ingredienti aggiunti, rinominati o eliminati: le caselle e gli elenchi si aggiornano. */
    #[LiveListener('ingredientsChanged')]
    public function refresh(): void
    {
        $known = array_map(fn ($i) => (string) $i->getId(), $this->pizzeria->getIngredients()->getValues());
        $this->ingredientIds = array_values(array_intersect($this->ingredientIds, $known));
        $this->editIngredientIds = array_values(array_intersect($this->editIngredientIds, $known));
    }

    /** Ingredienti della pizza, separati da virgola. */
    public function ingredientList(MenuPizza $pizza): string
    {
        return implode(', ', array_map(fn ($i) => $i->getName(), $pizza->getIngredients()->getValues()));
    }

    /**
     * @param list<string> $values
     *
     * @return list<int>
     */
    private static function ids(array $values): array
    {
        return array_values(array_map('intval', array_filter($values, 'is_numeric')));
    }

    /** @param callable(): string $operation restituisce il messaggio di conferma */
    private function apply(callable $operation): void
    {
        try {
            $this->notice = $operation();
        } catch (CatalogRuleException $e) {
            $this->error = $e->getMessage();
        }
    }
}
