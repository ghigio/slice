<?php

namespace App\Twig\Components;

use App\Entity\Eater;
use App\Entity\Pizza;
use App\Evening\BillSummary;
use App\Evening\Exception\EveningRuleException;
use App\Evening\PizzaOrdering;
use App\Money\MoneyFormatter;
use App\Repository\PizzaRepository;
use App\Text\NameNormalizer;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Pizze di un gruppo: inserimento, modifica ed eliminazione, elenco per persona con totali.
 * Usato dalla pagina del gruppo e, con asOrganizer, dalla pagina admin. Mostra solo le pizze del gruppo indicato.
 */
#[AsLiveComponent]
final class PizzaOrder
{
    use DefaultActionTrait;

    #[LiveProp]
    public Eater $eater;

    #[LiveProp]
    public bool $asOrganizer = false;

    #[LiveProp(writable: true)]
    public string $person = '';

    #[LiveProp(writable: true)]
    public string $pizzaName = '';

    #[LiveProp(writable: true)]
    public string $price = '';

    #[LiveProp(writable: true)]
    public ?int $editingId = null;

    #[LiveProp(writable: true)]
    public string $editPerson = '';

    #[LiveProp(writable: true)]
    public string $editPizzaName = '';

    #[LiveProp(writable: true)]
    public string $editPrice = '';

    public ?string $error = null;
    public ?string $notice = null;

    public function __construct(
        private readonly PizzaOrdering $ordering,
        private readonly PizzaRepository $pizzas,
    ) {
    }

    #[LiveAction]
    public function add(): void
    {
        $this->apply(function () {
            $pizza = $this->ordering->addPizza($this->eater, $this->person, $this->pizzaName, $this->price, $this->asOrganizer);
            $this->person = $pizza->getPerson();
            $this->pizzaName = '';
            $this->price = '';

            return sprintf('%s aggiunta per %s.', $pizza->getPizzaName(), $pizza->getPerson());
        });
    }

    #[LiveAction]
    public function startEdit(#[LiveArg] int $pizzaId): void
    {
        foreach ($this->eater->getPizzas() as $pizza) {
            if ($pizza->getId() === $pizzaId) {
                $this->editingId = $pizzaId;
                $this->editPerson = $pizza->getPerson();
                $this->editPizzaName = $pizza->getPizzaName();
                $this->editPrice = $pizza->hasPrice() ? MoneyFormatter::formatInput($pizza->getPriceCents()) : '';
            }
        }
    }

    #[LiveAction]
    public function cancelEdit(): void
    {
        $this->editingId = null;
        $this->editPerson = $this->editPizzaName = $this->editPrice = '';
    }

    #[LiveAction]
    public function save(): void
    {
        if (null === $this->editingId) {
            return;
        }
        $this->apply(function () {
            $this->ordering->updatePizza($this->eater, $this->editingId, $this->editPerson, $this->editPizzaName, $this->editPrice, $this->asOrganizer);
            $this->cancelEdit();

            return 'Pizza modificata.';
        });
    }

    #[LiveAction]
    public function delete(#[LiveArg] int $pizzaId): void
    {
        $this->apply(function () use ($pizzaId) {
            $this->ordering->deletePizza($this->eater, $pizzaId, $this->asOrganizer);

            return 'Pizza eliminata.';
        });
    }

    /** Quote del conto per la vista del gruppo; null per l'organizzatore o se il conto non è inserito. */
    public function getBill(): ?BillSummary
    {
        return $this->asOrganizer ? null : BillSummary::forEvening($this->eater->getEvening());
    }

    public function canEdit(): bool
    {
        return PizzaOrdering::canEdit($this->eater->getEvening(), $this->asOrganizer);
    }

    /**
     * Persona => pizze, totale dei prezzi noti e numero di pizze senza prezzo, in ordine alfabetico.
     *
     * @return array<string, array{pizzas: list<Pizza>, totalCents: int, missing: int}>
     */
    public function byPerson(): array
    {
        $groups = [];
        foreach ($this->eater->getPizzas() as $pizza) {
            $group = &$groups[$pizza->getPerson()];
            $group['pizzas'][] = $pizza;
            $group['totalCents'] = ($group['totalCents'] ?? 0) + ($pizza->getPriceCents() ?? 0);
            $group['missing'] = ($group['missing'] ?? 0) + ($pizza->hasPrice() ? 0 : 1);
            unset($group);
        }
        uksort($groups, 'strcasecmp');

        return $groups;
    }

    /** Totale dei prezzi noti. */
    public function totalCents(): int
    {
        return array_sum(array_map(fn (Pizza $p) => $p->getPriceCents() ?? 0, $this->eater->getPizzas()->toArray()));
    }

    public function missingPrices(): int
    {
        return \count(array_filter($this->eater->getPizzas()->toArray(), fn (Pizza $p) => !$p->hasPrice()));
    }

    /** @return list<array{name: string, priceCents: ?int}> */
    public function pizzaSuggestions(): array
    {
        return $this->pizzas->suggestionsForEvening($this->eater->getEvening());
    }

    /** @return list<string> */
    public function personSuggestions(): array
    {
        return $this->pizzas->personsForEater($this->eater);
    }

    /** @return array<string, string> nome normalizzato => ultimo prezzo («7,50»), per proporre il prezzo quando si sceglie una pizza */
    public function suggestedPrices(): array
    {
        $prices = [];
        foreach ($this->pizzaSuggestions() as $suggestion) {
            if (null !== $suggestion['priceCents']) {
                $prices[NameNormalizer::normalize($suggestion['name'])] = MoneyFormatter::formatInput($suggestion['priceCents']);
            }
        }

        return $prices;
    }

    /** @param callable(): string $operation restituisce il messaggio di conferma */
    private function apply(callable $operation): void
    {
        try {
            $this->notice = $operation();
        } catch (EveningRuleException $e) {
            $this->error = $e->getMessage();
        }
    }
}
