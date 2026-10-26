<?php

namespace App\Tests\Functional;

use App\Entity\Evening;
use App\Enum\EveningStatus;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class EveningStatusPanelTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    public static function buttonsByStatus(): iterable
    {
        yield 'ordini aperti' => [EveningStatus::OrdersOpen, ['Chiudi ordini']];
        yield 'ordini chiusi' => [EveningStatus::OrdersClosed, ['Riapri ordini']];
        yield 'conto inserito' => [EveningStatus::BillEntered, ['Archivia']];
        yield 'archiviata' => [EveningStatus::Archived, ['Riapri']];
    }

    /** @param list<string> $expected */
    #[DataProvider('buttonsByStatus')]
    public function testMostraStatoESoloIPulsantiAmmessi(EveningStatus $status, array $expected): void
    {
        $crawler = $this->component($this->persistEvening($status))->render()->crawler();

        self::assertStringContainsString($status->label(), $crawler->filter('.badge')->text());
        self::assertSame($expected, $crawler->filter('button')->each(fn ($b) => $b->text()));
    }

    public function testChiusuraConConfermaEPersistenza(): void
    {
        $evening = $this->persistEvening(EveningStatus::OrdersOpen);
        $component = $this->component($evening);

        self::assertNotEmpty($component->render()->crawler()->filter('button[data-confirm-message-param]')->attr('data-confirm-message-param'));

        $crawler = $component->call('closeOrders')->render()->crawler();

        self::assertSame(['Riapri ordini'], $crawler->filter('button')->each(fn ($b) => $b->text()));
        self::assertSame(EveningStatus::OrdersClosed, $this->reload($evening)->getStatus());
    }

    public function testAzioneNonAmmessaMostraErroreSenzaCambiareStato(): void
    {
        $evening = $this->persistEvening(EveningStatus::OrdersOpen);

        $crawler = $this->component($evening)->call('archive')->render()->crawler();

        self::assertSelectorTextContainsInCrawler($crawler, '.alert-danger', 'Operazione non consentita nello stato «Ordini aperti».');
        self::assertSame(EveningStatus::OrdersOpen, $this->reload($evening)->getStatus());
    }

    public function testRiapriDaArchiviata(): void
    {
        $evening = $this->persistEvening(EveningStatus::Archived);

        $this->component($evening)->call('unarchive');

        self::assertSame(EveningStatus::BillEntered, $this->reload($evening)->getStatus());
    }

    private function component(Evening $evening)
    {
        return $this->createLiveComponent('EveningStatusPanel', ['evening' => $evening]);
    }

    private function persistEvening(EveningStatus $status): Evening
    {
        $evening = new Evening('Serata', new \DateTimeImmutable('2026-10-19'), 'Org');
        $evening->setStatus($status);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($evening);
        $em->flush();

        return $evening;
    }

    private function reload(Evening $evening): Evening
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->find(Evening::class, $evening->getId());
    }

    private static function assertSelectorTextContainsInCrawler($crawler, string $selector, string $text): void
    {
        self::assertGreaterThan(0, $crawler->filter($selector)->count(), "Nessun elemento $selector");
        self::assertStringContainsString($text, $crawler->filter($selector)->text());
    }
}
