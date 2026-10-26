<?php

namespace App\Tests\Integration;

use App\Entity\Eater;
use App\Entity\Evening;
use App\Entity\Pizza;
use App\Enum\EveningStatus;
use App\Evening\EveningAdministration;
use App\Evening\Exception\EveningRuleException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EveningAdministrationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private EveningAdministration $admin;
    private Evening $evening;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->admin = new EveningAdministration($this->em);
        $this->evening = $this->persistEvening('Serata');
    }

    public function testAggiungeGruppoConNomePulito(): void
    {
        $eater = $this->admin->addEater($this->evening, '  Famiglia   Rossi ');

        self::assertNotNull($eater->getId());
        self::assertSame('Famiglia Rossi', $eater->getName());
        self::assertSame($this->evening, $eater->getEvening());
    }

    public function testNomeDuplicatoSenzaDistinzioneDiMaiuscoleEAccenti(): void
    {
        $this->admin->addEater($this->evening, 'Famiglia Rossi');

        $this->expectExceptionObject(new EveningRuleException('Esiste già un gruppo con questo nome.'));
        $this->admin->addEater($this->evening, ' famiglia  RÒSSI');
    }

    public function testNomeObbligatorio(): void
    {
        $this->expectExceptionObject(new EveningRuleException('Il nome del gruppo è obbligatorio.'));
        $this->admin->addEater($this->evening, '   ');
    }

    public function testNomeTroppoLungo(): void
    {
        $this->expectExceptionObject(new EveningRuleException('Il nome del gruppo può avere al massimo 255 caratteri.'));
        $this->admin->addEater($this->evening, str_repeat('a', 256));
    }

    public function testMassimoCinquantaGruppi(): void
    {
        for ($i = 1; $i <= 50; ++$i) {
            $this->admin->addEater($this->evening, 'Gruppo '.$i);
        }

        $this->expectExceptionObject(new EveningRuleException('Una serata può avere al massimo 50 gruppi.'));
        $this->admin->addEater($this->evening, 'Gruppo 51');
    }

    public function testRinominaAncheSoloMaiuscole(): void
    {
        $eater = $this->admin->addEater($this->evening, 'famiglia rossi');
        $this->admin->renameEater($this->evening, $eater->getId(), 'Famiglia Rossi');

        self::assertSame('Famiglia Rossi', $eater->getName());
    }

    public function testRinominaConNomeDiAltroGruppo(): void
    {
        $this->admin->addEater($this->evening, 'Famiglia Rossi');
        $bianchi = $this->admin->addEater($this->evening, 'Famiglia Bianchi');

        $this->expectExceptionObject(new EveningRuleException('Esiste già un gruppo con questo nome.'));
        $this->admin->renameEater($this->evening, $bianchi->getId(), 'famiglia rossi');
    }

    public function testEliminaGruppoSenzaPizze(): void
    {
        $eater = $this->admin->addEater($this->evening, 'Famiglia Rossi');
        $id = $eater->getId();

        $this->admin->deleteEater($this->evening, $id, false);

        self::assertNull($this->em->find(Eater::class, $id));
        self::assertCount(0, $this->evening->getEaters());
    }

    public function testGruppoConPizzeRichiedeConferma(): void
    {
        $eater = $this->admin->addEater($this->evening, 'Famiglia Rossi');
        $this->em->persist(new Pizza($eater, 'Anna', 'Margherita', 700));
        $this->em->flush();

        try {
            $this->admin->deleteEater($this->evening, $eater->getId(), false);
            self::fail('Attesa EveningRuleException');
        } catch (EveningRuleException $e) {
            self::assertSame("Il gruppo ha delle pizze: conferma l'eliminazione.", $e->getMessage());
        }

        $id = $eater->getId();
        $this->admin->deleteEater($this->evening, $id, true);
        self::assertNull($this->em->find(Eater::class, $id));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM pizzas'));
    }

    public function testEliminareIlGruppoOrganizzatoreAzzeraIlRiferimento(): void
    {
        $eater = $this->admin->addEater($this->evening, 'Famiglia Rossi');
        $this->admin->setOrganizerEater($this->evening, $eater->getId());
        self::assertSame($eater, $this->evening->getOrganizerEater());

        $this->admin->deleteEater($this->evening, $eater->getId(), false);

        self::assertNull($this->evening->getOrganizerEater());
        $this->em->refresh($this->evening);
        self::assertNull($this->evening->getOrganizerEater());
    }

    public function testIndicaENonIndicaIlGruppoOrganizzatore(): void
    {
        $eater = $this->admin->addEater($this->evening, 'Famiglia Rossi');

        $this->admin->setOrganizerEater($this->evening, $eater->getId());
        self::assertSame($eater, $this->evening->getOrganizerEater());

        $this->admin->setOrganizerEater($this->evening, null);
        self::assertNull($this->evening->getOrganizerEater());
    }

    public function testGruppoDiAltraSerataNonAccessibile(): void
    {
        $other = $this->persistEvening('Altra serata');
        $foreign = $this->admin->addEater($other, 'Famiglia Verdi');

        foreach ([
            fn () => $this->admin->renameEater($this->evening, $foreign->getId(), 'X'),
            fn () => $this->admin->deleteEater($this->evening, $foreign->getId(), true),
            fn () => $this->admin->setOrganizerEater($this->evening, $foreign->getId()),
            fn () => $this->admin->regenerateEaterToken($this->evening, $foreign->getId()),
        ] as $operation) {
            try {
                $operation();
                self::fail('Attesa EveningRuleException');
            } catch (EveningRuleException $e) {
                self::assertSame('Gruppo non trovato.', $e->getMessage());
            }
        }

        self::assertSame('Famiglia Verdi', $foreign->getName());
        self::assertNull($this->evening->getOrganizerEater());
    }

    public function testRigeneraIlToken(): void
    {
        $eater = $this->admin->addEater($this->evening, 'Famiglia Rossi');
        $old = $eater->getToken();

        $this->admin->regenerateEaterToken($this->evening, $eater->getId());
        $this->em->clear();

        $reloaded = $this->em->find(Eater::class, $eater->getId());
        self::assertNotSame($old, $reloaded->getToken());
    }

    public function testSerataArchiviataInSolaLettura(): void
    {
        $eater = $this->admin->addEater($this->evening, 'Famiglia Rossi');
        $this->evening->setStatus(EveningStatus::Archived);
        $this->em->flush();

        foreach ([
            fn () => $this->admin->addEater($this->evening, 'Nuovo'),
            fn () => $this->admin->renameEater($this->evening, $eater->getId(), 'X'),
            fn () => $this->admin->deleteEater($this->evening, $eater->getId(), true),
            fn () => $this->admin->setOrganizerEater($this->evening, $eater->getId()),
            fn () => $this->admin->regenerateEaterToken($this->evening, $eater->getId()),
        ] as $operation) {
            try {
                $operation();
                self::fail('Attesa EveningRuleException');
            } catch (EveningRuleException $e) {
                self::assertSame('La serata è archiviata: riaprila per modificarla.', $e->getMessage());
            }
        }
    }

    public function testTransizioniSalvate(): void
    {
        $this->admin->closeOrders($this->evening);
        $this->em->clear();
        self::assertSame(EveningStatus::OrdersClosed, $this->reload()->getStatus());

        $this->admin->reopenOrders($this->reload());
        $this->em->clear();
        self::assertSame(EveningStatus::OrdersOpen, $this->reload()->getStatus());
    }

    public function testArchiviaERiapri(): void
    {
        $this->evening->setStatus(EveningStatus::BillEntered);
        $this->em->flush();

        $this->admin->archive($this->evening);
        self::assertSame(EveningStatus::Archived, $this->evening->getStatus());

        $this->admin->unarchive($this->evening);
        self::assertSame(EveningStatus::BillEntered, $this->evening->getStatus());
    }

    public function testDallInvitoCiSiAggiungeSoloConOrdiniAperti(): void
    {
        $eater = $this->admin->addEaterFromInvite($this->evening, '  Famiglia   Rossi ');
        self::assertNotNull($eater->getId());
        self::assertSame('Famiglia Rossi', $eater->getName());

        foreach ([EveningStatus::OrdersClosed, EveningStatus::BillEntered, EveningStatus::Archived] as $status) {
            $this->evening->setStatus($status);
            try {
                $this->admin->addEaterFromInvite($this->evening, 'Famiglia '.$status->value);
                self::fail('Attesa EveningRuleException per '.$status->value);
            } catch (EveningRuleException $e) {
                self::assertSame('Non è più possibile aggiungere gruppi: gli ordini sono chiusi.', $e->getMessage());
            }
        }
        self::assertCount(1, $this->evening->getEaters());
    }

    public function testDallInvitoValgonoLeStesseRegoleSulNome(): void
    {
        $this->admin->addEaterFromInvite($this->evening, 'Famiglia Rossi');

        $this->expectExceptionObject(new EveningRuleException('Esiste già un gruppo con questo nome.'));
        $this->admin->addEaterFromInvite($this->evening, 'famiglia rossi');
    }

    public function testDallInvitoValeIlLimiteDiCinquantaGruppi(): void
    {
        for ($i = 1; $i <= 50; ++$i) {
            $this->admin->addEaterFromInvite($this->evening, 'Gruppo '.$i);
        }

        $this->expectExceptionObject(new EveningRuleException('Una serata può avere al massimo 50 gruppi.'));
        $this->admin->addEaterFromInvite($this->evening, 'Gruppo 51');
    }

    public function testRigeneraIlLinkDInvito(): void
    {
        $old = $this->evening->getInviteToken();

        $this->admin->regenerateInviteToken($this->evening);
        $this->em->clear();

        self::assertNotSame($old, $this->reload()->getInviteToken());
    }

    public function testLinkDInvitoNonRigenerabileDaArchiviata(): void
    {
        $this->evening->setStatus(EveningStatus::Archived);

        $this->expectExceptionObject(new EveningRuleException('La serata è archiviata: riaprila per modificarla.'));
        $this->admin->regenerateInviteToken($this->evening);
    }

    private function persistEvening(string $name): Evening
    {
        $evening = new Evening($name, new \DateTimeImmutable('2026-10-19'), 'Org');
        $this->em->persist($evening);
        $this->em->flush();

        return $evening;
    }

    private function reload(): Evening
    {
        return $this->em->find(Evening::class, $this->evening->getId());
    }
}
