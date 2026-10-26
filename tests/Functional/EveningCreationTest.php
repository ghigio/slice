<?php

namespace App\Tests\Functional;

use App\Entity\Evening;
use App\Repository\EveningRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class EveningCreationTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testLaHomePortaAllaCreazione(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $link = $crawler->selectLink('Crea una serata');
        self::assertCount(1, $link);
        self::assertSame('/nuova-serata', $link->attr('href'));
        self::assertSelectorTextContains('main', '90 giorni');
    }

    public function testIlFormHaCampiInItalianoEDataDiOggi(): void
    {
        $crawler = $this->client->request('GET', '/nuova-serata');

        self::assertResponseIsSuccessful();
        foreach (['Nome della serata', 'Data', 'Pizzeria', 'Il tuo nome', 'Note per il rimborso'] as $label) {
            self::assertSelectorExists(sprintf('label:contains("%s")', $label));
        }
        self::assertSame((new \DateTimeImmutable('today'))->format('Y-m-d'), $crawler->filter('input[type=date]')->attr('value'));
    }

    public function testCreazioneEReindirizzamentoAllaPaginaAdmin(): void
    {
        $this->submitForm([
            'name' => '  Pizza   da Gino ',
            'date' => '2026-10-19',
            'pizzeria' => 'Da Gino',
            'organizerName' => 'Federico',
            'refundNotes' => 'Satispay 333 1234567',
        ]);

        self::assertResponseRedirects();
        $location = $this->client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#^/s/[0-9a-f]{64}$#', $location);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());

        $evening = $this->findEvening(substr($location, 3));
        self::assertNotNull($evening);
        self::assertSame('Pizza da Gino', $evening->getName());
        self::assertSame('2026-10-19', $evening->getDate()->format('Y-m-d'));
        self::assertSame('Da Gino', $evening->getPizzeria());
        self::assertSame('Federico', $evening->getOrganizerName());
        self::assertSame('Satispay 333 1234567', $evening->getRefundNotes());

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert', "Salva questo link, è l'unico modo per gestire la serata");
        self::assertSelectorTextContains('h1', 'Pizza da Gino');
        self::assertSelectorTextContains('main', '19/10/2026');
        self::assertSelectorExists(sprintf('input[readonly][value="http://localhost%s"]', $location));
        self::assertSelectorTextContains('button', 'Copia link');
    }

    public function testCampiFacoltativiVuotiDiventanoNull(): void
    {
        $this->submitForm(['name' => 'Serata', 'date' => '2026-10-19', 'pizzeria' => '  ', 'organizerName' => 'Org', 'refundNotes' => '']);

        self::assertResponseRedirects();
        $evening = $this->findEvening(substr($this->client->getResponse()->headers->get('Location'), 3));
        self::assertNull($evening->getPizzeria());
        self::assertNull($evening->getRefundNotes());
    }

    public function testDatiMancantiMostranoErroriInItaliano(): void
    {
        $this->submitForm(['name' => '  ', 'date' => '2026-10-19', 'pizzeria' => '', 'organizerName' => '', 'refundNotes' => '']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.invalid-feedback', 'Questo valore non dovrebbe essere vuoto.');
        self::assertSame(0, self::getContainer()->get(EveningRepository::class)->count([]));
    }

    public function testNoteTroppoLunghe(): void
    {
        $this->submitForm(['name' => 'Serata', 'date' => '2026-10-19', 'pizzeria' => '', 'organizerName' => 'Org', 'refundNotes' => str_repeat('x', 1001)]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testRichiestaDaAltroSitoRespintaDalCsrf(): void
    {
        $this->client->request('POST', '/nuova-serata', ['new_evening' => [
            'name' => 'Serata', 'date' => '2026-10-19', 'organizerName' => 'Org', '_token' => 'csrf-token',
        ]], [], ['HTTP_ORIGIN' => 'https://malintenzionato.example']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, self::getContainer()->get(EveningRepository::class)->count([]));
    }

    public function testTokenAdminNonValidoDa404(): void
    {
        $this->client->request('GET', '/s/'.str_repeat('a', 64));
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/s/abc');
        self::assertResponseStatusCodeSame(404);
    }

    /** @param array<string, string> $values */
    private function submitForm(array $values): void
    {
        $crawler = $this->client->request('GET', '/nuova-serata');
        $form = $crawler->selectButton('Crea la serata')->form();
        foreach ($values as $field => $value) {
            $form["new_evening[$field]"] = $value;
        }
        $this->client->submit($form);
    }

    private function findEvening(string $adminToken): ?Evening
    {
        return self::getContainer()->get(EveningRepository::class)->findOneByAdminToken($adminToken);
    }
}
