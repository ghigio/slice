<?php

namespace App\Evening;

use App\Discount\DiscountCalculator;
use App\Discount\DiscountResult;
use App\Discount\Exception\NegativeDiscountedPriceException;
use App\Entity\Eater;
use App\Entity\Evening;
use App\Entity\Pizza;
use App\Enum\DiscountMode;
use App\Money\MoneyFormatter;
use App\Text\NameNormalizer;

/**
 * Quote di tutti i gruppi per un totale pagato e una modalità, ricalcolate a ogni lettura con DiscountCalculator.
 * Le quote sono somme dei prezzi scontati delle pizze, mai ripartizioni dei totali.
 */
final class BillSummary
{
    /** @var array<int, EaterShare> spl_object_id(eater) => quota */
    private array $shares = [];

    private function __construct(private readonly Evening $evening, private readonly DiscountResult $result)
    {
        $organizer = $evening->getOrganizerEater();
        foreach ($evening->getEaters() as $eater) {
            $list = $share = 0;
            foreach ($eater->getPizzas() as $pizza) {
                $list += $pizza->getPriceCents();
                $share += $this->discountedPriceCents($pizza);
            }
            $isOrganizer = $organizer === $eater;
            $this->shares[spl_object_id($eater)] = new EaterShare($eater, $eater->getPizzas()->count(), $list, $share, $isOrganizer ? 0 : $share, $isOrganizer);
        }
        uasort($this->shares, fn (EaterShare $a, EaterShare $b) => strcmp(NameNormalizer::normalize($a->eater->getName()), NameNormalizer::normalize($b->eater->getName())));
    }

    /**
     * @throws \App\Discount\Exception\EmptyOrderException se non ci sono pizze
     * @throws NegativeDiscountedPriceException            in modalità uguale, se una pizza andrebbe sotto zero
     */
    public static function compute(Evening $evening, int $paidTotalCents, DiscountMode $mode): self
    {
        $prices = [];
        foreach (self::pizzas($evening) as $key => $pizza) {
            $prices[$key] = $pizza->getPriceCents();
        }

        return new self($evening, (new DiscountCalculator())->calculate($prices, $paidTotalCents, $mode));
    }

    /** Quote con il conto salvato nella serata, o null se il conto non è inserito. */
    public static function forEvening(Evening $evening): ?self
    {
        if (!$evening->hasBill()) {
            return null;
        }

        return self::compute($evening, $evening->getPaidTotalCents(), $evening->getDiscountMode());
    }

    /** La pizza indicata da un errore della modalità uguale. */
    public static function pizzaForException(Evening $evening, NegativeDiscountedPriceException $e): ?Pizza
    {
        return self::pizzas($evening)[$e->pizzaKey] ?? null;
    }

    public function listTotalCents(): int
    {
        return $this->result->listTotalCents;
    }

    public function paidTotalCents(): int
    {
        return $this->result->paidTotalCents;
    }

    public function mode(): DiscountMode
    {
        return $this->result->mode;
    }

    /** Positivo = sconto, negativo = maggiorazione. */
    public function discountCents(): int
    {
        return $this->result->discountCents;
    }

    public function isSurcharge(): bool
    {
        return $this->discountCents() < 0;
    }

    /** Valore assoluto dello sconto (o della maggiorazione) in percentuale con due decimali: «10,45 %». */
    public function discountPercentText(): string
    {
        $hundredths = intdiv(2 * abs($this->discountCents()) * 10000 + $this->listTotalCents(), 2 * $this->listTotalCents());

        return sprintf('%d,%02d %%', intdiv($hundredths, 100), $hundredths % 100);
    }

    public function discountedPriceCents(Pizza $pizza): int
    {
        return $this->result->discountedPrices[spl_object_id($pizza)];
    }

    /** @return list<EaterShare> in ordine di nome */
    public function shares(): array
    {
        return array_values($this->shares);
    }

    public function share(Eater $eater): EaterShare
    {
        return $this->shares[spl_object_id($eater)];
    }

    /** @return list<array{person: string, listCents: int, shareCents: int}> in ordine di nome */
    public function personTotals(Eater $eater): array
    {
        $totals = [];
        foreach ($eater->getPizzas() as $pizza) {
            $person = $pizza->getPerson();
            $totals[$person] ??= ['person' => $person, 'listCents' => 0, 'shareCents' => 0];
            $totals[$person]['listCents'] += $pizza->getPriceCents();
            $totals[$person]['shareCents'] += $this->discountedPriceCents($pizza);
        }
        uksort($totals, fn ($a, $b) => strcmp(NameNormalizer::normalize((string) $a), NameNormalizer::normalize((string) $b)));

        return array_values($totals);
    }

    public function hasOrganizerEater(): bool
    {
        return null !== $this->evening->getOrganizerEater();
    }

    public function toReceiveCents(): int
    {
        return array_sum(array_map(fn (EaterShare $s) => $s->dueCents, $this->shares));
    }

    public function receivedCents(): int
    {
        return array_sum(array_map(fn (EaterShare $s) => $s->isReimbursed() ? $s->dueCents : 0, $this->shares));
    }

    public function missingCents(): int
    {
        return $this->toReceiveCents() - $this->receivedCents();
    }

    /** Δ = somma delle quote − totale pagato; da mostrare solo all'organizzatore. */
    public function roundingDifferenceCents(): int
    {
        return $this->result->roundingDifferenceCents;
    }

    public function roundingDifferenceMessage(): string
    {
        $delta = $this->roundingDifferenceCents();

        return match (true) {
            0 === $delta => 'Nessuna differenza',
            $delta > 0 => sprintf('Riceverai %s in più di quanto hai pagato', MoneyFormatter::format($delta)),
            default => sprintf('Riceverai %s in meno di quanto hai pagato', MoneyFormatter::format(-$delta)),
        };
    }

    /** @return array<int, Pizza> spl_object_id(pizza) => pizza */
    private static function pizzas(Evening $evening): array
    {
        $pizzas = [];
        foreach ($evening->getEaters() as $eater) {
            foreach ($eater->getPizzas() as $pizza) {
                $pizzas[spl_object_id($pizza)] = $pizza;
            }
        }

        return $pizzas;
    }
}
