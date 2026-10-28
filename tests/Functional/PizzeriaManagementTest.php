<?php

namespace App\Tests\Functional;

use App\Entity\Evening;
use App\Entity\Ingredient;
use App\Entity\MenuPizza;
use App\Entity\Pizzeria;
use App\Security\AdminUserProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

final class PizzeriaManagementTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Pizzeria $gino;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->gino = new Pizzeria('Da Gino');
        $this->em->persist($this->gino);
        $this->em->flush();
    }

    public function testElencoPizzerieConAggiuntaRinominaEdEliminazione(): void
    {
        $this->loginAsAdmin();
        $evening = new Evening('Serata', new \DateTimeImmutable('2026-10-19'), 'Org');
        $evening->setKnownPizzeria($this->gino);
        $this->em->persist($evening);
        $this->em->flush();

        $component = $this->component('PizzeriaManager');
        $crawler = $component->set('newName', 'Da Totò')->call('add')->render()->crawler();
        self::assertSame(['Da Gino', 'Da Totò'], $crawler->filter('[data-pizzeria-name]')->each(fn (Crawler $n) => $n->text()));
        self::assertStringContainsString('/gestione/pizzerie/'.$this->gino->getId(), (string) $crawler->filter('a:contains("Menu e ingredienti")')->attr('href'));
        $delete = $crawler->filter('#pizzeria-'.$this->gino->getId().' button:contains("Elimina")');
        self::assertStringContainsString('1 serata perderà il menu', (string) $delete->attr('data-confirm-message-param'));

        $crawler = $component->set('newName', 'da gino')->call('add')->render()->crawler();
        self::assertStringContainsString('Esiste già una pizzeria con questo nome.', $crawler->filter('.alert-danger')->text());

        $component->call('startEdit', ['pizzeriaId' => $this->gino->getId()]);
        $crawler = $component->set('editingName', 'Da Gino al porto')->call('rename')->render()->crawler();
        self::assertStringContainsString('Da Gino al porto', $crawler->text());

        $crawler = $component->call('delete', ['pizzeriaId' => $this->gino->getId()])->render()->crawler();
        self::assertSame(['Da Totò'], $crawler->filter('[data-pizzeria-name]')->each(fn (Crawler $n) => $n->text()));
    }

    public function testIngredientiEMenuDellaPizzeria(): void
    {
        $this->loginAsAdmin();
        $this->client->request('GET', '/gestione/pizzerie/'.$this->gino->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Da Gino');

        $ingredients = $this->component('IngredientManager', ['pizzeria' => $this->gino]);
        $ingredients->set('newName', 'Pomodoro')->call('add');
        $ingredients->set('newName', 'Mozzarella')->call('add');
        $crawler = $ingredients->set('newName', 'Salamino piccante')->call('add')->render()->crawler();
        self::assertSame(['Mozzarella', 'Pomodoro', 'Salamino piccante'], $crawler->filter('[data-ingredient-name]')->each(fn (Crawler $n) => $n->text()));

        [$mozzarella, $tomato, $salami] = $this->em->getRepository(Ingredient::class)->findBy(['pizzeria' => $this->gino], ['normalizedName' => 'ASC']);
        $menu = $this->component('MenuPizzaManager', ['pizzeria' => $this->gino]);
        $crawler = $menu->render()->crawler();
        self::assertSame(['Mozzarella', 'Pomodoro', 'Salamino piccante'], $crawler->filter('form[data-live-action-param="add"] .form-check-label')->each(fn (Crawler $n) => $n->text()));

        $menu->set('name', 'Diavola')->set('price', '8')->set('ingredientIds', [(string) $tomato->getId(), (string) $mozzarella->getId(), (string) $salami->getId()])->call('add');
        $crawler = $menu->set('name', 'Margherita')->set('price', '')->set('ingredientIds', [(string) $tomato->getId(), (string) $mozzarella->getId()])->call('add')->render()->crawler();
        self::assertSame(['Diavola', 'Margherita'], $crawler->filter('[data-menu-pizza-name]')->each(fn (Crawler $n) => $n->text()));
        self::assertStringContainsString('Mozzarella, Pomodoro, Salamino piccante', $crawler->filter('[data-menu-pizza]')->first()->text());
        self::assertStringContainsString('8,00 €', $crawler->filter('[data-menu-pizza]')->first()->text());
        self::assertCount(0, $crawler->filter('form[data-live-action-param="add"] input[type="checkbox"][checked]'), 'Il form si svuota dopo l\'aggiunta');

        $margherita = $this->em->getRepository(MenuPizza::class)->findOneBy(['normalizedName' => 'margherita']);
        $crawler = $menu->call('startEdit', ['pizzaId' => $margherita->getId()])->render()->crawler();
        self::assertSame(['Mozzarella', 'Pomodoro'], $crawler->filter('form[data-live-action-param="save"] input[checked] + label')->each(fn (Crawler $n) => $n->text()));
        $crawler = $menu->set('editPrice', '7')->set('editIngredientIds', [(string) $tomato->getId()])->call('save')->render()->crawler();
        self::assertStringContainsString('7,00 €', $crawler->filter('[data-menu-pizza]')->last()->text());
        $this->em->refresh($margherita);
        self::assertSame(['Pomodoro'], $margherita->getIngredients()->map(fn (Ingredient $i) => $i->getName())->getValues());

        // Il client è condiviso e il kernel si riavvia tra le richieste: si rimonta il componente con la pizzeria riletta.
        $ingredients = $this->component('IngredientManager', ['pizzeria' => $this->freshPizzeria()]);
        $delete = $ingredients->render()->crawler()->filter('#ingredient-'.$salami->getId().' button:contains("Elimina")');
        self::assertStringContainsString('Verrà tolto da 1 pizza', (string) $delete->attr('data-confirm-message-param'));
        $ingredients->call('delete', ['ingredientId' => $salami->getId()]);

        $menu = $this->component('MenuPizzaManager', ['pizzeria' => $this->freshPizzeria()]);
        self::assertStringNotContainsString('Salamino', $menu->render()->crawler()->filter('[data-menu-pizza]')->first()->text());

        $crawler = $menu->call('delete', ['pizzaId' => $margherita->getId()])->render()->crawler();
        self::assertSame(['Diavola'], $crawler->filter('[data-menu-pizza-name]')->each(fn (Crawler $n) => $n->text()));
    }

    public function testErroriDiValidazioneMostrati(): void
    {
        $this->loginAsAdmin();
        $crawler = $this->component('MenuPizzaManager', ['pizzeria' => $this->gino])->set('name', 'Margherita')->set('price', 'tanto')->call('add')->render()->crawler();

        self::assertStringContainsString('Inserisci un prezzo valido, per esempio 7,50.', $crawler->filter('.alert-danger')->text());
    }

    public function testLeAzioniRichiedonoIlLoginAncheSullaRottaPubblica(): void
    {
        $this->loginAsAdmin();
        $html = $this->component('PizzeriaManager')->render()->toString();
        $props = json_decode(html_entity_decode((new Crawler($html))->filter('[data-live-props-value]')->attr('data-live-props-value')), true);
        self::assertStringStartsWith('/gestione/_components/', (string) (new Crawler($html))->filter('[data-live-url-value]')->attr('data-live-url-value'));

        // Senza sessione: come un visitatore qualsiasi che costruisce la richiesta a mano.
        $anonymous = $this->client;
        $anonymous->getCookieJar()->clear();
        $anonymous->catchExceptions(true);
        $post = fn (string $url) => $anonymous->request('POST', $url, [
            'data' => json_encode(['props' => $props, 'updated' => ['newName' => 'Abusiva'], 'args' => []]),
        ], [], ['HTTP_ACCEPT' => 'application/vnd.live-component+html', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        // Rotta pubblica, fuori dal firewall: rifiutata dal componente.
        $post('/_components/PizzeriaManager/add');
        self::assertResponseStatusCodeSame(403);

        // Rotta dell'area di gestione: il firewall rimanda al login.
        $post('/gestione/_components/PizzeriaManager/add');
        self::assertSame('http://localhost/gestione/login', $anonymous->getResponse()->headers->get('Location'));

        self::assertSame(0, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM pizzerias WHERE name = 'Abusiva'"));
    }

    public function testPaginaDiUnaPizzeriaInesistente(): void
    {
        $this->loginAsAdmin();
        $this->client->request('GET', '/gestione/pizzerie/999999');

        self::assertResponseStatusCodeSame(404);
    }

    private function freshPizzeria(): Pizzeria
    {
        return self::getContainer()->get(EntityManagerInterface::class)->find(Pizzeria::class, $this->gino->getId());
    }

    private function loginAsAdmin(): void
    {
        // L'utente deve coincidere con quello del provider, altrimenti al refresh il token viene scartato.
        $this->client->loginUser(self::getContainer()->get(AdminUserProvider::class)->loadUserByIdentifier('gestore'), 'gestione');
    }

    private function component(string $name, array $data = []): TestLiveComponent
    {
        return $this->createLiveComponent($name, $data, $this->client);
    }
}
