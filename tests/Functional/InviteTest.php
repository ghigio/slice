<?php

namespace App\Tests\Functional;

use App\Entity\Eater;
use App\Entity\Evening;
use App\Entity\Pizza;
use App\Enum\EveningStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class InviteTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Evening $evening;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->evening = new Evening('Pizza da Gino', new \DateTimeImmutable('2026-10-19'), 'Federico', 'Da Gino');
        $rossi = new Eater($this->evening, 'Famiglia Rossi');
        new Eater($this->evening, 'Famiglia Bianchi');
        new Pizza($rossi, 'Anna', 'Margherita', 700);
        $this->em->persist($this->evening);
        $this->em->flush();
    }

    public function testMostraSoloINomiDeiGruppiEIlModulo(): void
    {
        $crawler = $this->client->request('GET', $this->inviteUrl());

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
        self::assertSelectorTextContains('h1', 'Pizza da Gino');
        self::assertSame(['Famiglia Bianchi', 'Famiglia Rossi'], $crawler->filter('[data-invite-eater]')->each(fn ($n) => $n->text()));
        self::assertCount(0, $crawler->filter('a[href*="/g/"]'));
        self::assertSelectorTextNotContains('main', 'Anna');
        self::assertSelectorTextNotContains('main', 'Margherita');
        self::assertSelectorExists('label:contains("Nome del gruppo")');
        self::assertSelectorExists('button:contains("Aggiungi il mio gruppo")');
    }

    public function testAggiuntaPortaAllaPaginaDelGruppo(): void
    {
        $this->submit('  Famiglia   Verdi ');

        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $location = $this->client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#^/g/[0-9a-f]{64}$#', $location);
        $eater = $this->em->getRepository(Eater::class)->findOneBy(['token' => substr($location, 3)]);
        self::assertSame('Famiglia Verdi', $eater->getName());
        self::assertSame($this->evening->getId(), $eater->getEvening()->getId());

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert', "Salva questo link: è l'unico modo per tornare alle pizze del tuo gruppo");
        self::assertSelectorExists(sprintf('input[readonly][value="http://localhost%s"]', $location));
        self::assertSelectorTextContains('button', 'Copia link');
        self::assertSelectorTextNotContains('main', 'WhatsApp');
    }

    public function testNomeGiaUsatoMostraErrore(): void
    {
        $this->submit('famiglia ROSSI');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.invalid-feedback', 'Esiste già un gruppo con questo nome.');
        self::assertCount(2, $this->eaters());
    }

    public function testNomeObbligatorio(): void
    {
        $this->submit('   ');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.invalid-feedback', 'Il nome del gruppo è obbligatorio.');
    }

    public function testConOrdiniChiusiNienteModuloENessunaAggiunta(): void
    {
        $crawler = $this->client->request('GET', $this->inviteUrl());
        $token = $crawler->filter('input[name="invite_eater[_token]"]')->attr('value');

        $this->evening->setStatus(EveningStatus::OrdersClosed);
        $this->em->flush();

        $this->client->request('GET', $this->inviteUrl());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Non è più possibile aggiungere gruppi: gli ordini sono chiusi.');
        self::assertSelectorNotExists('form');

        // Richiesta costruita a mano: rifiutata senza creare gruppi.
        $this->client->request('POST', $this->inviteUrl(), ['invite_eater' => ['name' => 'Famiglia Verdi', '_token' => $token]]);
        self::assertNotSame(303, $this->client->getResponse()->getStatusCode());
        self::assertCount(2, $this->eaters());
    }

    public function testTokenInesistenteERigeneratoDanno404(): void
    {
        $this->client->request('GET', '/i/'.str_repeat('0', 64));
        self::assertResponseStatusCodeSame(404);

        $old = $this->inviteUrl();
        $this->evening->regenerateInviteToken();
        $this->em->flush();

        $this->client->request('GET', $old);
        self::assertResponseStatusCodeSame(404);
        // Da un altro IP: dopo due token inesistenti il primo è bloccato dal rate limiting (limite di test).
        $this->client->request('GET', $this->inviteUrl(), [], [], ['REMOTE_ADDR' => '10.0.0.9']);
        self::assertResponseIsSuccessful();
    }

    private function submit(string $name): void
    {
        $crawler = $this->client->request('GET', $this->inviteUrl());
        $this->client->submit($crawler->selectButton('Aggiungi il mio gruppo')->form(['invite_eater[name]' => $name]));
    }

    private function inviteUrl(): string
    {
        return '/i/'.$this->evening->getInviteToken();
    }

    /** @return list<Eater> */
    private function eaters(): array
    {
        return $this->em->getRepository(Eater::class)->findBy(['evening' => $this->evening]);
    }
}
