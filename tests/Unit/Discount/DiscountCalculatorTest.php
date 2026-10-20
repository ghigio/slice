<?php

namespace App\Tests\Unit\Discount;

use App\Discount\DiscountCalculator;
use App\Discount\Exception\EmptyOrderException;
use App\Discount\Exception\NegativeDiscountedPriceException;
use App\Enum\DiscountMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DiscountCalculatorTest extends TestCase
{
    private DiscountCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new DiscountCalculator();
    }

    /**
     * Tabella «Casi di test e criteri di accettazione» di docs/REQUISITI.md, valori esatti in centesimi.
     *
     * @param list<int> $listPrices
     * @param list<int> $expectedPrices
     */
    #[DataProvider('requirementCases')]
    public function testCasiDellaTabellaDeiRequisiti(DiscountMode $mode, array $listPrices, int $paidTotal, array $expectedPrices, int $expectedDelta): void
    {
        $result = $this->calculator->calculate($listPrices, $paidTotal, $mode);

        self::assertSame($expectedPrices, $result->discountedPrices);
        self::assertSame($expectedDelta, $result->roundingDifferenceCents);
    }

    public static function requirementCases(): iterable
    {
        yield '1 proporzionale' => [DiscountMode::Proportional, [700, 800, 1200, 650], 3000, [627, 716, 1075, 582], 0];
        yield '2 proporzionale' => [DiscountMode::Proportional, [1000, 1000, 1000], 2000, [667, 667, 667], 1];
        yield '3 proporzionale half up' => [DiscountMode::Proportional, [500, 500], 999, [500, 500], 1];
        yield '4 uguale' => [DiscountMode::Equal, [700, 800, 1200], 2500, [633, 733, 1133], -1];
        yield '6 proporzionale' => [DiscountMode::Proportional, [500, 3000], 2400, [343, 2057], 0];
        yield '7 proporzionale' => [DiscountMode::Proportional, [700, 800], 1500, [700, 800], 0];
        yield '7 uguale' => [DiscountMode::Equal, [700, 800], 1500, [700, 800], 0];
        yield '8 proporzionale maggiorazione' => [DiscountMode::Proportional, [1000], 1100, [1100], 0];
    }

    public function testCaso5UgualeConPrezzoNegativoSegnalaLaPizza(): void
    {
        try {
            $this->calculator->calculate([500, 3000], 2400, DiscountMode::Equal);
            self::fail('Attesa NegativeDiscountedPriceException');
        } catch (NegativeDiscountedPriceException $e) {
            self::assertSame(0, $e->pizzaKey);
            self::assertSame(500, $e->listPriceCents);
            self::assertSame(-50, $e->discountedPriceCents);
        }
    }

    public function testRiepilogoCaso1PerGruppo(): void
    {
        // Chiavi = id pizza; famiglia A (organizzatore): 1, 2; famiglia B: 3, 4.
        $result = $this->calculator->calculate([1 => 700, 2 => 800, 3 => 1200, 4 => 650], 3000, DiscountMode::Proportional);
        $prices = $result->discountedPrices;

        $shareA = $prices[1] + $prices[2];
        $shareB = $prices[3] + $prices[4];

        // A è il gruppo dell'organizzatore: nessun rimborso, il totale da ricevere è la sola quota di B.
        $totalToReceive = $shareB;

        self::assertSame(1343, $shareA);
        self::assertSame(1657, $shareB);
        self::assertSame(1657, $totalToReceive);
    }

    public function testRisultatoEsponeTotaliESconto(): void
    {
        $result = $this->calculator->calculate([700, 800, 1200], 2500, DiscountMode::Equal);

        self::assertSame(2700, $result->listTotalCents);
        self::assertSame(2500, $result->paidTotalCents);
        self::assertSame(200, $result->discountCents);
        self::assertSame(2499, $result->discountedTotalCents);
        self::assertSame(-1, $result->roundingDifferenceCents);
        self::assertSame(DiscountMode::Equal, $result->mode);
    }

    public function testChiaviPreservate(): void
    {
        $result = $this->calculator->calculate([42 => 1000, 7 => 1000, 'x' => 1000], 2000, DiscountMode::Proportional);

        self::assertSame([42 => 667, 7 => 667, 'x' => 667], $result->discountedPrices);
    }

    public function testHalfUpInModalitaUguale(): void
    {
        $result = $this->calculator->calculate([1000, 1000], 1999, DiscountMode::Equal);

        self::assertSame([1000, 1000], $result->discountedPrices);
        self::assertSame(1, $result->roundingDifferenceCents);
    }

    public function testMaggiorazioneInModalitaUguale(): void
    {
        $result = $this->calculator->calculate([700, 800], 1600, DiscountMode::Equal);

        self::assertSame(-100, $result->discountCents);
        self::assertSame([750, 850], $result->discountedPrices);
        self::assertSame(0, $result->roundingDifferenceCents);
    }

    public function testUgualeAmmetteNegativoCheArrotondaAZero(): void
    {
        // Pizza da 1 cent: 1 − 4/3 = −0,33 cent → 0.
        $result = $this->calculator->calculate([1, 1000, 1000], 1997, DiscountMode::Equal);

        self::assertSame([0, 999, 999], $result->discountedPrices);
        self::assertSame(1, $result->roundingDifferenceCents);
    }

    public function testUgualeMezzoCentesimoNegativoArrotondaAZero(): void
    {
        // Pizza da 1 cent: 1 − 3/2 = −0,5 cent → half up verso +∞ → 0.
        $result = $this->calculator->calculate([1, 1000], 998, DiscountMode::Equal);

        self::assertSame([0, 999], $result->discountedPrices);
    }

    public function testUgualeErroreSeArrotondatoNegativo(): void
    {
        // Pizza da 1 cent: 1 − 5/2 = −1,5 cent → −1.
        try {
            $this->calculator->calculate(['a' => 1, 'b' => 1000], 996, DiscountMode::Equal);
            self::fail('Attesa NegativeDiscountedPriceException');
        } catch (NegativeDiscountedPriceException $e) {
            self::assertSame('a', $e->pizzaKey);
            self::assertSame(1, $e->listPriceCents);
            self::assertSame(-1, $e->discountedPriceCents);
        }
    }

    public function testNessunaPizzaImpedisceIlConto(): void
    {
        $this->expectException(EmptyOrderException::class);
        $this->calculator->calculate([], 1000, DiscountMode::Proportional);
    }

    #[DataProvider('invalidInputs')]
    public function testInputFuoriDominio(array $listPrices, int $paidTotal): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calculator->calculate($listPrices, $paidTotal, DiscountMode::Proportional);
    }

    public static function invalidInputs(): iterable
    {
        yield 'prezzo zero' => [[0, 1000], 900];
        yield 'prezzo negativo' => [[-100, 1000], 900];
        yield 'totale pagato zero' => [[1000], 0];
        yield 'totale pagato negativo' => [[1000], -1];
    }

    public function testValoriMassimiSenzaOverflow(): void
    {
        $prices = array_fill(0, 300, 10000);
        $result = $this->calculator->calculate($prices, 2999999, DiscountMode::Proportional);

        self::assertSame(3000000, $result->listTotalCents);
        self::assertSame(array_fill(0, 300, 10000), $result->discountedPrices);
        self::assertSame(1, $result->roundingDifferenceCents);
    }
}
