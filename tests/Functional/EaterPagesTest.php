<?php

namespace App\Tests\Functional;

use App\Entity\Eater;
use App\Entity\Evening;
use App\Entity\Pizza;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class EaterPagesTest extends WebTestCase
{
    private KernelBrowser $client;
    private Evening $evening;
    private Eater $rossi;
    private Eater $bianchi;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->evening = new Evening('Pizza da Gino', new \DateTimeImmutable('2026-10-19'), 'Federico', 'Da Gino');
        $this->rossi = new Eater($this->evening, 'Famiglia Rossi');
        $this->bianchi = new Eater($this->evening, 'Famiglia Bianchi');
        new Pizza($this->rossi, 'Anna', 'Margherita', 700);
        new Pizza($this->bianchi, 'Marta', 'Capricciosa', 900);
        $em->persist($this->evening);
        $em->flush();
    }

    public function testPaginaDelGruppo(): void
    {
        $this->client->request('GET', '/g/'.$this->rossi->getToken());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Pizza da Gino');
        self::assertSelectorTextContains('header', 'Da Gino');
        self::assertSelectorTextContains('header', '19/10/2026');
        self::assertSelectorTextContains('header', 'Famiglia Rossi');
        self::assertSelectorExists('[data-live-name-value="PizzaOrder"]');
        self::assertSelectorTextNotContains('main', 'Marta');
        self::assertSelectorTextNotContains('main', 'Famiglia Bianchi');
    }

    public function testPaginaPizzeDelGruppoPerLOrganizzatore(): void
    {
        $this->client->request('GET', sprintf('/s/%s/g/%s', $this->evening->getAdminToken(), $this->bianchi->getToken()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Famiglia Bianchi');
        self::assertSelectorTextContains('main', 'Marta');
        self::assertSelectorExists(sprintf('a[href="/s/%s"]', $this->evening->getAdminToken()));
    }

    public function testGruppoDiAltraSerataDa404(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $other = new Evening('Altra', new \DateTimeImmutable('2026-10-19'), 'Altro');
        $foreign = new Eater($other, 'Famiglia Verdi');
        $em->persist($other);
        $em->flush();

        $this->client->request('GET', sprintf('/s/%s/g/%s', $this->evening->getAdminToken(), $foreign->getToken()));
        self::assertResponseStatusCodeSame(404);
    }

    public function testLaListaGruppiPortaAllePizze(): void
    {
        $crawler = $this->client->request('GET', '/s/'.$this->evening->getAdminToken());

        $link = $crawler->filter('[data-eater-row]')->reduce(fn ($r) => str_contains($r->text(), 'Famiglia Rossi'))->filter('a:contains("Pizze")');
        self::assertSame(sprintf('/s/%s/g/%s', $this->evening->getAdminToken(), $this->rossi->getToken()), $link->attr('href'));
    }
}
