<?php

namespace App\Tests\Functional;

use App\Entity\Eater;
use App\Entity\Evening;
use App\Entity\Ingredient;
use App\Entity\MenuPizza;
use App\Entity\Pizza;
use App\Entity\Pizzeria;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * Pagina del gruppo con una pizzeria nota: scelta dal menu con filtro per ingrediente.
 */
final class PizzaMenuTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private EntityManagerInterface $em;
    private Evening $evening;
    private Eater $rossi;
    private Pizzeria $gino;
    /** @var array<string, Ingredient> */
    private array $ingredients = [];
    /** @var array<string, MenuPizza> */
    private array $menu = [];

    protected function setUp(): void
    {
        self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->gino = new Pizzeria('Da Gino');
        foreach (['Pomodoro', 'Mozzarella', 'Salamino piccante', 'Basilico'] as $name) {
            $this->ingredients[$name] = new Ingredient($this->gino, $name);
        }
        foreach ([
            'Margherita' => [700, ['Pomodoro', 'Mozzarella', 'Basilico']],
            'Diavola' => [800, ['Pomodoro', 'Mozzarella', 'Salamino piccante']],
            'Marinara' => [null, ['Pomodoro']],
        ] as $name => [$price, $ingredients]) {
            $this->menu[$name] = new MenuPizza($this->gino, $name, $price);
            $this->menu[$name]->setIngredients(array_map(fn (string $i) => $this->ingredients[$i], $ingredients));
        }
        $this->evening = new Evening('Serata', new \DateTimeImmutable('2026-10-19'), 'Org');
        $this->evening->setKnownPizzeria($this->gino);
        $this->rossi = new Eater($this->evening, 'Famiglia Rossi');
        $this->em->persist($this->gino);
        $this->em->persist($this->evening);
        $this->em->flush();
    }

    public function testSenzaPizzeriaNotaNessunMenu(): void
    {
        $evening = new Evening('Altra', new \DateTimeImmutable('2026-10-19'), 'Org', 'Bella Napoli');
        $eater = new Eater($evening, 'Famiglia Bianchi');
        $this->em->persist($evening);
        $this->em->flush();

        $crawler = $this->createLiveComponent('PizzaOrder', ['eater' => $eater])->render()->crawler();

        self::assertCount(0, $crawler->filter('button:contains("Scegli dal menu")'));
        self::assertCount(0, $crawler->filter('[data-menu]'));
    }

    public function testMenuConIngredientiEFiltro(): void
    {
        $component = $this->component();
        $crawler = $component->render()->crawler();
        self::assertStringContainsString('Scegli dal menu di Da Gino', $crawler->filter('button[data-live-action-param="toggleMenu"]')->text());
        self::assertCount(0, $crawler->filter('[data-menu-pizza]'), 'Il menu si apre con il pulsante');

        $crawler = $component->call('toggleMenu')->render()->crawler();
        self::assertSame(['Diavola', 'Margherita', 'Marinara'], $this->menuNames($crawler));
        self::assertStringContainsString('Basilico, Mozzarella, Pomodoro', $crawler->filter('[data-menu-pizza]')->eq(1)->text());
        self::assertStringNotContainsString('€', $crawler->filter('[data-menu]')->text(), 'Il gruppo non vede i prezzi');

        $crawler = $component->set('ingredientFilter', [$this->id('Mozzarella')])->render()->crawler();
        self::assertSame(['Diavola', 'Margherita'], $this->menuNames($crawler));

        $crawler = $component->set('ingredientFilter', [$this->id('Mozzarella'), $this->id('Basilico')])->render()->crawler();
        self::assertSame(['Margherita'], $this->menuNames($crawler), 'Con più ingredienti servono tutti');

        $crawler = $component->set('ingredientFilter', [$this->id('Basilico'), $this->id('Salamino piccante')])->render()->crawler();
        self::assertSame([], $this->menuNames($crawler));
        self::assertStringContainsString('Nessuna pizza con questi ingredienti.', $crawler->filter('[data-menu]')->text());

        $crawler = $component->call('clearFilter')->render()->crawler();
        self::assertCount(3, $crawler->filter('[data-menu-pizza]'));
    }

    public function testSceltaDalMenuEPrezzoDelMenu(): void
    {
        $component = $this->component();
        $component->call('toggleMenu')->call('chooseMenuPizza', ['menuPizzaId' => $this->menu['Diavola']->getId()]);
        $crawler = $component->render()->crawler();

        self::assertSame('Diavola', $crawler->filter('#eater-'.$this->rossi->getId().'-pizza')->attr('value'));
        self::assertCount(0, $crawler->filter('[data-menu-pizza]'), 'Il menu si chiude dopo la scelta');

        $component->set('person', 'Anna')->call('add');
        $pizza = $this->em->getRepository(Pizza::class)->findOneBy(['eater' => $this->rossi]);
        self::assertSame('Diavola', $pizza->getPizzaName());
        self::assertSame(800, $pizza->getPriceCents());
    }

    public function testNomeLiberoAncoraPossibile(): void
    {
        $this->component()->set('person', 'Anna')->set('pizzaName', 'Margherita baby')->call('add');

        $pizza = $this->em->getRepository(Pizza::class)->findOneBy(['eater' => $this->rossi]);
        self::assertSame('Margherita baby', $pizza->getPizzaName());
        self::assertNull($pizza->getPriceCents());
    }

    public function testNonSiSceglieUnaPizzaDiUnAltraPizzeria(): void
    {
        $other = new Pizzeria('Da Totò');
        $foreign = new MenuPizza($other, 'Calabrese', 900);
        $this->em->persist($other);
        $this->em->flush();

        $crawler = $this->component()->call('chooseMenuPizza', ['menuPizzaId' => $foreign->getId()])->render()->crawler();

        self::assertStringContainsString('Pizza non trovata nel menu.', $crawler->filter('.alert-danger')->text());
        self::assertSame('', (string) $crawler->filter('#eater-'.$this->rossi->getId().'-pizza')->attr('value'));
    }

    public function testSuggerimentiConINomiDelMenu(): void
    {
        $component = $this->component();
        $crawler = $component->render()->crawler();
        self::assertSame(['Diavola', 'Margherita', 'Marinara'], $crawler->filter('datalist[data-pizza-suggestions] option')->each(fn (Crawler $o) => $o->attr('value')));

        $crawler = $component->set('pizzaName', 'Magherita')->render()->crawler();
        self::assertStringContainsString('Esiste già «Margherita»', $crawler->filter('[data-similar-suggestion]')->text());
    }

    public function testLOrganizzatoreRiceveIlPrezzoDelMenuComeSuggerimento(): void
    {
        $crawler = $this->createLiveComponent('PizzaOrder', ['eater' => $this->rossi, 'asOrganizer' => true])->render()->crawler();

        $prices = json_decode((string) $crawler->filter('[data-pizza-price-prices-value]')->attr('data-pizza-price-prices-value'), true);
        self::assertSame(['diavola' => '8,00', 'margherita' => '7,00'], $prices);
    }

    private function component(): TestLiveComponent
    {
        return $this->createLiveComponent('PizzaOrder', ['eater' => $this->rossi]);
    }

    private function id(string $ingredient): string
    {
        return (string) $this->ingredients[$ingredient]->getId();
    }

    /** @return list<string> */
    private function menuNames(Crawler $crawler): array
    {
        return $crawler->filter('[data-menu-pizza-name]')->each(fn (Crawler $n) => $n->text());
    }
}
