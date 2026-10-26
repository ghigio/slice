<?php

namespace App\Tests\Functional;

use App\Entity\Evening;
use App\Enum\EveningStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class InviteLinkTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private Evening $evening;

    protected function setUp(): void
    {
        self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->evening = new Evening('Pizza da Gino', new \DateTimeImmutable('2026-10-19'), 'Federico');
        $em->persist($this->evening);
        $em->flush();
    }

    public function testLinkCopiabileEWhatsApp(): void
    {
        $crawler = $this->createLiveComponent('InviteLink', ['evening' => $this->evening])->render()->crawler();
        $url = 'http://localhost/i/'.$this->evening->getInviteToken();

        self::assertSame($url, $crawler->filter('input[readonly]')->attr('value'));
        self::assertSame('Copia link', $crawler->filter('button:contains("Copia link")')->text());
        $message = sprintf('Slice – Pizza da Gino: aggiungi il tuo gruppo e ordina le pizze qui: %s', $url);
        self::assertSame('https://wa.me/?text='.rawurlencode($message), $crawler->filter('a:contains("WhatsApp")')->attr('href'));
        self::assertNotEmpty($crawler->filter('button:contains("Rigenera link")')->attr('data-confirm-message-param'));
    }

    public function testRigenera(): void
    {
        $old = $this->evening->getInviteToken();

        $crawler = $this->createLiveComponent('InviteLink', ['evening' => $this->evening])->call('regenerate')->render()->crawler();

        self::assertStringNotContainsString($old, $crawler->filter('input[readonly]')->attr('value'));
        self::assertStringContainsString('il precedente non funziona più', $crawler->filter('.alert-success')->text());
    }

    public function testArchiviataSenzaRigenera(): void
    {
        $this->evening->setStatus(EveningStatus::Archived);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $crawler = $this->createLiveComponent('InviteLink', ['evening' => $this->evening])->render()->crawler();

        self::assertCount(0, $crawler->filter('button:contains("Rigenera link")'));
    }

    public function testPresenteNellaPaginaAdmin(): void
    {
        self::getClient()->request('GET', '/s/'.$this->evening->getAdminToken());

        self::assertSelectorExists('[data-live-name-value="InviteLink"]');
    }
}
