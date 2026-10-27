<?php

namespace App\Twig\Components;

use App\Entity\Evening;
use App\Evening\Exception\EveningRuleException;
use App\Evening\PizzaOrdering;
use App\Repository\PizzaRepository;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Pizze senza prezzo (pagina admin): l'organizzatore inserisce il prezzo una volta per nome di pizza.
 * Finché ne restano, il conto non si può inserire.
 */
#[AsLiveComponent]
final class MissingPrices
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public Evening $evening;

    /** @var array<string, string> chiave del nome (vedi key()) => prezzo digitato */
    #[LiveProp(writable: true)]
    public array $prices = [];

    public ?string $error = null;
    public ?string $notice = null;

    public function __construct(
        private readonly PizzaOrdering $ordering,
        private readonly PizzaRepository $pizzas,
    ) {
    }

    #[LiveAction]
    public function apply(#[LiveArg] string $normalizedName): void
    {
        $key = self::key($normalizedName);
        try {
            $updated = $this->ordering->setMissingPrices($this->evening, $normalizedName, $this->prices[$key] ?? '');
            unset($this->prices[$key]);
            $this->notice = sprintf('Prezzo applicato a %d %s.', $updated, 1 === $updated ? 'pizza' : 'pizze');
            $this->emit('pricesUpdated');
        } catch (EveningRuleException $e) {
            $this->error = $e->getMessage();
        }
    }

    /** Pizze unite dall'organizzatore: i nomi senza prezzo cambiano subito. */
    #[LiveListener('pizzasMerged')]
    public function refresh(): void
    {
    }

    /** @return list<array{name: string, normalizedName: string, quantity: int}> */
    public function getMissing(): array
    {
        return $this->pizzas->missingPricesForEvening($this->evening);
    }

    /** Chiave sicura per il modello (il nome normalizzato può contenere spazi e punti). */
    public static function key(string $normalizedName): string
    {
        return 'p'.hash('xxh3', $normalizedName);
    }
}
