<?php

namespace App\Tests\Functional;

use App\Entity\Eater;
use App\Entity\Evening;
use App\Entity\Pizza;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

final class PizzaListComponentTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private Evening $evening;

    protected function setUp(): void
    {
        self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->evening = new Evening('Serata', new \DateTimeImmutable('2026-10-19'), 'Org');
        $rossi = new Eater($this->evening, 'Famiglia Rossi');
        $bianchi = new Eater($this->evening, 'Famiglia Bianchi');
        $em->persist($this->evening);
        foreach ([[$rossi, 'Anna', 'Margherita', 700], [$rossi, 'Luca', 'margherita', 700], [$bianchi, 'Marta', ' margherita ', 750], [$bianchi, 'Gino', 'Diavola', 800]] as [$eater, $person, $name, $price]) {
            new Pizza($eater, $person, $name, $price);
            $em->flush();
        }
    }

    public function testListaAggregataConTotaleECopia(): void
    {
        $crawler = $this->component()->render()->crawler();

        self::assertSame(['1 x Diavola', '3 x margherita'], $crawler->filter('[data-pizza-line]')->each(fn (Crawler $l) => preg_replace('/\s+/', ' ', trim($l->text()))));
        self::assertStringContainsString('4 pizze', $crawler->filter('[data-pizza-total]')->text());
        self::assertSame("1 x Diavola\n3 x margherita", $crawler->filter('textarea[readonly]')->text(null, false));
        self::assertSame('Copia lista', $crawler->filter('button:contains("Copia lista")')->text());
        self::assertStringContainsString('5000', (string) $crawler->filter('[data-poll]')->attr('data-poll'));
    }

    public function testAvvisoPrezziDiversi(): void
    {
        $alert = $this->component()->render()->crawler()->filter('[data-price-conflicts]');

        self::assertStringContainsString('Prezzi diversi per la stessa pizza', $alert->text());
        self::assertStringContainsString('Famiglia Bianchi', $alert->text());
        self::assertStringContainsString('Marta', $alert->text());
        self::assertStringContainsString('7,50 €', $alert->text());
        self::assertStringContainsString('7,00 €', $alert->text());
    }

    public function testVistaPerGruppo(): void
    {
        $crawler = $this->component()->set('view', 'eaters')->render()->crawler();

        self::assertSame(['Famiglia Bianchi', 'Famiglia Rossi'], $crawler->filter('[data-list-eater]')->each(fn (Crawler $n) => $n->text()));
        self::assertCount(0, $crawler->filter('textarea[readonly]'));
    }

    public function testSenzaPizze(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $empty = new Evening('Vuota', new \DateTimeImmutable('2026-10-19'), 'Org');
        $em->persist($empty);
        $em->flush();

        $crawler = $this->createLiveComponent('PizzaList', ['evening' => $empty])->render()->crawler();

        self::assertStringContainsString('Ancora nessuna pizza', $crawler->text());
        self::assertCount(0, $crawler->filter('[data-price-conflicts]'));
    }

    public function testPresenteNellaPaginaAdmin(): void
    {
        self::getClient()->request('GET', '/s/'.$this->evening->getAdminToken());

        self::assertSelectorExists('[data-live-name-value="PizzaList"]');
    }

    private function component(): TestLiveComponent
    {
        return $this->createLiveComponent('PizzaList', ['evening' => $this->evening]);
    }
}
