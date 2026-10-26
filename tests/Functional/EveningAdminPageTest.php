<?php

namespace App\Tests\Functional;

use App\Entity\Eater;
use App\Entity\Evening;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class EveningAdminPageTest extends WebTestCase
{
    public function testLaPaginaAdminMostraStatoEGruppiDellaSolaSerata(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $mine = new Evening('Pizza da Gino', new \DateTimeImmutable('2026-10-19'), 'Federico');
        new Eater($mine, 'Famiglia Rossi');
        $other = new Evening('Altra serata', new \DateTimeImmutable('2026-10-19'), 'Altro');
        new Eater($other, 'Famiglia Verdi');
        $em->persist($mine);
        $em->persist($other);
        $em->flush();

        $client->request('GET', '/s/'.$mine->getAdminToken());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-controller~="live"][data-live-name-value="EveningStatusPanel"]');
        self::assertSelectorExists('[data-controller~="live"][data-live-name-value="EaterManager"]');
        self::assertSelectorTextContains('.badge', 'Ordini aperti');
        self::assertSelectorTextContains('[data-eater-name]', 'Famiglia Rossi');
        self::assertSelectorTextNotContains('main', 'Famiglia Verdi');
    }
}
