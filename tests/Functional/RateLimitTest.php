<?php

namespace App\Tests\Functional;

use App\Entity\Eater;
use App\Entity\Evening;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * In ambiente test i limiti sono ridotti (config/packages/rate_limiter.yaml): creazione 2, pagine 5, componenti 50, token inesistenti 2.
 */
final class RateLimitTest extends WebTestCase
{
    private KernelBrowser $client;
    private Eater $eater;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $evening = new Evening('Serata', new \DateTimeImmutable('2026-10-19'), 'Org');
        $this->eater = new Eater($evening, 'Famiglia Rossi');
        $em->persist($evening);
        $em->flush();
    }

    public function testCreazioneSerateLimitataPerIp(): void
    {
        for ($i = 0; $i < 2; ++$i) {
            $this->client->request('POST', '/nuova-serata', ['new_evening' => []]);
            self::assertNotSame(429, $this->client->getResponse()->getStatusCode());
        }

        $this->client->request('POST', '/nuova-serata', ['new_evening' => []]);
        self::assertResponseStatusCodeSame(429);
        self::assertTrue($this->client->getResponse()->headers->has('Retry-After'));

        $this->client->request('GET', '/nuova-serata');
        self::assertResponseIsSuccessful();

        $this->client->request('POST', '/nuova-serata', ['new_evening' => []], [], ['REMOTE_ADDR' => '10.0.0.2']);
        self::assertNotSame(429, $this->client->getResponse()->getStatusCode());
    }

    public function testPagineConTokenLimitatePerIp(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->client->request('GET', '/g/'.$this->eater->getToken());
            self::assertResponseIsSuccessful();
        }

        $this->client->request('GET', '/g/'.$this->eater->getToken());
        self::assertResponseStatusCodeSame(429);

        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
    }

    public function testComponentiLimitatiSeparatamente(): void
    {
        for ($i = 0; $i < 50; ++$i) {
            $this->client->request('GET', '/_components/PizzaOrder');
            self::assertNotSame(429, $this->client->getResponse()->getStatusCode());
        }
        $this->client->request('GET', '/_components/PizzaOrder');
        self::assertResponseStatusCodeSame(429);

        $this->client->request('GET', '/g/'.$this->eater->getToken());
        self::assertResponseIsSuccessful();
    }

    public function testTroppiTokenInesistentiBloccanoLeRotteConToken(): void
    {
        $this->client->request('GET', '/g/'.str_repeat('0', 64));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/s/abc');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/g/'.$this->eater->getToken());
        self::assertResponseStatusCodeSame(429);

        $this->client->request('GET', '/g/'.$this->eater->getToken(), [], [], ['REMOTE_ADDR' => '10.0.0.3']);
        self::assertResponseIsSuccessful();
    }
}
