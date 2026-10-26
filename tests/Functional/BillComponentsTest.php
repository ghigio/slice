<?php

namespace App\Tests\Functional;

use App\Entity\Eater;
use App\Entity\Evening;
use App\Entity\Pizza;
use App\Enum\DiscountMode;
use App\Enum\EveningStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class BillComponentsTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use InteractsWithLiveComponents;

    private EntityManagerInterface $em;
    private Evening $evening;
    private Eater $a;
    private Eater $b;

    protected function setUp(): void
    {
        self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        // Caso 1 dei requisiti: A (organizzatore) 7,00 + 8,00; B 12,00 + 6,50; totale pagato 30,00.
        $this->evening = new Evening('Pizza da Gino', new \DateTimeImmutable('2026-10-19'), 'Federico', 'Da Gino', 'Satispay 333 1234567');
        $this->a = new Eater($this->evening, 'Famiglia A');
        $this->b = new Eater($this->evening, 'Famiglia B');
        $this->evening->setOrganizerEater($this->a);
        new Pizza($this->a, 'Anna', 'Margherita', 700);
        new Pizza($this->a, 'Luca', 'Diavola', 800);
        new Pizza($this->b, 'Marta', 'Bufala', 1200);
        new Pizza($this->b, 'Marta', 'Marinara', 650);
        $this->evening->setStatus(EveningStatus::OrdersClosed);
        $this->em->persist($this->evening);
        $this->em->flush();
    }

    public function testFormConOrdiniApertiInvitaAChiuderli(): void
    {
        $this->evening->setStatus(EveningStatus::OrdersOpen);
        $this->em->flush();

        $crawler = $this->billForm()->render()->crawler();

        self::assertStringContainsString('Chiudi gli ordini per inserire il conto.', $crawler->text());
        self::assertCount(0, $crawler->filter('input'));
    }

    public function testAnteprimaPrimaDiConfermare(): void
    {
        $component = $this->billForm();
        $crawler = $component->render()->crawler();
        self::assertStringContainsString('33,50 €', $crawler->filter('[data-list-total]')->text());
        self::assertCount(0, $crawler->filter('[data-bill-preview]'));

        $crawler = $component->set('paidTotal', '30')->render()->crawler();
        $preview = $crawler->filter('[data-bill-preview]');
        self::assertStringContainsString('3,50 €', $preview->text());
        self::assertStringContainsString('10,45 %', $preview->text());
        self::assertStringContainsString('13,43 €', $this->rowFor($preview, 'Famiglia A')->text());
        self::assertStringContainsString('16,57 €', $this->rowFor($preview, 'Famiglia B')->text());
        self::assertSame('Conferma conto', $crawler->filter('button[data-live-action-param="save"]')->text());
        self::assertNull($this->reload()->getPaidTotalCents());
    }

    public function testErroreModalitaUgualeNellAnteprima(): void
    {
        $crawler = $this->billForm()->set('paidTotal', '7')->set('mode', 'equal')->render()->crawler();

        self::assertStringContainsString('usa la modalità proporzionale', $crawler->filter('.alert-danger')->text());
        self::assertCount(0, $crawler->filter('button[data-live-action-param="save"]'));
    }

    public function testConfermaDelConto(): void
    {
        $crawler = $this->billForm()->set('paidTotal', '30,00')->call('save')->render()->crawler();

        self::assertStringContainsString('Conto inserito', $crawler->filter('.alert-success')->text());
        $evening = $this->reload();
        self::assertSame(EveningStatus::BillEntered, $evening->getStatus());
        self::assertSame(3000, $evening->getPaidTotalCents());
    }

    public function testMaggiorazioneRichiedeConferma(): void
    {
        $component = $this->billForm()->set('paidTotal', '40');
        $crawler = $component->render()->crawler();
        self::assertStringContainsString('maggiore del totale da listino', $crawler->filter('[data-surcharge-warning]')->text());
        self::assertCount(1, $crawler->filter('input[type=checkbox][data-model$="surchargeConfirmed"]'));

        $component->call('save');
        self::assertSame(EveningStatus::OrdersClosed, $this->reload()->getStatus());

        $component->set('surchargeConfirmed', true)->call('save');
        self::assertSame(4000, $this->reload()->getPaidTotalCents());
    }

    public function testModificaDelContoPrecompilata(): void
    {
        $this->evening->enterBill(3000, DiscountMode::Equal);
        $this->em->flush();

        $component = $this->billForm();

        self::assertSame('30,00', $component->component()->paidTotal);
        self::assertSame('equal', $component->component()->mode);
        self::assertSame('Aggiorna conto', $component->render()->crawler()->filter('button[data-live-action-param="save"]')->text());
    }

    public function testRiepilogoConTotaliEDifferenza(): void
    {
        $this->evening->enterBill(3000, DiscountMode::Proportional);
        $this->em->flush();

        $crawler = $this->summary()->render()->crawler();

        $a = $this->rowFor($crawler, 'Famiglia A');
        self::assertStringContainsString('2', $a->filter('[data-pizza-count]')->text());
        self::assertStringContainsString('15,00 €', $a->text());
        self::assertStringContainsString('13,43 €', $a->text());
        self::assertStringContainsString('Nessun rimborso dovuto', $a->text());
        self::assertCount(0, $a->filter('input[type=checkbox]'));

        $b = $this->rowFor($crawler, 'Famiglia B');
        self::assertStringContainsString('16,57 €', $b->text());
        self::assertCount(1, $b->filter('input[type=checkbox]'));

        self::assertStringContainsString('16,57 €', $crawler->filter('[data-to-receive]')->text());
        self::assertStringContainsString('0,00 €', $crawler->filter('[data-received]')->text());
        self::assertStringContainsString('16,57 €', $crawler->filter('[data-missing]')->text());
        self::assertStringContainsString('Nessuna differenza', $crawler->filter('[data-rounding-difference]')->text());
        self::assertStringContainsString('5000', (string) $crawler->filter('[data-poll]')->attr('data-poll'));
    }

    public function testSegnaRimborsoConDataEOra(): void
    {
        self::mockTime(new \DateTimeImmutable('2026-10-19 22:15:00'));
        $this->evening->enterBill(3000, DiscountMode::Proportional);
        $this->em->flush();

        $crawler = $this->summary()->call('toggleReimbursed', ['eaterId' => $this->b->getId(), 'reimbursed' => true])->render()->crawler();

        $b = $this->rowFor($crawler, 'Famiglia B');
        self::assertNotNull($b->filter('input[type=checkbox]')->attr('checked'));
        self::assertStringContainsString('19/10/2026', $b->text());
        self::assertStringContainsString('16,57 €', $crawler->filter('[data-received]')->text());
        self::assertStringContainsString('0,00 €', $crawler->filter('[data-missing]')->text());
    }

    public function testAvvisoSeMancaIlGruppoOrganizzatore(): void
    {
        $this->evening->setOrganizerEater(null);
        $this->evening->enterBill(3000, DiscountMode::Proportional);
        $this->em->flush();

        self::assertStringContainsString('Indica il tuo gruppo', $this->summary()->render()->crawler()->text());
    }

    public function testRiepilogoAssenteSenzaConto(): void
    {
        self::assertCount(0, $this->summary()->render()->crawler()->filter('table'));
    }

    public function testIlGruppoVedeLaSuaQuotaMaNonLaDifferenza(): void
    {
        $this->evening->enterBill(3000, DiscountMode::Proportional);
        $this->em->flush();

        $crawler = $this->createLiveComponent('PizzaOrder', ['eater' => $this->b])->render()->crawler();
        $bill = $crawler->filter('[data-bill-view]');

        self::assertStringContainsString('12,00 €', $bill->text());
        self::assertStringContainsString('10,75 €', $bill->text());
        self::assertStringContainsString('6,50 €', $bill->text());
        self::assertStringContainsString('5,82 €', $bill->text());
        self::assertStringContainsString('16,57 €', $bill->filter('[data-person-share]')->text());
        self::assertStringContainsString('16,57 €', $bill->filter('[data-amount-due]')->text());
        self::assertStringContainsString('Sconto applicato: 10,45 %', $bill->text());
        self::assertStringContainsString('Satispay 333 1234567', $bill->text());
        self::assertStringContainsString('Da rimborsare', $bill->text());
        self::assertStringNotContainsString('differenza', mb_strtolower($crawler->text()));
        self::assertStringNotContainsString('Famiglia A', $crawler->text());
        self::assertCount(0, $crawler->filter('input'));
    }

    public function testIlGruppoVedeLaDataDelRimborso(): void
    {
        $this->evening->enterBill(3000, DiscountMode::Proportional);
        $this->b->markReimbursed(new \DateTimeImmutable('2026-10-19 22:15:00'));
        $this->em->flush();

        $bill = $this->createLiveComponent('PizzaOrder', ['eater' => $this->b])->render()->crawler()->filter('[data-bill-view]');

        self::assertStringContainsString('Rimborsato il 19/10/2026', $bill->text());
    }

    public function testIlGruppoDellOrganizzatoreNonDeveNulla(): void
    {
        $this->evening->enterBill(3000, DiscountMode::Proportional);
        $this->em->flush();

        $bill = $this->createLiveComponent('PizzaOrder', ['eater' => $this->a])->render()->crawler()->filter('[data-bill-view]');

        self::assertStringContainsString('13,43 €', $bill->text());
        self::assertStringContainsString('Nessun rimborso dovuto', $bill->text());
        self::assertStringNotContainsString('Da rimborsare', $bill->text());
    }

    public function testPaginaAdminIncludeIComponentiDelConto(): void
    {
        self::getClient()->request('GET', '/s/'.$this->evening->getAdminToken());

        self::assertSelectorExists('[data-live-name-value="BillForm"]');
        self::assertSelectorExists('[data-live-name-value="BillSummaryTable"]');
    }

    private function billForm()
    {
        return $this->createLiveComponent('BillForm', ['evening' => $this->evening]);
    }

    private function summary()
    {
        return $this->createLiveComponent('BillSummaryTable', ['evening' => $this->evening]);
    }

    private function rowFor(Crawler $crawler, string $eaterName): Crawler
    {
        return $crawler->filter('[data-share-row]')->reduce(fn (Crawler $r) => $r->filter('[data-share-name]')->text() === $eaterName)->first();
    }

    private function reload(): Evening
    {
        $this->em->clear();

        return $this->em->find(Evening::class, $this->evening->getId());
    }
}
