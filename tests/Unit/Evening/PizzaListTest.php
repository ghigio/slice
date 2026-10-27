<?php

namespace App\Tests\Unit\Evening;

use App\Entity\Eater;
use App\Entity\Evening;
use App\Entity\Pizza;
use App\Evening\PizzaList;
use PHPUnit\Framework\TestCase;

final class PizzaListTest extends TestCase
{
    private Eater $rossi;
    private Eater $bianchi;

    protected function setUp(): void
    {
        $evening = new Evening('Serata', new \DateTimeImmutable('2026-10-19'), 'Org');
        $this->rossi = new Eater($evening, 'Famiglia Rossi');
        $this->bianchi = new Eater($evening, 'Famiglia Bianchi');
    }

    public function testAggregaVariantiDelloStessoNome(): void
    {
        $list = PizzaList::fromPizzas([
            new Pizza($this->rossi, 'Anna', 'Margherita', 700),
            new Pizza($this->rossi, 'Luca', 'margherita', 700),
            new Pizza($this->bianchi, 'Marta', ' margherita ', 700),
            new Pizza($this->bianchi, 'Gino', 'Diavola', 800),
        ]);

        self::assertSame([
            ['name' => 'Diavola', 'quantity' => 1],
            ['name' => 'margherita', 'quantity' => 3],
        ], $list->lines());
        self::assertSame(4, $list->total());
    }

    public function testNomiConChiaveDiAggregazione(): void
    {
        $list = PizzaList::fromPizzas([
            new Pizza($this->rossi, 'Anna', 'Salamino piccante', null),
            new Pizza($this->rossi, 'Luca', 'Salamino', 700),
            new Pizza($this->bianchi, 'Marta', 'salamino', 700),
        ]);

        self::assertSame([
            ['name' => 'salamino', 'normalizedName' => 'salamino', 'quantity' => 2],
            ['name' => 'Salamino piccante', 'normalizedName' => 'salamino piccante', 'quantity' => 1],
        ], $list->names());
    }

    public function testOrdineAlfabeticoSenzaAccentiEMaiuscole(): void
    {
        $list = PizzaList::fromPizzas([
            new Pizza($this->rossi, 'Anna', 'zucca', 800),
            new Pizza($this->rossi, 'Anna', 'Àrrabbiata', 800),
            new Pizza($this->rossi, 'Anna', 'bufala', 900),
        ]);

        self::assertSame(['Àrrabbiata', 'bufala', 'zucca'], array_column($list->lines(), 'name'));
    }

    public function testTestoDaCopiare(): void
    {
        $list = PizzaList::fromPizzas([
            new Pizza($this->rossi, 'Anna', 'Margherita', 700),
            new Pizza($this->rossi, 'Luca', 'Margherita', 700),
            new Pizza($this->bianchi, 'Gino', 'Diavola', 800),
        ]);

        self::assertSame("1 x Diavola\n2 x Margherita", $list->asText());
    }

    public function testVistaPerGruppoConNomiDellaSerata(): void
    {
        $list = PizzaList::fromPizzas([
            new Pizza($this->rossi, 'Anna', 'Margherita', 700),
            new Pizza($this->rossi, 'Luca', 'margherita', 700),
            new Pizza($this->bianchi, 'Gino', 'Margherita', 700),
            new Pizza($this->bianchi, 'Gino', 'Diavola', 800),
        ]);

        self::assertSame([
            ['eater' => 'Famiglia Bianchi', 'lines' => [['name' => 'Diavola', 'quantity' => 1], ['name' => 'Margherita', 'quantity' => 1]], 'total' => 2],
            ['eater' => 'Famiglia Rossi', 'lines' => [['name' => 'Margherita', 'quantity' => 2]], 'total' => 2],
        ], $list->byEater());
    }

    public function testPrezziDiversiTraGruppi(): void
    {
        $list = PizzaList::fromPizzas([
            new Pizza($this->rossi, 'Anna', 'Margherita', 700),
            new Pizza($this->bianchi, 'Gino', 'margherita', 750),
            new Pizza($this->rossi, 'Luca', 'Diavola', 800),
            new Pizza($this->bianchi, 'Marta', 'Diavola', 800),
        ]);

        self::assertSame([
            // «Margherita» e «margherita» una volta ciascuna: vince la più recente.
            ['name' => 'margherita', 'rows' => [
                ['eater' => 'Famiglia Bianchi', 'person' => 'Gino', 'pizzaName' => 'margherita', 'priceCents' => 750],
                ['eater' => 'Famiglia Rossi', 'person' => 'Anna', 'pizzaName' => 'Margherita', 'priceCents' => 700],
            ]],
        ], $list->priceConflicts());
    }

    public function testPrezziDiversiNelloStessoGruppoNonSonoUnConflitto(): void
    {
        $list = PizzaList::fromPizzas([
            new Pizza($this->rossi, 'Anna', 'Margherita', 700),
            new Pizza($this->rossi, 'Luca', 'Margherita', 750),
        ]);

        self::assertSame([], $list->priceConflicts());
    }

    public function testListaVuota(): void
    {
        $list = PizzaList::fromPizzas([]);

        self::assertSame([], $list->lines());
        self::assertSame(0, $list->total());
        self::assertSame('', $list->asText());
        self::assertSame([], $list->byEater());
    }

    public function testPizzeSenzaPrezzoNellaListaMaNonNeiConflitti(): void
    {
        $list = PizzaList::fromPizzas([
            new Pizza($this->rossi, 'Anna', 'Margherita', null),
            new Pizza($this->bianchi, 'Gino', 'Margherita', 700),
            new Pizza($this->bianchi, 'Marta', 'Margherita', null),
        ]);

        self::assertSame([['name' => 'Margherita', 'quantity' => 3]], $list->lines());
        self::assertSame([], $list->priceConflicts());
    }
}
