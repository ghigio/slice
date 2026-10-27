<?php

namespace App\Evening;

use App\Entity\Pizza;
use App\Text\NameNormalizer;
use App\Text\PreferredForm;

/**
 * Lista per il pizzaiolo: pizze aggregate per nome normalizzato, vista per gruppo e prezzi incoerenti tra gruppi.
 */
final class PizzaList
{
    /** @var array<string, string> nome normalizzato => nome da mostrare */
    private array $displayNames = [];

    /** @param list<Pizza> $pizzas in ordine di inserimento */
    private function __construct(private readonly array $pizzas)
    {
        $forms = [];
        foreach ($pizzas as $pizza) {
            $forms[$pizza->getNormalizedName()][] = $pizza->getPizzaName();
        }
        foreach ($forms as $key => $names) {
            $this->displayNames[(string) $key] = PreferredForm::pick($names);
        }
    }

    /** @param iterable<Pizza> $pizzas in ordine di inserimento */
    public static function fromPizzas(iterable $pizzas): self
    {
        return new self([...$pizzas]);
    }

    /** @return list<array{name: string, quantity: int}> */
    public function lines(): array
    {
        return $this->aggregate($this->pizzas);
    }

    public function total(): int
    {
        return count($this->pizzas);
    }

    /** Testo semplice da dettare o incollare: «3 x Margherita», una riga per pizza. */
    public function asText(): string
    {
        return implode("\n", array_map(fn (array $line) => sprintf('%d x %s', $line['quantity'], $line['name']), $this->lines()));
    }

    /** @return list<array{eater: string, lines: list<array{name: string, quantity: int}>, total: int}> */
    public function byEater(): array
    {
        $groups = [];
        foreach ($this->pizzas as $pizza) {
            $groups[$pizza->getEater()->getName()][] = $pizza;
        }
        uksort($groups, fn ($a, $b) => strcmp(self::sortKey((string) $a), self::sortKey((string) $b)));

        $result = [];
        foreach ($groups as $eater => $pizzas) {
            $result[] = ['eater' => (string) $eater, 'lines' => $this->aggregate($pizzas), 'total' => count($pizzas)];
        }

        return $result;
    }

    /**
     * Pizze con lo stesso nome ma prezzi diversi in gruppi diversi (probabile errore di inserimento); le pizze senza prezzo non contano.
     *
     * @return list<array{name: string, rows: list<array{eater: string, person: string, pizzaName: string, priceCents: int}>}>
     */
    public function priceConflicts(): array
    {
        $byName = [];
        foreach ($this->pizzas as $pizza) {
            $byName[$pizza->getNormalizedName()][] = $pizza;
        }

        $conflicts = [];
        foreach ($byName as $key => $pizzas) {
            if (!self::hasCrossEaterPriceDifference($pizzas)) {
                continue;
            }
            $rows = array_map(fn (Pizza $p) => [
                'eater' => $p->getEater()->getName(),
                'person' => $p->getPerson(),
                'pizzaName' => $p->getPizzaName(),
                'priceCents' => $p->getPriceCents(),
            ], $pizzas);
            usort($rows, fn ($a, $b) => [self::sortKey($a['eater']), self::sortKey($a['person'])] <=> [self::sortKey($b['eater']), self::sortKey($b['person'])]);
            $conflicts[] = ['name' => $this->displayNames[(string) $key], 'rows' => $rows];
        }
        usort($conflicts, fn ($a, $b) => strcmp(self::sortKey($a['name']), self::sortKey($b['name'])));

        return $conflicts;
    }

    /**
     * Come lines(), con il nome normalizzato che identifica la pizza (per unire due pizze).
     *
     * @return list<array{name: string, normalizedName: string, quantity: int}>
     */
    public function names(): array
    {
        return $this->count($this->pizzas);
    }

    /**
     * @param list<Pizza> $pizzas
     *
     * @return list<array{name: string, quantity: int}>
     */
    private function aggregate(array $pizzas): array
    {
        return array_map(fn (array $line) => ['name' => $line['name'], 'quantity' => $line['quantity']], $this->count($pizzas));
    }

    /**
     * @param list<Pizza> $pizzas
     *
     * @return list<array{name: string, normalizedName: string, quantity: int}>
     */
    private function count(array $pizzas): array
    {
        $quantities = [];
        foreach ($pizzas as $pizza) {
            $key = $pizza->getNormalizedName();
            $quantities[$key] = ($quantities[$key] ?? 0) + 1;
        }
        uksort($quantities, fn ($a, $b) => strcmp((string) $a, (string) $b));

        $lines = [];
        foreach ($quantities as $key => $quantity) {
            $lines[] = ['name' => $this->displayNames[(string) $key], 'normalizedName' => (string) $key, 'quantity' => $quantity];
        }

        return $lines;
    }

    /** @param list<Pizza> $pizzas */
    private static function hasCrossEaterPriceDifference(array $pizzas): bool
    {
        foreach ($pizzas as $a) {
            foreach ($pizzas as $b) {
                if ($a->getEater() !== $b->getEater() && $a->hasPrice() && $b->hasPrice() && $a->getPriceCents() !== $b->getPriceCents()) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function sortKey(string $value): string
    {
        return NameNormalizer::normalize($value);
    }
}
