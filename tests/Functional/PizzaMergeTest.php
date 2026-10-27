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

final class PizzaMergeTest extends WebTestCase
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
        new Pizza($this->rossi, 'Anna', 'Salamino', 700);
        new Pizza($this->bianchi, 'Marta', 'Salamino piccante', null);
        new Pizza($this->bianchi, 'Gino', 'Salamino piccante', null);
        new Pizza($this->rossi, 'Luca', 'Diavola', null);
        $this->em->persist($this->evening);
        $this->em->flush();
    }

    public function testPossibiliDoppioniConUnioneInUnClic(): void
    {
        $component = $this->createLiveComponent('PizzaMerge', ['evening' => $this->evening]);
        $crawler = $component->render()->crawler();

        $duplicate = $crawler->filter('[data-duplicate]');
        self::assertCount(1, $duplicate);
        self::assertStringContainsString('Salamino (1)', $duplicate->text());
        self::assertStringContainsString('Salamino piccante (2)', $duplicate->text());
        $button = $duplicate->filter('button:contains("Unisci in «Salamino»")');
        self::assertSame('Unire 2 Salamino piccante in Salamino?', $button->attr('data-confirm-message-param'));
        self::assertStringContainsString('15000', (string) $crawler->filter('[data-poll]')->attr('data-poll'));

        $crawler = $component->call('merge', ['from' => 'salamino piccante', 'into' => 'salamino'])->render()->crawler();

        self::assertStringContainsString('2 pizze unite in «Salamino».', $crawler->filter('.alert-success')->text());
        self::assertCount(0, $crawler->filter('[data-duplicate]'));
        $pizzas = $this->em->getRepository(Pizza::class)->findBy(['normalizedName' => 'salamino']);
        self::assertCount(3, $pizzas);
        self::assertSame([700, 700, 700], array_map(fn (Pizza $p) => $p->getPriceCents(), $pizzas));

        $list = $this->createLiveComponent('PizzaList', ['evening' => $this->evening])->render()->crawler();
        self::assertSame("1 x Diavola\n3 x Salamino", $list->filter('textarea[readonly]')->text(null, false));
    }

    public function testUnioneDaElencoPerICasiNonTrovati(): void
    {
        $component = $this->createLiveComponent('PizzaMerge', ['evening' => $this->evening]);
        $crawler = $component->render()->crawler();

        $options = $crawler->filter('select#merge-from option')->each(fn (Crawler $o) => $o->text());
        self::assertSame(['Scegli…', 'Diavola (1)', 'Salamino (1)', 'Salamino piccante (2)'], $options);

        $crawler = $component->set('from', 'diavola')->set('into', 'salamino')->call('mergeSelected')->render()->crawler();
        self::assertStringContainsString('1 pizza unita in «Salamino».', $crawler->filter('.alert-success')->text());

        $crawler = $component->set('from', 'salamino')->set('into', 'salamino')->call('mergeSelected')->render()->crawler();
        self::assertStringContainsString('Scegli due pizze diverse.', $crawler->filter('.alert-danger')->text());
    }

    public function testNascostoDaArchiviataEPresenteInAdmin(): void
    {
        $this->evening->setStatus(EveningStatus::Archived);
        $this->em->flush();

        $crawler = $this->createLiveComponent('PizzaMerge', ['evening' => $this->evening])->render()->crawler();
        self::assertCount(0, $crawler->filter('form, button'));

        self::getClient()->request('GET', '/s/'.$this->evening->getAdminToken());
        self::assertSelectorExists('[data-live-name-value="PizzaMerge"]');
    }
}
