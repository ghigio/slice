<?php

namespace App\Tests\Integration;

use App\Entity\Eater;
use App\Entity\Evening;
use App\Entity\Pizza;
use App\Repository\PizzaRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PizzaSuggestionsTest extends KernelTestCase
{
    public function testNomiDellaSerataConFormaPiuFrequenteEUltimoPrezzo(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $evening = new Evening('Serata', new \DateTimeImmutable('2026-10-19'), 'Org');
        $rossi = new Eater($evening, 'Famiglia Rossi');
        $bianchi = new Eater($evening, 'Famiglia Bianchi');
        $other = new Evening('Altra', new \DateTimeImmutable('2026-10-19'), 'Org');
        $verdi = new Eater($other, 'Famiglia Verdi');
        $em->persist($evening);
        $em->persist($other);

        new Pizza($rossi, 'Anna', 'margherita', 650);
        $em->flush();
        new Pizza($bianchi, 'Marta', 'Margherita', 700);
        $em->flush();
        new Pizza($bianchi, 'Luca', 'Margherita ', 700);
        $em->flush();
        new Pizza($rossi, 'Anna', 'MARGHERITA', 720);
        $em->flush();
        new Pizza($rossi, 'Paolo', 'Diavola', 800);
        new Pizza($verdi, 'Gino', 'Capricciosa', 900);
        $em->flush();

        $suggestions = self::getContainer()->get(PizzaRepository::class)->suggestionsForEvening($evening);

        self::assertSame([
            ['name' => 'Diavola', 'priceCents' => 800],
            ['name' => 'Margherita', 'priceCents' => 720],
        ], $suggestions);
    }

    public function testAParitaDiFrequenzaVinceLaFormaPiuRecente(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $evening = new Evening('Serata', new \DateTimeImmutable('2026-10-19'), 'Org');
        $rossi = new Eater($evening, 'Famiglia Rossi');
        $em->persist($evening);
        foreach (['Diavola', 'diavola', 'DIAVOLA', 'Diavola', 'diavola'] as $form) {
            new Pizza($rossi, 'Anna', $form, 800);
            $em->flush();
        }

        $suggestions = self::getContainer()->get(PizzaRepository::class)->suggestionsForEvening($evening);

        self::assertSame('diavola', $suggestions[0]['name']);
    }

    public function testPersoneDelSoloGruppo(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $evening = new Evening('Serata', new \DateTimeImmutable('2026-10-19'), 'Org');
        $rossi = new Eater($evening, 'Famiglia Rossi');
        $bianchi = new Eater($evening, 'Famiglia Bianchi');
        new Pizza($rossi, 'Luca', 'Margherita', 700);
        new Pizza($rossi, 'Anna', 'Diavola', 800);
        new Pizza($rossi, 'Luca', 'Marinara', 600);
        new Pizza($bianchi, 'Marta', 'Margherita', 700);
        $em->persist($evening);
        $em->flush();

        self::assertSame(['Anna', 'Luca'], self::getContainer()->get(PizzaRepository::class)->personsForEater($rossi));
    }

    public function testPizzeSenzaPrezzoNeiSuggerimentiENelRiepilogo(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $evening = new Evening('Serata', new \DateTimeImmutable('2026-10-19'), 'Org');
        $rossi = new Eater($evening, 'Famiglia Rossi');
        $em->persist($evening);
        new Pizza($rossi, 'Anna', 'Margherita', 700);
        $em->flush();
        new Pizza($rossi, 'Luca', 'margherita', null);
        new Pizza($rossi, 'Luca', 'Margherita', null);
        new Pizza($rossi, 'Sara', 'Diavola', null);
        $em->flush();

        $repository = self::getContainer()->get(PizzaRepository::class);

        // Il suggerimento propone l'ultimo prezzo noto; una pizza mai prezzata compare senza prezzo.
        self::assertSame([
            ['name' => 'Diavola', 'priceCents' => null],
            ['name' => 'Margherita', 'priceCents' => 700],
        ], $repository->suggestionsForEvening($evening));

        self::assertSame([
            ['name' => 'Diavola', 'normalizedName' => 'diavola', 'quantity' => 1],
            ['name' => 'Margherita', 'normalizedName' => 'margherita', 'quantity' => 2],
        ], $repository->missingPricesForEvening($evening));
    }
}
