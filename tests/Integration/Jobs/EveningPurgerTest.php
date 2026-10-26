<?php

namespace App\Tests\Integration\Jobs;

use App\Entity\Eater;
use App\Entity\Evening;
use App\Entity\Pizza;
use App\Jobs\EveningPurger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EveningPurgerTest extends KernelTestCase
{
    public function testCancellaLeSerateDaNovantaGiorniInPoi(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $today = new \DateTimeImmutable('2026-10-26');

        $evenings = [];
        foreach (['-91 days' => true, '-90 days' => true, '-89 days' => false, '+5 days' => false] as $offset => $deleted) {
            $evening = new Evening('Serata '.$offset, $today->modify($offset), 'Org');
            $eater = new Eater($evening, 'Famiglia');
            $evening->setOrganizerEater($eater);
            new Pizza($eater, 'Anna', 'Margherita', 700);
            $em->persist($evening);
            $evenings[$offset] = [$evening, $deleted];
        }
        $em->flush();
        $ids = array_map(fn ($e) => $e[0]->getId(), $evenings);

        $count = (new EveningPurger($em))->purge($today);

        self::assertSame(2, $count);
        $em->clear();
        foreach ($evenings as $offset => [, $deleted]) {
            self::assertSame($deleted, null === $em->find(Evening::class, $ids[$offset]), $offset);
        }
        $connection = $em->getConnection();
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM eaters'));
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM pizzas'));
    }
}
