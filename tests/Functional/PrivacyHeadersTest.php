<?php

namespace App\Tests\Functional;

use App\Entity\Eater;
use App\Entity\Evening;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PrivacyHeadersTest extends WebTestCase
{
    public function testLaHomeEIndicizzabile(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertFalse($client->getResponse()->headers->has('X-Robots-Tag'));
        self::assertSelectorNotExists('meta[name="robots"]');
    }

    public function testLeAltrePagineNonSonoIndicizzabili(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $evening = new Evening('Serata', new \DateTimeImmutable('2026-10-19'), 'Org');
        $eater = new Eater($evening, 'Famiglia Rossi');
        $em->persist($evening);
        $em->flush();

        foreach (['/nuova-serata', '/s/'.$evening->getAdminToken(), '/g/'.$eater->getToken(), sprintf('/s/%s/g/%s', $evening->getAdminToken(), $eater->getToken())] as $url) {
            $client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
            self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow', $url);
            self::assertSelectorExists('meta[name="robots"][content="noindex, nofollow"]', $url);
        }
    }

    public function testAncheGliErroriNonSonoIndicizzabili(): void
    {
        $client = self::createClient();
        $client->request('GET', '/g/'.str_repeat('0', 64));

        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
    }

    public function testPagina404GenericaInItaliano(): void
    {
        $client = self::createClient(['debug' => false]);
        $client->request('GET', '/s/'.str_repeat('a', 64));

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('h1', 'Pagina non trovata');
        self::assertSelectorTextContains('main', 'Il link non è valido o non è più attivo');
        self::assertSelectorTextNotContains('main', 'Evening');
    }
}
