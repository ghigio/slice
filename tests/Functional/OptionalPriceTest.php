<?php

namespace App\Tests\Functional;

use App\Entity\Eater;
use App\Entity\Evening;
use App\Entity\Pizza;
use App\Enum\EveningStatus;
use App\Twig\Components\MissingPrices;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class OptionalPriceTest extends WebTestCase
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
        $this->evening = new Evening('Serata', new \DateTimeImmutable('2026-10-19'), 'Org');
        $this->rossi = new Eater($this->evening, 'Famiglia Rossi');
        $this->bianchi = new Eater($this->evening, 'Famiglia Bianchi');
        $this->em->persist($this->evening);
        $this->em->flush();
    }

    public function testLOrganizzatoreAggiungeUnaPizzaSenzaPrezzo(): void
    {
        $component = $this->createLiveComponent('PizzaOrder', ['eater' => $this->rossi, 'asOrganizer' => true]);
        $crawler = $component->render()->crawler();
        self::assertStringContainsString('facoltativo', $crawler->filter('label[for$="-price"]')->text());
        self::assertNull($crawler->filter('input[data-model$="|price"]')->attr('required'));

        $crawler = $component->set('person', 'Anna')->set('pizzaName', 'Margherita')->set('price', '')->call('add')->render()->crawler();

        self::assertStringContainsString('Prezzo da definire', $crawler->filter('[data-person-block]')->text());
        self::assertSame('da definire', $crawler->filter('[data-person-total]')->text());
        self::assertCount(1, $this->em->getRepository(Pizza::class)->findBy(['eater' => $this->rossi]));
    }

    public function testTotaliParzialiConPizzeSenzaPrezzo(): void
    {
        new Pizza($this->rossi, 'Anna', 'Margherita', 700);
        new Pizza($this->rossi, 'Anna', 'Diavola', null);
        $this->em->flush();

        $crawler = $this->createLiveComponent('PizzaOrder', ['eater' => $this->rossi, 'asOrganizer' => true])->render()->crawler();

        self::assertStringContainsString('7,00 € + 1 da definire', $crawler->filter('[data-person-total]')->text());
        self::assertStringContainsString('1 pizza senza prezzo', $crawler->filter('[data-eater-total]')->text());
    }

    public function testIlContoNonSiInserisceConPizzeSenzaPrezzo(): void
    {
        new Pizza($this->rossi, 'Anna', 'Margherita', null);
        new Pizza($this->rossi, 'Luca', 'Diavola', 800);
        $this->evening->setStatus(EveningStatus::OrdersClosed);
        $this->em->flush();

        $crawler = $this->createLiveComponent('BillForm', ['evening' => $this->evening])->set('paidTotal', '15')->render()->crawler();

        self::assertStringContainsString("C'è 1 pizza senza prezzo: completale prima di inserire il conto.", $crawler->filter('.alert-danger')->text());
        self::assertCount(0, $crawler->filter('button[data-live-action-param="save"]'));
    }

    public function testRiquadroPizzeSenzaPrezzo(): void
    {
        new Pizza($this->rossi, 'Anna', 'Margherita', null);
        new Pizza($this->bianchi, 'Gino', 'margherita', null);
        new Pizza($this->bianchi, 'Marta', 'Diavola', 800);
        $this->em->flush();

        $component = $this->createLiveComponent('MissingPrices', ['evening' => $this->evening]);
        $crawler = $component->render()->crawler();

        // «Margherita» e «margherita» una volta ciascuna: si mostra la forma più recente, come nella lista per il pizzaiolo.
        self::assertSame(['margherita'], $crawler->filter('[data-missing-name]')->each(fn (Crawler $n) => $n->text()));
        self::assertStringContainsString('2 pizze', $crawler->filter('[data-missing-row]')->text());

        $crawler = $component->set('prices', [MissingPrices::key('margherita') => '7'])->call('apply', ['normalizedName' => 'margherita'])->render()->crawler();

        self::assertCount(0, $crawler->filter('[data-missing-row]'));
        self::assertStringContainsString('Prezzo applicato a 2 pizze', $crawler->text());
        self::assertSame([700, 700, 800], array_map(fn (Pizza $p) => $p->getPriceCents(), $this->em->getRepository(Pizza::class)->findBy([], ['id' => 'ASC'])));
    }

    public function testIlPrezzoDigitatoNelBrowserArrivaAlServer(): void
    {
        new Pizza($this->rossi, 'Anna', 'Margherita', null);
        new Pizza($this->bianchi, 'Marta', 'Diavola', 800);
        new Pizza($this->bianchi, 'Gino', 'Bufala', null);
        $this->em->flush();

        $html = $this->createLiveComponent('MissingPrices', ['evening' => $this->evening])->render()->toString();
        $crawler = new Crawler($html);
        $props = json_decode(html_entity_decode($crawler->filter('[data-live-props-value]')->attr('data-live-props-value')), true);

        // Il live controller accetta solo modelli già presenti nelle props: ogni campo deve avere la sua chiave.
        $models = $crawler->filter('input[data-model]')->each(fn (Crawler $n) => substr((string) $n->attr('data-model'), \strlen('norender|')));
        self::assertCount(2, $models);
        foreach ($models as $model) {
            self::assertArrayHasKey(substr($model, \strlen('prices.')), $props['prices'], $model.' non inizializzato');
        }

        $client = self::getClient();
        $client->request('POST', '/_components/MissingPrices/apply', [
            'data' => json_encode(['props' => $props, 'updated' => ['prices.'.MissingPrices::key('margherita') => '7'], 'args' => ['normalizedName' => 'margherita']]),
        ], [], ['HTTP_ACCEPT' => 'application/vnd.live-component+html', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Inserisci il prezzo.', $client->getResponse()->getContent());
        self::assertStringContainsString('Prezzo applicato a 1 pizza', $client->getResponse()->getContent());
        self::assertSame([700, 800, null], array_map(fn (Pizza $p) => $p->getPriceCents(), $this->em->getRepository(Pizza::class)->findBy([], ['id' => 'ASC'])));
    }

    public function testRiquadroVuotoSenzaPizzeDaCompletareEPresenteInAdmin(): void
    {
        new Pizza($this->rossi, 'Anna', 'Margherita', 700);
        $this->em->flush();

        self::assertCount(0, $this->createLiveComponent('MissingPrices', ['evening' => $this->evening])->render()->crawler()->filter('[data-missing-row]'));

        self::getClient()->request('GET', '/s/'.$this->evening->getAdminToken());
        self::assertSelectorExists('[data-live-name-value="MissingPrices"]');
    }
}
