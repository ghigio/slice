<?php

namespace App\Tests\Integration;

use App\Entity\Eater;
use App\Entity\Evening;
use App\Entity\MenuPizza;
use App\Entity\Pizza;
use App\Entity\Pizzeria;
use App\Enum\EveningStatus;
use App\Evening\Exception\EveningRuleException;
use App\Evening\PizzaOrdering;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PizzaOrderingTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PizzaOrdering $ordering;
    private Evening $evening;
    private Eater $rossi;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->ordering = new PizzaOrdering($this->em);
        $this->evening = new Evening('Serata', new \DateTimeImmutable('2026-10-19'), 'Org');
        $this->rossi = new Eater($this->evening, 'Famiglia Rossi');
        $this->em->persist($this->evening);
        $this->em->flush();
    }

    public function testAggiungePizzaConPrezzoInCentesimi(): void
    {
        $pizza = $this->ordering->addPizza($this->rossi, ' Anna ', ' quattro  stagioni ', '8,50', true);

        self::assertNotNull($pizza->getId());
        self::assertSame('Anna', $pizza->getPerson());
        self::assertSame('quattro stagioni', $pizza->getPizzaName());
        self::assertSame(850, $pizza->getPriceCents());
        self::assertCount(1, $this->rossi->getPizzas());
    }

    public static function invalidInputs(): iterable
    {
        yield 'persona vuota' => ['  ', 'Margherita', '7', 'Il nome della persona è obbligatorio.'];
        yield 'persona lunga' => [str_repeat('a', 61), 'Margherita', '7', 'Il nome della persona può avere al massimo 60 caratteri.'];
        yield 'pizza vuota' => ['Anna', ' ', '7', 'Il nome della pizza è obbligatorio.'];
        yield 'pizza lunga' => ['Anna', str_repeat('b', 81), '7', 'Il nome della pizza può avere al massimo 80 caratteri.'];
        yield 'prezzo non valido' => ['Anna', 'Margherita', 'sette', 'Inserisci un prezzo valido, per esempio 7,50.'];
        yield 'prezzo fuori intervallo' => ['Anna', 'Margherita', '120', 'Il prezzo deve essere tra 0,01 € e 100,00 €.'];
    }

    #[DataProvider('invalidInputs')]
    public function testDatiNonValidi(string $person, string $pizzaName, string $price, string $message): void
    {
        $this->expectExceptionObject(new EveningRuleException($message));
        $this->ordering->addPizza($this->rossi, $person, $pizzaName, $price, true);
    }

    public static function statusRules(): iterable
    {
        $closed = "Ordini chiusi dall'organizzatore.";
        $archived = 'La serata è archiviata: riaprila per modificarla.';
        yield 'gruppo, ordini aperti' => [EveningStatus::OrdersOpen, false, null];
        yield 'gruppo, ordini chiusi' => [EveningStatus::OrdersClosed, false, $closed];
        yield 'gruppo, conto inserito' => [EveningStatus::BillEntered, false, $closed];
        yield 'gruppo, archiviata' => [EveningStatus::Archived, false, $closed];
        yield 'organizzatore, ordini aperti' => [EveningStatus::OrdersOpen, true, null];
        yield 'organizzatore, ordini chiusi' => [EveningStatus::OrdersClosed, true, null];
        yield 'organizzatore, conto inserito' => [EveningStatus::BillEntered, true, null];
        yield 'organizzatore, archiviata' => [EveningStatus::Archived, true, $archived];
    }

    #[DataProvider('statusRules')]
    public function testChiPuoModificareInOgniStato(EveningStatus $status, bool $asOrganizer, ?string $error): void
    {
        $existing = $this->ordering->addPizza($this->rossi, 'Anna', 'Margherita', '7', false);
        $this->evening->setStatus($status);
        $this->em->flush();

        $operations = [
            fn () => $this->ordering->addPizza($this->rossi, 'Luca', 'Diavola', '8', $asOrganizer),
            fn () => $this->ordering->updatePizza($this->rossi, $existing->getId(), 'Anna', 'Marinara', '6', $asOrganizer),
            fn () => $this->ordering->deletePizza($this->rossi, $existing->getId(), $asOrganizer),
        ];

        foreach ($operations as $operation) {
            try {
                $operation();
                self::assertNull($error, 'Operazione consentita per errore');
            } catch (EveningRuleException $e) {
                self::assertSame($error, $e->getMessage());
            }
        }
    }

    public function testModificaPizza(): void
    {
        $pizza = $this->ordering->addPizza($this->rossi, 'Anna', 'Margherita', '7', true);

        $this->ordering->updatePizza($this->rossi, $pizza->getId(), 'Anna Maria', 'Margherita Bufala', '9.5', true);
        $this->em->clear();

        $reloaded = $this->em->find(Pizza::class, $pizza->getId());
        self::assertSame('Anna Maria', $reloaded->getPerson());
        self::assertSame('Margherita Bufala', $reloaded->getPizzaName());
        self::assertSame('margherita bufala', $reloaded->getNormalizedName());
        self::assertSame(950, $reloaded->getPriceCents());
    }

    public function testEliminaPizza(): void
    {
        $pizza = $this->ordering->addPizza($this->rossi, 'Anna', 'Margherita', '7', false);
        $id = $pizza->getId();

        $this->ordering->deletePizza($this->rossi, $id, false);

        self::assertNull($this->em->find(Pizza::class, $id));
        self::assertCount(0, $this->rossi->getPizzas());
    }

    public function testPizzaDiAltroGruppoNonAccessibile(): void
    {
        $bianchi = new Eater($this->evening, 'Famiglia Bianchi');
        $this->em->flush();
        $foreign = $this->ordering->addPizza($bianchi, 'Marta', 'Diavola', '8', false);

        foreach ([
            fn () => $this->ordering->updatePizza($this->rossi, $foreign->getId(), 'X', 'Y', '1', false),
            fn () => $this->ordering->deletePizza($this->rossi, $foreign->getId(), true),
        ] as $operation) {
            try {
                $operation();
                self::fail('Attesa EveningRuleException');
            } catch (EveningRuleException $e) {
                self::assertSame('Pizza non trovata.', $e->getMessage());
            }
        }
        self::assertSame('Diavola', $foreign->getPizzaName());
    }

    public function testLimiteDiTrecentoPizzePerSerata(): void
    {
        $bianchi = new Eater($this->evening, 'Famiglia Bianchi');
        for ($i = 0; $i < 300; ++$i) {
            new Pizza(0 === $i % 2 ? $this->rossi : $bianchi, 'P'.$i, 'Margherita', 700);
        }
        $this->em->flush();

        $this->expectExceptionObject(new EveningRuleException('La serata ha raggiunto il limite di 300 pizze.'));
        $this->ordering->addPizza($this->rossi, 'Anna', 'Margherita', '7', false);
    }

    public function testPrezzoFacoltativoConOrdiniAperti(): void
    {
        $pizza = $this->ordering->addPizza($this->rossi, 'Anna', 'Margherita', '  ', true);
        self::assertNull($pizza->getPriceCents());

        $this->ordering->updatePizza($this->rossi, $pizza->getId(), 'Anna', 'Margherita', '7', true);
        self::assertSame(700, $pizza->getPriceCents());

        $this->ordering->updatePizza($this->rossi, $pizza->getId(), 'Anna', 'Margherita', '', true);
        self::assertNull($pizza->getPriceCents());
    }

    public function testIlGruppoNonInserisceIlPrezzoAncheConRichiesteCostruiteAMano(): void
    {
        $pizza = $this->ordering->addPizza($this->rossi, 'Anna', 'Margherita', '7', false);
        self::assertNull($pizza->getPriceCents());

        $this->ordering->updatePizza($this->rossi, $pizza->getId(), 'Anna', 'Margherita', '9', false);
        self::assertNull($pizza->getPriceCents());

        // Un prezzo non valido inviato dal gruppo non conta: il campo non esiste per il gruppo.
        self::assertNull($this->ordering->addPizza($this->rossi, 'Luca', 'Diavola', 'tanto', false)->getPriceCents());
    }

    public function testSenzaPrezzoNellaSerataSiUsaQuelloDelMenu(): void
    {
        $pizzeria = new Pizzeria('Da Gino');
        new MenuPizza($pizzeria, 'Diavola', 800);
        new MenuPizza($pizzeria, 'Marinara', null);
        $this->em->persist($pizzeria);
        $this->evening->setKnownPizzeria($pizzeria);
        $this->em->flush();

        self::assertSame(800, $this->ordering->addPizza($this->rossi, 'Anna', ' diavola ', '', false)->getPriceCents());
        self::assertNull($this->ordering->addPizza($this->rossi, 'Anna', 'Marinara', '', false)->getPriceCents(), 'Menu senza prezzo');
        self::assertNull($this->ordering->addPizza($this->rossi, 'Anna', 'Margherita baby', '', false)->getPriceCents(), 'Pizza fuori menu');

        // L'organizzatore corregge il prezzo nella serata: vale più del menu.
        $this->ordering->addPizza($this->rossi, 'Luca', 'Diavola', '7,50', true);
        self::assertSame(750, $this->ordering->addPizza($this->rossi, 'Gino', 'Diavola', '', false)->getPriceCents());
    }

    public function testLaPizzaDelGruppoPrendeLUltimoPrezzoNotoPerQuelNome(): void
    {
        $bianchi = new Eater($this->evening, 'Famiglia Bianchi');
        $this->em->flush();
        $this->ordering->addPizza($bianchi, 'Marta', 'Margherita', '6,50', true);
        $this->ordering->addPizza($bianchi, 'Gino', 'Margherita', '7', true);

        $pizza = $this->ordering->addPizza($this->rossi, 'Anna', ' margherita ', '', false);
        self::assertSame(700, $pizza->getPriceCents());

        $this->ordering->updatePizza($this->rossi, $pizza->getId(), 'Anna Maria', 'Margherita', '', false);
        self::assertSame(700, $pizza->getPriceCents(), 'Cambiando solo la persona il prezzo resta');

        $this->ordering->updatePizza($this->rossi, $pizza->getId(), 'Anna', 'Diavola', '', false);
        self::assertNull($pizza->getPriceCents(), 'Nessun prezzo noto per la Diavola');

        $this->ordering->updatePizza($this->rossi, $pizza->getId(), 'Anna', 'MARGHERITA', '', false);
        self::assertSame(700, $pizza->getPriceCents());
    }

    public function testConIlContoInseritoIlPrezzoEObbligatorio(): void
    {
        $pizza = $this->ordering->addPizza($this->rossi, 'Anna', 'Margherita', '7', true);
        $this->evening->setStatus(EveningStatus::OrdersClosed);
        $this->evening->enterBill(700, \App\Enum\DiscountMode::Proportional);
        $this->em->flush();

        foreach ([
            fn () => $this->ordering->addPizza($this->rossi, 'Luca', 'Diavola', '', true),
            fn () => $this->ordering->updatePizza($this->rossi, $pizza->getId(), 'Anna', 'Margherita', '', true),
        ] as $operation) {
            try {
                $operation();
                self::fail('Attesa EveningRuleException');
            } catch (EveningRuleException $e) {
                self::assertSame('Con il conto inserito ogni pizza deve avere un prezzo.', $e->getMessage());
            }
        }
        self::assertSame(700, $pizza->getPriceCents());
    }

    public function testLOrganizzatoreCompletaIPrezziMancantiPerNome(): void
    {
        $bianchi = new Eater($this->evening, 'Famiglia Bianchi');
        $this->em->flush();
        $a = $this->ordering->addPizza($this->rossi, 'Anna', 'Margherita', '', false);
        $b = $this->ordering->addPizza($bianchi, 'Gino', ' margherita ', '', false);
        $priced = $this->ordering->addPizza($bianchi, 'Marta', 'Margherita', '6,50', true);
        $other = $this->ordering->addPizza($this->rossi, 'Luca', 'Diavola', '', false);

        $updated = $this->ordering->setMissingPrices($this->evening, 'margherita', '7');

        self::assertSame(2, $updated);
        self::assertSame(700, $a->getPriceCents());
        self::assertSame(700, $b->getPriceCents());
        self::assertSame(650, $priced->getPriceCents());
        self::assertNull($other->getPriceCents());
    }

    public function testPrezziMancantiConPrezzoNonValidoONonInserito(): void
    {
        $this->ordering->addPizza($this->rossi, 'Anna', 'Margherita', '', false);

        foreach (['' => 'Inserisci il prezzo.', 'tanto' => 'Inserisci un prezzo valido, per esempio 7,50.'] as $input => $message) {
            try {
                $this->ordering->setMissingPrices($this->evening, 'margherita', $input);
                self::fail('Attesa EveningRuleException');
            } catch (EveningRuleException $e) {
                self::assertSame($message, $e->getMessage());
            }
        }
    }

    public function testPrezziMancantiNonDaArchiviata(): void
    {
        $this->ordering->addPizza($this->rossi, 'Anna', 'Margherita', '', false);
        $this->evening->setStatus(EveningStatus::Archived);

        $this->expectExceptionObject(new EveningRuleException('La serata è archiviata: riaprila per modificarla.'));
        $this->ordering->setMissingPrices($this->evening, 'margherita', '7');
    }

    public function testUnisceLePizzeConNomeEPrezzoDellaDestinazione(): void
    {
        $bianchi = new Eater($this->evening, 'Famiglia Bianchi');
        $this->em->flush();
        $salamino = $this->ordering->addPizza($this->rossi, 'Anna', 'Salamino', '7', true);
        $a = $this->ordering->addPizza($bianchi, 'Marta', 'Salamino piccante', '', true);
        $b = $this->ordering->addPizza($bianchi, 'Gino', 'salamino  piccante', '8', true);
        $other = $this->ordering->addPizza($this->rossi, 'Luca', 'Diavola', '', true);

        $merged = $this->ordering->mergePizzas($this->evening, 'salamino piccante', 'salamino');

        self::assertSame(2, $merged);
        foreach ([$salamino, $a, $b] as $pizza) {
            self::assertSame('Salamino', $pizza->getPizzaName());
            self::assertSame('salamino', $pizza->getNormalizedName());
            self::assertSame(700, $pizza->getPriceCents());
        }
        self::assertSame('Diavola', $other->getPizzaName());
        self::assertSame(['Gino', 'Marta'], array_map(fn (Pizza $p) => $p->getPerson(), [$b, $a]), 'Persone e gruppi restano invariati');
    }

    public function testSenzaPrezzoDiDestinazioneLePizzeUniteMantengonoIlLoro(): void
    {
        $this->ordering->addPizza($this->rossi, 'Anna', 'Diavola', '', true);
        $spicy = $this->ordering->addPizza($this->rossi, 'Luca', 'Diavola piccante', '8', true);

        $this->ordering->mergePizzas($this->evening, 'diavola piccante', 'diavola');

        self::assertSame('Diavola', $spicy->getPizzaName());
        self::assertSame(800, $spicy->getPriceCents());
    }

    public static function invalidMerges(): iterable
    {
        yield 'stessa pizza' => ['margherita', 'margherita', 'Scegli due pizze diverse.'];
        yield 'pizza da unire inesistente' => ['capricciosa', 'margherita', 'Pizza non trovata.'];
        yield 'destinazione inesistente' => ['margherita', 'capricciosa', 'Pizza non trovata.'];
    }

    #[DataProvider('invalidMerges')]
    public function testUnioneNonValida(string $from, string $into, string $message): void
    {
        $this->ordering->addPizza($this->rossi, 'Anna', 'Margherita', '', true);

        $this->expectExceptionObject(new EveningRuleException($message));
        $this->ordering->mergePizzas($this->evening, $from, $into);
    }

    public function testUnioneConsentitaAOrdiniChiusiMaNonDaArchiviata(): void
    {
        $this->ordering->addPizza($this->rossi, 'Anna', 'Margherita', '', true);
        $this->ordering->addPizza($this->rossi, 'Luca', 'Magherita', '', true);
        $this->evening->setStatus(EveningStatus::OrdersClosed);
        self::assertSame(1, $this->ordering->mergePizzas($this->evening, 'magherita', 'margherita'));

        $this->ordering->addPizza($this->rossi, 'Gino', 'Margerita', '', true);
        $this->evening->setStatus(EveningStatus::Archived);
        $this->expectExceptionObject(new EveningRuleException('La serata è archiviata: riaprila per modificarla.'));
        $this->ordering->mergePizzas($this->evening, 'margerita', 'margherita');
    }

    public function testConIlContoInseritoUnioneRifiutataSeRendeImpossibileIlCalcolo(): void
    {
        $baby = $this->ordering->addPizza($this->rossi, 'Anna', 'Baby', '3', true);
        $this->ordering->addPizza($this->rossi, 'Luca', 'Gigante', '30', true);
        $margherita = $this->ordering->addPizza($this->rossi, 'Gino', 'Margherita', '3', true);
        $this->evening->setStatus(EveningStatus::OrdersClosed);
        $this->evening->enterBill(3000, \App\Enum\DiscountMode::Equal);
        $this->em->flush();

        try {
            $this->ordering->mergePizzas($this->evening, 'margherita', 'gigante');
            self::fail('Attesa EveningRuleException');
        } catch (EveningRuleException $e) {
            self::assertStringContainsString('la pizza Baby di Anna', $e->getMessage());
            self::assertStringContainsString('passa alla modalità proporzionale', $e->getMessage());
        }
        self::assertSame('Margherita', $margherita->getPizzaName());
        self::assertSame(300, $margherita->getPriceCents());
        self::assertSame(300, $baby->getPriceCents());
    }
}
