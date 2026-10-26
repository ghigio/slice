<?php

namespace App\Tests\Unit\Evening;

use App\Discount\Exception\NegativeDiscountedPriceException;
use App\Entity\Eater;
use App\Entity\Evening;
use App\Entity\Pizza;
use App\Enum\DiscountMode;
use App\Evening\BillSummary;
use PHPUnit\Framework\TestCase;

final class BillSummaryTest extends TestCase
{
    private Evening $evening;
    private Eater $a;
    private Eater $b;

    protected function setUp(): void
    {
        // Caso 1 dei requisiti: A (organizzatore) 7,00 + 8,00; B 12,00 + 6,50; totale pagato 30,00.
        $this->evening = new Evening('Serata', new \DateTimeImmutable('2026-10-19'), 'Org');
        $this->a = new Eater($this->evening, 'Famiglia A');
        $this->b = new Eater($this->evening, 'Famiglia B');
        $this->evening->setOrganizerEater($this->a);
        new Pizza($this->a, 'Anna', 'Margherita', 700);
        new Pizza($this->a, 'Luca', 'Diavola', 800);
        new Pizza($this->b, 'Marta', 'Bufala', 1200);
        new Pizza($this->b, 'Marta', 'Marinara', 650);
    }

    public function testRiepilogoDelCaso1(): void
    {
        $summary = BillSummary::compute($this->evening, 3000, DiscountMode::Proportional);

        $a = $summary->share($this->a);
        self::assertSame(2, $a->pizzaCount);
        self::assertSame(1500, $a->listCents);
        self::assertSame(1343, $a->shareCents);
        self::assertSame(0, $a->dueCents);
        self::assertTrue($a->isOrganizer);

        $b = $summary->share($this->b);
        self::assertSame(1850, $b->listCents);
        self::assertSame(1657, $b->shareCents);
        self::assertSame(1657, $b->dueCents);
        self::assertFalse($b->isOrganizer);

        self::assertSame(3350, $summary->listTotalCents());
        self::assertSame(350, $summary->discountCents());
        self::assertSame(1657, $summary->toReceiveCents());
        self::assertSame(0, $summary->receivedCents());
        self::assertSame(1657, $summary->missingCents());
        self::assertSame(0, $summary->roundingDifferenceCents());
        self::assertSame('Nessuna differenza', $summary->roundingDifferenceMessage());
        self::assertSame('10,45 %', $summary->discountPercentText());
        self::assertFalse($summary->isSurcharge());
    }

    public function testPrezziScontatiETotaliPerPersona(): void
    {
        $summary = BillSummary::compute($this->evening, 3000, DiscountMode::Proportional);

        $prices = array_map(fn (Pizza $p) => $summary->discountedPriceCents($p), $this->b->getPizzas()->toArray());
        self::assertSame([1075, 582], $prices);
        self::assertSame([
            ['person' => 'Marta', 'listCents' => 1850, 'shareCents' => 1657],
        ], $summary->personTotals($this->b));
        self::assertSame([
            ['person' => 'Anna', 'listCents' => 700, 'shareCents' => 627],
            ['person' => 'Luca', 'listCents' => 800, 'shareCents' => 716],
        ], $summary->personTotals($this->a));
    }

    public function testRimborsiRicevuti(): void
    {
        $this->b->markReimbursed(new \DateTimeImmutable('2026-10-19 22:15'));

        $summary = BillSummary::compute($this->evening, 3000, DiscountMode::Proportional);

        self::assertSame(1657, $summary->receivedCents());
        self::assertSame(0, $summary->missingCents());
    }

    public function testSenzaGruppoOrganizzatoreRimborsanoTutti(): void
    {
        $this->evening->setOrganizerEater(null);

        $summary = BillSummary::compute($this->evening, 3000, DiscountMode::Proportional);

        self::assertSame(3000, $summary->toReceiveCents());
        self::assertFalse($summary->hasOrganizerEater());
    }

    public function testGruppoSenzaPizzeNonDeveNulla(): void
    {
        $empty = new Eater($this->evening, 'Famiglia C');

        $share = BillSummary::compute($this->evening, 3000, DiscountMode::Proportional)->share($empty);

        self::assertSame(0, $share->pizzaCount);
        self::assertSame(0, $share->dueCents);
    }

    public function testQuoteInOrdineDiNome(): void
    {
        new Eater($this->evening, 'Aaa');

        $names = array_map(fn ($s) => $s->eater->getName(), BillSummary::compute($this->evening, 3000, DiscountMode::Proportional)->shares());

        self::assertSame(['Aaa', 'Famiglia A', 'Famiglia B'], $names);
    }

    public function testMessaggiDellaDifferenzaDaArrotondamento(): void
    {
        $evening = new Evening('Serata', new \DateTimeImmutable('2026-10-19'), 'Org');
        $eater = new Eater($evening, 'Famiglia');
        foreach ([1000, 1000, 1000] as $price) {
            new Pizza($eater, 'Anna', 'Margherita', $price);
        }
        $plus = BillSummary::compute($evening, 2000, DiscountMode::Proportional);
        self::assertSame(1, $plus->roundingDifferenceCents());
        self::assertSame('Riceverai 0,01 € in più di quanto hai pagato', $plus->roundingDifferenceMessage());

        $evening = new Evening('Serata', new \DateTimeImmutable('2026-10-19'), 'Org');
        $eater = new Eater($evening, 'Famiglia');
        foreach ([700, 800, 1200] as $price) {
            new Pizza($eater, 'Anna', 'Margherita', $price);
        }
        $minus = BillSummary::compute($evening, 2500, DiscountMode::Equal);
        self::assertSame(-1, $minus->roundingDifferenceCents());
        self::assertSame('Riceverai 0,01 € in meno di quanto hai pagato', $minus->roundingDifferenceMessage());
    }

    public function testMaggiorazione(): void
    {
        $evening = new Evening('Serata', new \DateTimeImmutable('2026-10-19'), 'Org');
        new Pizza(new Eater($evening, 'Famiglia'), 'Anna', 'Margherita', 1000);

        $summary = BillSummary::compute($evening, 1100, DiscountMode::Proportional);

        self::assertTrue($summary->isSurcharge());
        self::assertSame('10,00 %', $summary->discountPercentText());
        self::assertSame(-100, $summary->discountCents());
    }

    public function testErroreModalitaUgualeIndicaLaPizza(): void
    {
        $evening = new Evening('Serata', new \DateTimeImmutable('2026-10-19'), 'Org');
        $eater = new Eater($evening, 'Famiglia');
        $cheap = new Pizza($eater, 'Anna', 'Marinara', 500);
        new Pizza($eater, 'Luca', 'Speciale', 3000);

        try {
            BillSummary::compute($evening, 2400, DiscountMode::Equal);
            self::fail('Attesa NegativeDiscountedPriceException');
        } catch (NegativeDiscountedPriceException $e) {
            self::assertSame($cheap, BillSummary::pizzaForException($evening, $e));
            self::assertSame(-50, $e->discountedPriceCents);
        }
    }
}
