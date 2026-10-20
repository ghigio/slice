<?php

namespace App\Tests\Integration;

use App\Entity\Eater;
use App\Entity\Evening;
use App\Entity\Pizza;
use App\Enum\DiscountMode;
use App\Enum\EveningStatus;
use App\Repository\EaterRepository;
use App\Repository\EveningRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EntityPersistenceTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testPersisteIlGrafoCompleto(): void
    {
        $evening = new Evening('Pizza da Gino', new \DateTimeImmutable('2026-10-19'), 'Federico', 'Da Gino', 'IBAN IT00X000');
        $rossi = new Eater($evening, 'Famiglia Rossi');
        $bianchi = new Eater($evening, 'Famiglia Bianchi');
        $evening->setOrganizerEater($rossi);
        new Pizza($rossi, 'Anna', 'Margherita', 700);
        new Pizza($rossi, 'Luca', '  quattro   stagioni ', 850);
        new Pizza($bianchi, 'Marta', 'Würstel e patatine', 900);

        $this->em->persist($evening);
        $this->em->flush();
        $this->em->clear();

        $loaded = self::getContainer()->get(EveningRepository::class)->findOneByAdminToken($evening->getAdminToken());

        self::assertNotNull($loaded);
        self::assertSame('Pizza da Gino', $loaded->getName());
        self::assertSame('2026-10-19', $loaded->getDate()->format('Y-m-d'));
        self::assertSame('Da Gino', $loaded->getPizzeria());
        self::assertSame('Federico', $loaded->getOrganizerName());
        self::assertSame('IBAN IT00X000', $loaded->getRefundNotes());
        self::assertSame(EveningStatus::OrdersOpen, $loaded->getStatus());
        self::assertSame(DiscountMode::Proportional, $loaded->getDiscountMode());
        self::assertNull($loaded->getPaidTotalCents());
        self::assertSame('Famiglia Rossi', $loaded->getOrganizerEater()?->getName());
        self::assertNotNull($loaded->getCreatedAt());
        self::assertNotNull($loaded->getUpdatedAt());

        $eaters = $loaded->getEaters();
        self::assertSame(['Famiglia Bianchi', 'Famiglia Rossi'], $eaters->map(fn (Eater $e) => $e->getName())->getValues());

        $pizzas = $eaters->last()->getPizzas();
        self::assertCount(2, $pizzas);
        self::assertSame('quattro stagioni', $pizzas[1]->getPizzaName());
        self::assertSame('quattro stagioni', $pizzas[1]->getNormalizedName());
        self::assertSame(850, $pizzas[1]->getPriceCents());
        self::assertSame('wurstel e patatine', $eaters->first()->getPizzas()->first()->getNormalizedName());
    }

    public function testNomiPulitiDagliSpazi(): void
    {
        $evening = new Evening('Serata', new \DateTimeImmutable('2026-10-19'), 'Org');
        $eater = new Eater($evening, '  Famiglia   Rossi ');
        $pizza = new Pizza($eater, '  Anna  Maria ', ' Margherita ', 700);

        self::assertSame('Famiglia Rossi', $eater->getName());
        self::assertSame('Anna Maria', $pizza->getPerson());
        self::assertSame('Margherita', $pizza->getPizzaName());
        self::assertSame('margherita', $pizza->getNormalizedName());
    }

    public function testTokenCasualiDa64CaratteriEsadecimali(): void
    {
        $evening = new Evening('Serata', new \DateTimeImmutable('2026-10-19'), 'Org');
        $a = new Eater($evening, 'A');
        $b = new Eater($evening, 'B');

        foreach ([$evening->getAdminToken(), $a->getToken(), $b->getToken()] as $token) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        }
        self::assertCount(3, array_unique([$evening->getAdminToken(), $a->getToken(), $b->getToken()]));
    }

    public function testRigenerareIlTokenInvalidaIlPrecedente(): void
    {
        $evening = new Evening('Serata', new \DateTimeImmutable('2026-10-19'), 'Org');
        $eater = new Eater($evening, 'Famiglia Rossi');
        $this->em->persist($evening);
        $this->em->flush();

        $oldToken = $eater->getToken();
        $eater->regenerateToken();
        $this->em->flush();
        $this->em->clear();

        $repository = self::getContainer()->get(EaterRepository::class);
        self::assertNotSame($oldToken, $eater->getToken());
        self::assertNull($repository->findOneByToken($oldToken));
        self::assertSame('Famiglia Rossi', $repository->findOneByToken($eater->getToken())?->getName());
    }

    public function testCancellareLaSerataCancellaGruppiEPizzeACascata(): void
    {
        $evening = new Evening('Serata', new \DateTimeImmutable('2026-10-19'), 'Org');
        $eater = new Eater($evening, 'Famiglia Rossi');
        $evening->setOrganizerEater($eater);
        new Pizza($eater, 'Anna', 'Margherita', 700);
        $this->em->persist($evening);
        $this->em->flush();

        $connection = $this->em->getConnection();
        $connection->executeStatement('DELETE FROM evenings WHERE id = ?', [$evening->getId()]);

        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM eaters'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM pizzas'));
    }

    public function testCancellareIlGruppoOrganizzatoreAzzeraIlRiferimento(): void
    {
        $evening = new Evening('Serata', new \DateTimeImmutable('2026-10-19'), 'Org');
        $eater = new Eater($evening, 'Famiglia Rossi');
        $evening->setOrganizerEater($eater);
        new Pizza($eater, 'Anna', 'Margherita', 700);
        $this->em->persist($evening);
        $this->em->flush();

        $connection = $this->em->getConnection();
        $connection->executeStatement('DELETE FROM eaters WHERE id = ?', [$eater->getId()]);

        self::assertNull($connection->fetchOne('SELECT organizer_eater_id FROM evenings WHERE id = ?', [$evening->getId()]));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM pizzas'));
    }

    public function testNomeGruppoUnicoNellaSerata(): void
    {
        $evening = new Evening('Serata', new \DateTimeImmutable('2026-10-19'), 'Org');
        new Eater($evening, 'Famiglia Rossi');
        new Eater($evening, 'Famiglia  Rossi ');
        $this->em->persist($evening);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testStessoNomeGruppoAmmessoInSerateDiverse(): void
    {
        $first = new Evening('Serata 1', new \DateTimeImmutable('2026-10-19'), 'Org');
        $second = new Evening('Serata 2', new \DateTimeImmutable('2026-10-20'), 'Org');
        new Eater($first, 'Famiglia Rossi');
        new Eater($second, 'Famiglia Rossi');
        $this->em->persist($first);
        $this->em->persist($second);
        $this->em->flush();

        self::assertSame(2, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM eaters WHERE name = 'Famiglia Rossi'"));
    }
}
