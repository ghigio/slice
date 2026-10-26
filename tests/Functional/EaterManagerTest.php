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

final class EaterManagerTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private EntityManagerInterface $em;
    private Evening $evening;

    protected function setUp(): void
    {
        self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->evening = new Evening('Pizza da Gino', new \DateTimeImmutable('2026-10-19'), 'Federico');
        $this->em->persist($this->evening);
        $this->em->flush();
    }

    public function testSenzaGruppiConPolling(): void
    {
        $crawler = $this->component()->render()->crawler();

        self::assertStringContainsString('Nessun gruppo', $crawler->text());
        self::assertStringContainsString('15000', (string) $crawler->filter('[data-poll]')->attr('data-poll'));
    }

    public function testAggiungeUnGruppo(): void
    {
        $component = $this->component();
        $component->set('newName', '  Famiglia   Rossi ')->call('add');
        $crawler = $component->render()->crawler();

        self::assertSame(['Famiglia Rossi'], $this->names($crawler));
        self::assertStringContainsString('0 pizze', $this->row($crawler, 0)->text());
        self::assertSame('', $component->component()->newName);
        self::assertCount(1, $this->em->getRepository(Eater::class)->findAll());
    }

    public function testNomeDuplicatoMostraErrore(): void
    {
        $this->addEater('Famiglia Rossi');

        $crawler = $this->component()->set('newName', 'famiglia rossi')->call('add')->render()->crawler();

        self::assertStringContainsString('Esiste già un gruppo con questo nome.', $crawler->filter('.alert-danger')->text());
        self::assertCount(1, $this->names($crawler));
    }

    public function testLinkCopiabileEWhatsApp(): void
    {
        $eater = $this->addEater('Famiglia Rossi');
        $crawler = $this->component()->render()->crawler();
        $url = 'http://localhost/g/'.$eater->getToken();

        self::assertSame($url, $this->row($crawler, 0)->filter('input[readonly]')->attr('value'));
        self::assertSame('Copia link', $this->row($crawler, 0)->filter('button:contains("Copia link")')->text());
        $message = sprintf('Slice – Pizza da Gino: ecco il link per ordinare le pizze di Famiglia Rossi: %s', $url);
        self::assertSame('https://wa.me/?text='.rawurlencode($message), $this->row($crawler, 0)->filter('a:contains("WhatsApp")')->attr('href'));
    }

    public function testContaLePizze(): void
    {
        $eater = $this->addEater('Famiglia Rossi');
        $this->em->persist(new Pizza($eater, 'Anna', 'Margherita', 700));
        $this->em->flush();
        self::assertStringContainsString('1 pizza', $this->row($this->component()->render()->crawler(), 0)->text());

        $this->em->persist(new Pizza($eater, 'Luca', 'Diavola', 800));
        $this->em->flush();
        self::assertStringContainsString('2 pizze', $this->row($this->component()->render()->crawler(), 0)->text());
    }

    public function testIndicaIlProprioGruppo(): void
    {
        $eater = $this->addEater('Famiglia Rossi');
        $this->addEater('Famiglia Bianchi');

        $crawler = $this->component()->call('setOrganizer', ['eaterId' => $eater->getId()])->render()->crawler();

        self::assertStringContainsString('Il tuo gruppo', $this->rowByName($crawler, 'Famiglia Rossi')->text());
        self::assertStringNotContainsString('Il tuo gruppo', $this->rowByName($crawler, 'Famiglia Bianchi')->text());
    }

    public function testRinomina(): void
    {
        $eater = $this->addEater('Famiglia Rossi');

        $component = $this->component()->call('startEdit', ['eaterId' => $eater->getId()]);
        self::assertSame('Famiglia Rossi', $component->render()->crawler()->filter('input[data-model$="editingName"]')->attr('value'));

        $crawler = $component->set('editingName', 'Famiglia Rossi-Verdi')->call('rename')->render()->crawler();

        self::assertSame(['Famiglia Rossi-Verdi'], $this->names($crawler));
        self::assertNull($component->component()->editingId);
    }

    public function testEliminaConConferma(): void
    {
        $eater = $this->addEater('Famiglia Rossi');
        $this->em->persist(new Pizza($eater, 'Anna', 'Margherita', 700));
        $this->em->flush();

        $deleteButton = $this->row($this->component()->render()->crawler(), 0)->filter('button:contains("Elimina")');
        self::assertStringContainsString('1 pizza', (string) $deleteButton->attr('data-confirm-message-param'));

        $crawler = $this->component()->call('delete', ['eaterId' => $eater->getId(), 'confirmed' => true])->render()->crawler();

        self::assertStringContainsString('Nessun gruppo', $crawler->text());
    }

    public function testRigeneraInvalidaIlVecchioLink(): void
    {
        $eater = $this->addEater('Famiglia Rossi');
        $oldToken = $eater->getToken();

        $crawler = $this->component()->call('regenerate', ['eaterId' => $eater->getId()])->render()->crawler();

        $newUrl = $this->row($crawler, 0)->filter('input[readonly]')->attr('value');
        self::assertStringNotContainsString($oldToken, $newUrl);

        $client = self::getClient();
        $client->request('GET', '/g/'.$oldToken);
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', parse_url($newUrl, PHP_URL_PATH));
        self::assertResponseIsSuccessful();
    }

    public function testSerataArchiviataInSolaLettura(): void
    {
        $this->addEater('Famiglia Rossi');
        $this->evening->setStatus(EveningStatus::Archived);
        $this->em->flush();

        $crawler = $this->component()->render()->crawler();

        self::assertStringContainsString('Serata archiviata: sola lettura.', $crawler->text());
        self::assertCount(0, $crawler->filter('input[data-model$="newName"]'));
        self::assertSame(['Copia link'], $crawler->filter('button')->each(fn ($b) => $b->text()));
    }

    private function component(): TestLiveComponent
    {
        return $this->createLiveComponent('EaterManager', ['evening' => $this->evening]);
    }

    private function addEater(string $name): Eater
    {
        $eater = new Eater($this->evening, $name);
        $this->em->persist($eater);
        $this->em->flush();

        return $eater;
    }

    /** @return list<string> */
    private function names(Crawler $crawler): array
    {
        return $crawler->filter('[data-eater-row] [data-eater-name]')->each(fn (Crawler $n) => $n->text());
    }

    private function row(Crawler $crawler, int $index): Crawler
    {
        return $crawler->filter('[data-eater-row]')->eq($index);
    }

    private function rowByName(Crawler $crawler, string $name): Crawler
    {
        return $crawler->filter('[data-eater-row]')->reduce(fn (Crawler $r) => $r->filter('[data-eater-name]')->text() === $name)->first();
    }
}
