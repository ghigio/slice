<?php

namespace App\Tests\Functional;

use App\Entity\Eater;
use App\Entity\Evening;
use App\Entity\Pizza;
use App\Enum\DiscountMode;
use App\Enum\EveningStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Requisito: pagine principali sotto 500 ms con 300 pizze (misura indicativa, senza debug).
 */
final class PerformanceTest extends WebTestCase
{
    public function testPagineConTrecentoPizze(): void
    {
        $client = self::createClient(['debug' => false]);
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $evening = new Evening('Serata grande', new \DateTimeImmutable('2026-10-19'), 'Org');
        $names = ['Margherita', 'Diavola', 'Capricciosa', 'Marinara', 'Quattro stagioni', 'Bufala'];
        for ($g = 0; $g < 50; ++$g) {
            $eater = new Eater($evening, 'Gruppo '.$g);
            for ($p = 0; $p < 6; ++$p) {
                new Pizza($eater, 'Persona '.$p, $names[($g + $p) % 6], 600 + 50 * $p);
            }
        }
        $evening->setOrganizerEater($eater);
        $evening->setStatus(EveningStatus::OrdersClosed);
        $evening->enterBill(170000, DiscountMode::Proportional);
        $em->persist($evening);
        $em->flush();

        $urls = ['/s/'.$evening->getAdminToken(), '/g/'.$eater->getToken()];
        foreach ($urls as $url) {
            $client->request('GET', $url); // riscaldamento della cache
        }

        foreach ($urls as $url) {
            $start = hrtime(true);
            $client->request('GET', $url);
            $elapsedMs = (hrtime(true) - $start) / 1e6;

            self::assertResponseIsSuccessful();
            self::assertLessThan(500, $elapsedMs, sprintf('%s: %.0f ms', $url, $elapsedMs));
        }
    }
}
