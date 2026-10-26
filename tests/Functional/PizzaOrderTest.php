<?php

namespace App\Tests\Functional;

use App\Entity\Eater;
use App\Entity\Evening;
use App\Entity\Pizza;
use App\Enum\EveningStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

final class PizzaOrderTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private EntityManagerInterface $em;
    private Evening $evening;
    private Eater $rossi;
    private Eater $bianchi;

    protected function setUp(): void
    {
        self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->evening = new Evening('Pizza da Gino', new \DateTimeImmutable('2026-10-19'), 'Federico');
        $this->rossi = new Eater($this->evening, 'Famiglia Rossi');
        $this->bianchi = new Eater($this->evening, 'Famiglia Bianchi');
        $this->em->persist($this->evening);
        $this->em->flush();
    }

    public function testElencoPerPersonaConTotaliEPolling(): void
    {
        $this->pizza($this->rossi, 'Luca', 'Diavola', 800);
        $this->pizza($this->rossi, 'Anna', 'Margherita', 700);
        $this->pizza($this->rossi, 'Anna', 'Marinara', 650);

        $crawler = $this->component()->render()->crawler();

        self::assertSame(['Anna', 'Luca'], $crawler->filter('[data-person-name]')->each(fn (Crawler $n) => $n->text()));
        self::assertStringContainsString('13,50 €', $this->personBlock($crawler, 'Anna')->filter('[data-person-total]')->text());
        self::assertStringContainsString('8,00 €', $this->personBlock($crawler, 'Luca')->filter('[data-person-total]')->text());
        self::assertStringContainsString('21,50 €', $crawler->filter('[data-eater-total]')->text());
        self::assertStringContainsString('5000', (string) $crawler->filter('[data-poll]')->attr('data-poll'));
    }

    public function testAggiungeUnaPizzaEMantieneLaPersona(): void
    {
        $component = $this->component();
        $component->set('person', 'Anna')->set('pizzaName', ' margherita ')->set('price', '7,5')->call('add');
        $crawler = $component->render()->crawler();

        self::assertStringContainsString('margherita', $this->personBlock($crawler, 'Anna')->text());
        self::assertStringContainsString('7,50 €', $this->personBlock($crawler, 'Anna')->text());
        self::assertSame('Anna', $component->component()->person);
        self::assertSame('', $component->component()->pizzaName);
        self::assertSame('', $component->component()->price);
        self::assertCount(1, $this->em->getRepository(Pizza::class)->findBy(['eater' => $this->rossi]));
    }

    public function testErroreDiValidazioneMantieneIDati(): void
    {
        $component = $this->component();
        $crawler = $component->set('person', 'Anna')->set('pizzaName', 'Margherita')->set('price', 'tanto')->call('add')->render()->crawler();

        self::assertStringContainsString('Inserisci un prezzo valido, per esempio 7,50.', $crawler->filter('.alert-danger')->text());
        self::assertSame('Margherita', $component->component()->pizzaName);
        self::assertSame('tanto', $component->component()->price);
    }

    public function testOrdiniChiusiInSolaLetturaPerIlGruppo(): void
    {
        $this->pizza($this->rossi, 'Anna', 'Margherita', 700);
        $this->evening->setStatus(EveningStatus::OrdersClosed);
        $this->em->flush();

        $crawler = $this->component()->render()->crawler();

        self::assertStringContainsString("Ordini chiusi dall'organizzatore", $crawler->text());
        self::assertCount(0, $crawler->filter('input'));
        self::assertCount(0, $crawler->filter('button'));
        self::assertStringContainsString('Margherita', $crawler->text());
    }

    public function testOrganizzatoreModificaAncheAOrdiniChiusi(): void
    {
        $this->evening->setStatus(EveningStatus::OrdersClosed);
        $this->em->flush();

        $component = $this->component(asOrganizer: true);
        $crawler = $component->set('person', 'Anna')->set('pizzaName', 'Margherita')->set('price', '7')->call('add')->render()->crawler();

        self::assertStringNotContainsString("Ordini chiusi dall'organizzatore", $crawler->text());
        self::assertStringContainsString('Margherita', $this->personBlock($crawler, 'Anna')->text());
    }

    public function testModificaEdEliminazione(): void
    {
        $pizza = $this->pizza($this->rossi, 'Anna', 'Margherita', 700);
        $component = $this->component();

        $component->call('startEdit', ['pizzaId' => $pizza->getId()]);
        self::assertSame('7,00', $component->component()->editPrice);

        $crawler = $component->set('editPizzaName', 'Bufala')->set('editPrice', '9')->call('save')->render()->crawler();
        self::assertStringContainsString('Bufala', $crawler->text());
        self::assertStringContainsString('9,00 €', $crawler->text());
        self::assertNull($component->component()->editingId);

        $deleteButton = $crawler->filter('button:contains("Elimina")');
        self::assertNotEmpty($deleteButton->attr('data-confirm-message-param'));

        $crawler = $component->call('delete', ['pizzaId' => $pizza->getId()])->render()->crawler();
        self::assertStringContainsString('Nessuna pizza', $crawler->text());
    }

    public function testSuggerimentiSenzaDatiDegliAltriGruppi(): void
    {
        $this->pizza($this->rossi, 'Anna', 'Margherita', 700);
        $this->pizza($this->bianchi, 'Marta', 'Capricciosa', 900);

        $crawler = $this->component()->render()->crawler();

        $pizzaOptions = $crawler->filter('datalist[data-pizza-suggestions] option')->each(fn (Crawler $o) => $o->attr('value'));
        self::assertSame(['Capricciosa', 'Margherita'], $pizzaOptions);
        $personOptions = $crawler->filter('datalist[data-person-suggestions] option')->each(fn (Crawler $o) => $o->attr('value'));
        self::assertSame(['Anna'], $personOptions);

        $prices = json_decode((string) $crawler->filter('[data-pizza-price-prices-value]')->attr('data-pizza-price-prices-value'), true);
        self::assertSame(['capricciosa' => '9,00', 'margherita' => '7,00'], $prices);

        self::assertStringNotContainsString('Marta', $crawler->html());
        self::assertStringNotContainsString('Famiglia Bianchi', $crawler->html());
    }

    private function component(bool $asOrganizer = false): TestLiveComponent
    {
        return $this->createLiveComponent('PizzaOrder', ['eater' => $this->rossi, 'asOrganizer' => $asOrganizer]);
    }

    private function pizza(Eater $eater, string $person, string $name, int $cents): Pizza
    {
        $pizza = new Pizza($eater, $person, $name, $cents);
        $this->em->persist($pizza);
        $this->em->flush();

        return $pizza;
    }

    private function personBlock(Crawler $crawler, string $person): Crawler
    {
        return $crawler->filter('[data-person-block]')->reduce(fn (Crawler $b) => $b->filter('[data-person-name]')->text() === $person)->first();
    }
}
