<?php

namespace App\Tests\Functional;

use App\Entity\Eater;
use App\Entity\Evening;
use App\Entity\Pizza;
use App\Enum\EveningStatus;
use App\Repository\EveningRepository;
use App\Twig\Components\MissingPrices;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * Criteri di accettazione di docs/REQUISITI.md, percorsi dal punto di vista di organizzatore e gruppi.
 */
final class AcceptanceTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testSerataCompletaDallaCreazioneAiRimborsi(): void
    {
        // Si crea una serata dalla home e si ottiene il link admin con avviso di salvataggio.
        $crawler = $this->client->request('GET', '/');
        $crawler = $this->client->click($crawler->selectLink('Crea una serata')->link());
        $form = $crawler->selectButton('Crea la serata')->form([
            'new_evening[name]' => 'Pizza da Gino',
            'new_evening[date]' => '2026-10-19',
            'new_evening[organizerName]' => 'Federico',
            'new_evening[refundNotes]' => 'Satispay 333 1234567',
        ]);
        $this->client->submit($form);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.alert', "Salva questo link, è l'unico modo per gestire la serata");
        $adminUrl = $crawler->filter('#admin-link')->attr('value');
        $evening = self::getContainer()->get(EveningRepository::class)->findOneByAdminToken(basename($adminUrl));

        // Si creano gruppi, se ne indica uno come proprio e si condividono i link (copia e WhatsApp).
        $eaters = $this->createLiveComponent('EaterManager', ['evening' => $evening]);
        $eaters->set('newName', 'Famiglia A')->call('add');
        $eaters->set('newName', 'Famiglia B')->call('add');
        $a = $this->eater($evening, 'Famiglia A');
        $b = $this->eater($evening, 'Famiglia B');
        $crawler = $eaters->call('setOrganizer', ['eaterId' => $a->getId()])->render()->crawler();
        self::assertStringContainsString('Il tuo gruppo', $crawler->filter('#eater-'.$a->getId())->text());
        self::assertStringStartsWith('https://wa.me/?text=', $crawler->filter('#eater-'.$b->getId().' a:contains("WhatsApp")')->attr('href'));
        self::assertSame('http://localhost/g/'.$b->getToken(), $crawler->filter('#link-'.$b->getId())->attr('value'));

        // Due membri dello stesso gruppo inseriscono pizze e vedono quelle dell'altro al prossimo aggiornamento (15 s).
        $memberOne = $this->createLiveComponent('PizzaOrder', ['eater' => $a]);
        $memberTwo = $this->createLiveComponent('PizzaOrder', ['eater' => $a]);
        $memberOne->set('person', 'Anna')->set('pizzaName', 'Margherita')->call('add');
        $memberTwo->set('person', 'Luca')->set('pizzaName', 'Diavola')->call('add');
        $seenByOne = $memberOne->refresh()->render()->crawler();
        self::assertStringContainsString('Diavola', $seenByOne->text());
        self::assertStringContainsString('15000', $seenByOne->filter('[data-poll]')->attr('data-poll'));

        $groupB = $this->createLiveComponent('PizzaOrder', ['eater' => $b]);
        $groupB->set('person', 'Marta')->set('pizzaName', 'Bufala')->call('add');
        $groupB->set('person', 'Marta')->set('pizzaName', ' margherita ')->call('add');

        // La lista per il pizzaiolo aggrega «Margherita» e « margherita » in una sola riga e si copia come testo.
        $list = $this->createLiveComponent('PizzaList', ['evening' => $evening])->render()->crawler();
        self::assertSame("1 x Bufala\n1 x Diavola\n2 x margherita", $list->filter('textarea[readonly]')->text(null, false));

        // Con ordini chiusi i gruppi non possono aggiungere o modificare pizze.
        $this->createLiveComponent('EveningStatusPanel', ['evening' => $evening])->call('closeOrders');
        $blocked = $this->createLiveComponent('PizzaOrder', ['eater' => $b])->set('person', 'Marta')->set('pizzaName', 'Extra')->set('price', '5')->call('add')->render()->crawler();
        self::assertStringContainsString("Ordini chiusi dall'organizzatore", $blocked->text());
        self::assertSame(4, $this->pizzaCount());

        // I prezzi li inserisce l'organizzatore: una volta per nome, poi corregge la singola pizza (caso 1).
        // Il percorso completo supera il limite ridotto dei Live Components in ambiente test: si azzerano i contatori.
        self::getContainer()->get('rate_limiter.cache')->clear();
        $missing = $this->createLiveComponent('MissingPrices', ['evening' => $evening]);
        foreach (['margherita' => '7', 'diavola' => '8', 'bufala' => '12'] as $name => $price) {
            $missing->set('prices', [MissingPrices::key($name) => $price])->call('apply', ['normalizedName' => $name]);
        }
        $margheritaB = $this->em->getRepository(Pizza::class)->findOneBy(['eater' => $b, 'normalizedName' => 'margherita']);
        $this->createLiveComponent('PizzaOrder', ['eater' => $b, 'asOrganizer' => true])
            ->call('startEdit', ['pizzaId' => $margheritaB->getId()])->set('editPrice', '6,50')->call('save');

        // Inserito il totale pagato, ogni gruppo vede listino, scontato, totale per persona e da rimborsare (caso 1).
        $this->createLiveComponent('BillForm', ['evening' => $evening])->set('paidTotal', '30')->call('save');
        $billB = $this->createLiveComponent('PizzaOrder', ['eater' => $b])->render()->crawler()->filter('[data-bill-view]');
        self::assertStringContainsString('16,57 €', $billB->filter('[data-amount-due]')->text());
        self::assertStringContainsString('10,75 €', $billB->text());
        self::assertStringContainsString('5,82 €', $billB->text());
        $billA = $this->createLiveComponent('PizzaOrder', ['eater' => $a])->render()->crawler()->filter('[data-bill-view]');
        self::assertStringContainsString('13,43 €', $billA->text());
        self::assertStringContainsString('Nessun rimborso dovuto', $billA->text());

        // Cambiando modalità o totale, tutte le quote si aggiornano.
        $this->createLiveComponent('BillForm', ['evening' => $evening])->set('paidTotal', '31,50')->set('mode', 'equal')->call('save');
        self::assertStringContainsString('17,50 €', $this->createLiveComponent('PizzaOrder', ['eater' => $b])->render()->crawler()->filter('[data-amount-due]')->text());

        // La differenza da arrotondamento compare solo nella pagina admin.
        $summary = $this->createLiveComponent('BillSummaryTable', ['evening' => $evening]);
        self::assertStringContainsString('Nessuna differenza', $summary->render()->crawler()->filter('[data-rounding-difference]')->text());
        $this->client->request('GET', '/g/'.$b->getToken());
        self::assertSelectorTextNotContains('main', 'Differenza');

        // L'organizzatore segna i rimborsi e vede ricevuto e mancante; il gruppo vede «Rimborsato il …».
        $crawler = $summary->call('toggleReimbursed', ['eaterId' => $b->getId(), 'reimbursed' => true])->render()->crawler();
        self::assertStringContainsString('17,50 €', $crawler->filter('[data-received]')->text());
        self::assertStringContainsString('0,00 €', $crawler->filter('[data-missing]')->text());
        self::assertStringContainsString('Rimborsato il', $this->createLiveComponent('PizzaOrder', ['eater' => $b])->render()->crawler()->text());
    }

    public function testUnLinkDiGruppoNonMostraMaiDatiDegliAltri(): void
    {
        [$evening, $a, $b] = $this->eveningWithBill();

        $this->client->request('GET', '/g/'.$b->getToken());

        self::assertResponseIsSuccessful();
        foreach (['Famiglia A', 'Anna', 'Luca', '13,43', 'Differenza'] as $foreign) {
            self::assertSelectorTextNotContains('body', $foreign);
        }
    }

    public function testUnLinkRigeneratoRendeInutilizzabileIlPrecedente(): void
    {
        [$evening, , $b] = $this->eveningWithBill();
        $old = $b->getToken();

        $this->createLiveComponent('EaterManager', ['evening' => $evening])->call('regenerate', ['eaterId' => $b->getId()]);

        $this->client->request('GET', '/g/'.$old);
        self::assertResponseStatusCodeSame(404);
    }

    public function testRichiestaCostruitaAManoNonPuoForzareLaModalitaOrganizzatore(): void
    {
        $evening = new Evening('Serata', new \DateTimeImmutable('2026-10-19'), 'Org');
        $eater = new Eater($evening, 'Famiglia B');
        $evening->setStatus(EveningStatus::OrdersClosed);
        $this->em->persist($evening);
        $this->em->flush();

        try {
            $this->createLiveComponent('PizzaOrder', ['eater' => $eater])
                ->set('asOrganizer', true)
                ->set('person', 'Marta')->set('pizzaName', 'Extra')->set('price', '5')
                ->call('add');
        } catch (\Throwable) {
            // La proprietà asOrganizer non è modificabile dal browser: la richiesta viene rifiutata.
        }

        self::assertSame(0, $this->pizzaCount());
    }

    public function testRichiestaCostruitaAManoSuUnComponenteConPropsManomesse(): void
    {
        $evening = new Evening('Serata', new \DateTimeImmutable('2026-10-19'), 'Org');
        $eater = new Eater($evening, 'Famiglia B');
        $evening->setStatus(EveningStatus::OrdersClosed);
        $this->em->persist($evening);
        $this->em->flush();

        $html = $this->createLiveComponent('PizzaOrder', ['eater' => $eater])->render()->toString();
        $props = json_decode(html_entity_decode((new Crawler($html))->filter('[data-live-props-value]')->attr('data-live-props-value')), true);
        $props['asOrganizer'] = true;

        $this->client->request('POST', '/_components/PizzaOrder/add', [
            'data' => json_encode(['props' => $props, 'updated' => ['person' => 'Marta', 'pizzaName' => 'Extra', 'price' => '5'], 'args' => []]),
        ], [], ['HTTP_ACCEPT' => 'application/vnd.live-component+html', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        // Le props non modificabili sono firmate: la manomissione viene rifiutata.
        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('Invalid checksum', $this->client->getResponse()->getContent());
        self::assertSame(0, $this->pizzaCount());
    }

    /** @return array{Evening, Eater, Eater} */
    private function eveningWithBill(): array
    {
        $evening = new Evening('Pizza da Gino', new \DateTimeImmutable('2026-10-19'), 'Federico');
        $a = new Eater($evening, 'Famiglia A');
        $b = new Eater($evening, 'Famiglia B');
        $evening->setOrganizerEater($a);
        new Pizza($a, 'Anna', 'Margherita', 700);
        new Pizza($a, 'Luca', 'Diavola', 800);
        new Pizza($b, 'Marta', 'Bufala', 1200);
        new Pizza($b, 'Marta', 'Marinara', 650);
        $evening->setStatus(EveningStatus::OrdersClosed);
        $evening->enterBill(3000, \App\Enum\DiscountMode::Proportional);
        $this->em->persist($evening);
        $this->em->flush();

        return [$evening, $a, $b];
    }

    private function eater(Evening $evening, string $name): Eater
    {
        return $this->em->getRepository(Eater::class)->findOneBy(['evening' => $evening, 'name' => $name]);
    }

    private function pizzaCount(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM pizzas');
    }
}
