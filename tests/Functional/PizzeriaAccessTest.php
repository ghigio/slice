<?php

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Area di gestione delle pizzerie: un solo account (ADMIN_USERNAME / ADMIN_PASSWORD_HASH, vedi .env.test).
 */
final class PizzeriaAccessTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testSenzaLoginSiVaAlLogin(): void
    {
        $this->client->request('GET', '/gestione');

        self::assertResponseRedirects('/gestione/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Accesso alla gestione');
        self::assertSelectorExists('input[name="_csrf_token"]');
        self::assertSame('noindex, nofollow', $this->client->getResponse()->headers->get('X-Robots-Tag'));
    }

    public function testCredenzialiErrateRifiutate(): void
    {
        $this->login('gestore', 'sbagliata');

        self::assertResponseRedirects('/gestione/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Username o password non validi.');

        $this->login('altro', 'password-di-test');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Username o password non validi.');
    }

    public function testConLeCredenzialiGiusteSiEntraESiEsce(): void
    {
        $this->login('gestore', 'password-di-test');

        self::assertResponseRedirects('/gestione');
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Pizzerie');

        $this->client->submit($this->client->getCrawler()->selectButton('Esci')->form());
        $this->client->request('GET', '/gestione');
        self::assertResponseRedirects('/gestione/login');
    }

    public function testTroppiTentativiBloccanoIlLogin(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->login('gestore', 'sbagliata');
        }
        $this->login('gestore', 'password-di-test');

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Troppi tentativi');
        $this->client->request('GET', '/gestione');
        self::assertResponseRedirects('/gestione/login');
    }

    public function testLeRotteDellaSerataRestanoSenzaAccount(): void
    {
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/nuova-serata');
        self::assertResponseIsSuccessful();
    }

    private function login(string $username, string $password): void
    {
        $crawler = $this->client->request('GET', '/gestione/login');
        $form = $crawler->selectButton('Entra')->form(['_username' => $username, '_password' => $password]);
        $this->client->submit($form);
    }
}
