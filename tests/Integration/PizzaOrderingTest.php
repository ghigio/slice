<?php

namespace App\Tests\Integration;

use App\Entity\Eater;
use App\Entity\Evening;
use App\Entity\Pizza;
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
        $pizza = $this->ordering->addPizza($this->rossi, ' Anna ', ' quattro  stagioni ', '8,50', false);

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
        $this->ordering->addPizza($this->rossi, $person, $pizzaName, $price, false);
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
        $pizza = $this->ordering->addPizza($this->rossi, 'Anna', 'Margherita', '7', false);

        $this->ordering->updatePizza($this->rossi, $pizza->getId(), 'Anna Maria', 'Margherita Bufala', '9.5', false);
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
}
