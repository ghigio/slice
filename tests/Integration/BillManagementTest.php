<?php

namespace App\Tests\Integration;

use App\Entity\Eater;
use App\Entity\Evening;
use App\Entity\Pizza;
use App\Enum\DiscountMode;
use App\Enum\EveningStatus;
use App\Evening\BillManagement;
use App\Evening\BillSummary;
use App\Evening\Exception\EveningRuleException;
use App\Evening\PizzaOrdering;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BillManagementTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private BillManagement $bills;
    private Evening $evening;
    private Eater $a;
    private Eater $b;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->bills = new BillManagement($this->em);

        // Caso 1 dei requisiti.
        $this->evening = new Evening('Serata', new \DateTimeImmutable('2026-10-19'), 'Org');
        $this->a = new Eater($this->evening, 'Famiglia A');
        $this->b = new Eater($this->evening, 'Famiglia B');
        $this->evening->setOrganizerEater($this->a);
        new Pizza($this->a, 'Anna', 'Margherita', 700);
        new Pizza($this->a, 'Luca', 'Diavola', 800);
        new Pizza($this->b, 'Marta', 'Bufala', 1200);
        new Pizza($this->b, 'Marta', 'Marinara', 650);
        $this->evening->setStatus(EveningStatus::OrdersClosed);
        $this->em->persist($this->evening);
        $this->em->flush();
    }

    public function testAnteprimaSenzaSalvare(): void
    {
        $summary = $this->bills->preview($this->evening, '30,00', DiscountMode::Proportional);

        self::assertSame(1657, $summary->share($this->b)->dueCents);
        self::assertSame(EveningStatus::OrdersClosed, $this->evening->getStatus());
        self::assertNull($this->evening->getPaidTotalCents());
    }

    public function testInserimentoDelContoESalvataggio(): void
    {
        $this->bills->enterBill($this->evening, '30', DiscountMode::Proportional, false);
        $this->em->clear();

        $evening = $this->em->find(Evening::class, $this->evening->getId());
        self::assertSame(EveningStatus::BillEntered, $evening->getStatus());
        self::assertSame(3000, $evening->getPaidTotalCents());

        $summary = BillSummary::forEvening($evening);
        $b = array_values(array_filter($summary->shares(), fn ($s) => 'Famiglia B' === $s->eater->getName()))[0];
        self::assertSame(1657, $b->dueCents);
        self::assertSame(1657, $summary->toReceiveCents());
    }

    public function testModificaTotaleEModalitaRicalcolaLeQuote(): void
    {
        $this->bills->enterBill($this->evening, '30', DiscountMode::Proportional, false);
        $this->bills->enterBill($this->evening, '31,50', DiscountMode::Equal, false);

        $summary = BillSummary::forEvening($this->evening);
        self::assertSame(3150, $summary->paidTotalCents());
        self::assertSame(DiscountMode::Equal, $summary->mode());
        // D = 200, 50 cent in meno a pizza: B = 1150 + 600.
        self::assertSame(1750, $summary->share($this->b)->dueCents);
    }

    public function testConOrdiniApertiIlContoNonSiInserisce(): void
    {
        $this->evening->setStatus(EveningStatus::OrdersOpen);

        $this->expectExceptionObject(new EveningRuleException('Chiudi gli ordini prima di inserire il conto.'));
        $this->bills->preview($this->evening, '30', DiscountMode::Proportional);
    }

    public function testSerataArchiviata(): void
    {
        $this->evening->setStatus(EveningStatus::Archived);

        $this->expectExceptionObject(new EveningRuleException('La serata è archiviata: riaprila per modificarla.'));
        $this->bills->enterBill($this->evening, '30', DiscountMode::Proportional, false);
    }

    public function testSenzaPizzeIlContoNonSiInserisce(): void
    {
        $empty = new Evening('Vuota', new \DateTimeImmutable('2026-10-19'), 'Org');
        $empty->setStatus(EveningStatus::OrdersClosed);

        $this->expectExceptionObject(new EveningRuleException('Nessuna pizza: il conto non si può inserire.'));
        $this->bills->preview($empty, '30', DiscountMode::Proportional);
    }

    public function testImportoNonValido(): void
    {
        $this->expectExceptionObject(new EveningRuleException('Inserisci un importo valido, per esempio 30,00.'));
        $this->bills->preview($this->evening, 'trenta', DiscountMode::Proportional);
    }

    public function testMaggiorazioneRichiedeConfermaEsplicita(): void
    {
        try {
            $this->bills->enterBill($this->evening, '40', DiscountMode::Proportional, false);
            self::fail('Attesa EveningRuleException');
        } catch (EveningRuleException $e) {
            self::assertSame('Il totale pagato (40,00 €) è maggiore del totale da listino (33,50 €): controlla l\'importo e conferma per continuare.', $e->getMessage());
        }
        self::assertSame(EveningStatus::OrdersClosed, $this->evening->getStatus());

        $this->bills->enterBill($this->evening, '40', DiscountMode::Proportional, true);
        self::assertSame(EveningStatus::BillEntered, $this->evening->getStatus());
    }

    public function testModalitaUgualeImpossibileIndicaLaPizza(): void
    {
        // S = 3350, T = 700, D = 2650: 6,625 € in meno a pizza, la Marinara da 6,50 € andrebbe a −0,12 €.
        $this->expectExceptionObject(new EveningRuleException('In modalità uguale la pizza Marinara di Marta (Famiglia B) da 6,50 € andrebbe a -0,12 €: usa la modalità proporzionale.'));
        $this->bills->preview($this->evening, '7', DiscountMode::Equal);
    }

    public function testRimborsi(): void
    {
        $now = new \DateTimeImmutable('2026-10-19 22:15:00');
        $this->bills->enterBill($this->evening, '30', DiscountMode::Proportional, false);

        $this->bills->setReimbursed($this->evening, $this->b->getId(), true, $now);
        self::assertEquals($now, $this->b->getReimbursedAt());
        self::assertSame(1657, BillSummary::forEvening($this->evening)->receivedCents());

        $this->bills->setReimbursed($this->evening, $this->b->getId(), false, $now);
        self::assertNull($this->b->getReimbursedAt());
    }

    public function testRimborsiAncheDaArchiviata(): void
    {
        $this->bills->enterBill($this->evening, '30', DiscountMode::Proportional, false);
        $this->evening->archive();

        $this->bills->setReimbursed($this->evening, $this->b->getId(), true, new \DateTimeImmutable());

        self::assertNotNull($this->b->getReimbursedAt());
    }

    public function testRimborsiNonAmmessi(): void
    {
        $cases = [
            'prima del conto' => [fn () => $this->bills->setReimbursed($this->evening, $this->b->getId(), true, new \DateTimeImmutable()), 'I rimborsi si segnano dopo aver inserito il conto.'],
        ];
        foreach ($cases as [$operation, $message]) {
            try {
                $operation();
                self::fail('Attesa EveningRuleException');
            } catch (EveningRuleException $e) {
                self::assertSame($message, $e->getMessage());
            }
        }

        $this->bills->enterBill($this->evening, '30', DiscountMode::Proportional, false);
        $other = new Evening('Altra', new \DateTimeImmutable('2026-10-19'), 'Org');
        $foreign = new Eater($other, 'Famiglia Verdi');
        $this->em->persist($other);
        $this->em->flush();

        $cases = [
            'gruppo organizzatore' => [fn () => $this->bills->setReimbursed($this->evening, $this->a->getId(), true, new \DateTimeImmutable()), 'Questo gruppo non deve rimborsare nulla.'],
            'altra serata' => [fn () => $this->bills->setReimbursed($this->evening, $foreign->getId(), true, new \DateTimeImmutable()), 'Gruppo non trovato.'],
        ];
        foreach ($cases as [$operation, $message]) {
            try {
                $operation();
                self::fail('Attesa EveningRuleException');
            } catch (EveningRuleException $e) {
                self::assertSame($message, $e->getMessage());
            }
        }
    }

    public function testModificaPizzaBloccataSeRendeImpossibileLaModalitaUguale(): void
    {
        $this->bills->enterBill($this->evening, '25', DiscountMode::Equal, false);
        $ordering = new PizzaOrdering($this->em);
        $marinara = $this->b->getPizzas()->last();

        // Modifica compatibile: Marinara a 6,00 €.
        $ordering->updatePizza($this->b, $marinara->getId(), 'Marta', 'Marinara', '6', true);
        self::assertSame(600, $marinara->getPriceCents());

        // Marinara a 0,30 €: S = 2730, D = 230, 0,575 € in meno a pizza → −0,27 €.
        try {
            $ordering->updatePizza($this->b, $marinara->getId(), 'Marta', 'Marinara', '0,30', true);
            self::fail('Attesa EveningRuleException');
        } catch (EveningRuleException $e) {
            self::assertSame('Con questa modifica, in modalità uguale, la pizza Marinara di Marta (Famiglia B) da 0,30 € andrebbe a -0,27 €: passa alla modalità proporzionale.', $e->getMessage());
        }
        self::assertSame(600, $marinara->getPriceCents());
        $this->em->refresh($marinara);
        self::assertSame(600, $marinara->getPriceCents());
    }

    public function testAggiuntaBloccataSeRendeImpossibileLaModalitaUguale(): void
    {
        $this->bills->enterBill($this->evening, '25', DiscountMode::Equal, false);
        $ordering = new PizzaOrdering($this->em);

        // Focaccia da 1,00 €: S = 3450, D = 950, 1,90 € in meno a pizza → −0,90 €.
        try {
            $ordering->addPizza($this->b, 'Gino', 'Focaccia', '1', true);
            self::fail('Attesa EveningRuleException');
        } catch (EveningRuleException $e) {
            self::assertStringContainsString('la pizza Focaccia di Gino (Famiglia B) da 1,00 €', $e->getMessage());
        }
        self::assertCount(2, $this->b->getPizzas());
    }

    public function testConIlContoDeveRestareAlmenoUnaPizza(): void
    {
        $evening = new Evening('Una pizza', new \DateTimeImmutable('2026-10-19'), 'Org');
        $eater = new Eater($evening, 'Famiglia');
        $pizza = new Pizza($eater, 'Anna', 'Margherita', 700);
        $evening->setStatus(EveningStatus::OrdersClosed);
        $this->em->persist($evening);
        $this->em->flush();
        $this->bills->enterBill($evening, '7', DiscountMode::Proportional, false);

        $this->expectExceptionObject(new EveningRuleException('Con il conto inserito deve restare almeno una pizza.'));
        (new PizzaOrdering($this->em))->deletePizza($eater, $pizza->getId(), true);
    }
}
