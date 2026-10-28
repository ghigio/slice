<?php

namespace App\Tests\Integration;

use App\Entity\Evening;
use App\Entity\Ingredient;
use App\Entity\MenuPizza;
use App\Entity\Pizzeria;
use App\Pizzeria\Exception\CatalogRuleException;
use App\Pizzeria\PizzeriaCatalog;
use App\Repository\PizzeriaRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PizzeriaCatalogTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PizzeriaCatalog $catalog;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->catalog = new PizzeriaCatalog($this->em, self::getContainer()->get(PizzeriaRepository::class));
    }

    public function testAggiungeRinominaEdEliminaPizzerie(): void
    {
        $gino = $this->catalog->addPizzeria('  Da  Gino ');
        self::assertNotNull($gino->getId());
        self::assertSame('Da Gino', $gino->getName());

        $this->catalog->renamePizzeria($gino, 'Da Gino 2');
        self::assertSame('Da Gino 2', $gino->getName());
        $this->catalog->renamePizzeria($gino, 'DA GINO 2');
        self::assertSame('DA GINO 2', $gino->getName(), 'Rinominare con la stessa chiave è ammesso');

        $id = $gino->getId();
        $this->catalog->deletePizzeria($gino);
        self::assertNull($this->em->find(Pizzeria::class, $id));
    }

    public static function invalidPizzeriaNames(): iterable
    {
        yield 'vuoto' => ['  ', 'Il nome della pizzeria è obbligatorio.'];
        yield 'troppo lungo' => [str_repeat('a', 101), 'Il nome della pizzeria può avere al massimo 100 caratteri.'];
        yield 'già presente' => ['da gìno', 'Esiste già una pizzeria con questo nome.'];
    }

    #[DataProvider('invalidPizzeriaNames')]
    public function testNomiDiPizzeriaNonValidi(string $name, string $message): void
    {
        $this->catalog->addPizzeria('Da Gino');

        $this->expectExceptionObject(new CatalogRuleException($message));
        $this->catalog->addPizzeria($name);
    }

    public function testSerateCheUsanoLaPizzeria(): void
    {
        $gino = $this->catalog->addPizzeria('Da Gino');
        $evening = new Evening('Serata', new \DateTimeImmutable('2026-10-19'), 'Org');
        $evening->setKnownPizzeria($gino);
        $this->em->persist($evening);
        $this->em->flush();

        self::assertSame(1, $this->catalog->countEvenings($gino));
    }

    public function testIngredienti(): void
    {
        $gino = $this->catalog->addPizzeria('Da Gino');
        $tomato = $this->catalog->addIngredient($gino, ' Pomodoro ');
        $basil = $this->catalog->addIngredient($gino, 'Basilico');
        $this->catalog->addMenuPizza($gino, 'Marinara', '5', [$tomato->getId(), $basil->getId()]);
        $this->catalog->addMenuPizza($gino, 'Basilicata', '', [$basil->getId()]);

        $this->catalog->renameIngredient($gino, $basil->getId(), 'Basilico fresco');
        self::assertSame('Basilico fresco', $basil->getName());

        self::assertSame(2, $this->catalog->deleteIngredient($gino, $basil->getId()), 'Numero di pizze da cui viene tolto');
        self::assertSame(['Pomodoro'], $gino->getIngredients()->map(fn (Ingredient $i) => $i->getName())->getValues());
        $this->em->clear();
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM menu_pizza_ingredients'));
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM menu_pizzas'));
    }

    public static function invalidIngredients(): iterable
    {
        yield 'vuoto' => ['', "Il nome dell'ingrediente è obbligatorio."];
        yield 'troppo lungo' => [str_repeat('a', 61), "Il nome dell'ingrediente può avere al massimo 60 caratteri."];
        yield 'già presente' => ['POMODORO', 'Esiste già un ingrediente con questo nome.'];
    }

    #[DataProvider('invalidIngredients')]
    public function testIngredientiNonValidi(string $name, string $message): void
    {
        $gino = $this->catalog->addPizzeria('Da Gino');
        $this->catalog->addIngredient($gino, 'Pomodoro');

        $this->expectExceptionObject(new CatalogRuleException($message));
        $this->catalog->addIngredient($gino, $name);
    }

    public function testPizzeDelMenu(): void
    {
        $gino = $this->catalog->addPizzeria('Da Gino');
        $tomato = $this->catalog->addIngredient($gino, 'Pomodoro');
        $mozzarella = $this->catalog->addIngredient($gino, 'Mozzarella');

        $pizza = $this->catalog->addMenuPizza($gino, ' Margherita ', '7,50 €', [$tomato->getId(), $mozzarella->getId()]);
        self::assertSame('Margherita', $pizza->getName());
        self::assertSame(750, $pizza->getPriceCents());
        self::assertCount(2, $pizza->getIngredients());

        $this->catalog->updateMenuPizza($gino, $pizza->getId(), 'Margherita DOP', '', [$mozzarella->getId()]);
        self::assertSame('Margherita DOP', $pizza->getName());
        self::assertNull($pizza->getPriceCents());
        self::assertSame(['Mozzarella'], $pizza->getIngredients()->map(fn (Ingredient $i) => $i->getName())->getValues());

        $id = $pizza->getId();
        $this->catalog->deleteMenuPizza($gino, $id);
        self::assertNull($this->em->find(MenuPizza::class, $id));
    }

    public static function invalidMenuPizzas(): iterable
    {
        yield 'nome vuoto' => [' ', '7', 'Il nome della pizza è obbligatorio.'];
        yield 'nome lungo' => [str_repeat('a', 81), '7', 'Il nome della pizza può avere al massimo 80 caratteri.'];
        yield 'già presente' => ['margherita', '7', 'Esiste già una pizza con questo nome nel menu.'];
        yield 'prezzo non valido' => ['Diavola', 'tanto', 'Inserisci un prezzo valido, per esempio 7,50.'];
        yield 'prezzo fuori intervallo' => ['Diavola', '120', 'Il prezzo deve essere tra 0,01 € e 100,00 €.'];
    }

    #[DataProvider('invalidMenuPizzas')]
    public function testPizzeDelMenuNonValide(string $name, string $price, string $message): void
    {
        $gino = $this->catalog->addPizzeria('Da Gino');
        $this->catalog->addMenuPizza($gino, 'Margherita', '', []);

        $this->expectExceptionObject(new CatalogRuleException($message));
        $this->catalog->addMenuPizza($gino, $name, $price, []);
    }

    public function testNonSiUsanoIngredientiOPizzeDiAltrePizzerie(): void
    {
        $gino = $this->catalog->addPizzeria('Da Gino');
        $totò = $this->catalog->addPizzeria('Da Totò');
        $foreignIngredient = $this->catalog->addIngredient($totò, 'Nduja');
        $foreignPizza = $this->catalog->addMenuPizza($totò, 'Calabrese', '', []);

        foreach ([
            'Ingrediente non trovato.' => fn () => $this->catalog->addMenuPizza($gino, 'Diavola', '', [$foreignIngredient->getId()]),
            'Ingrediente non trovato. ' => fn () => $this->catalog->deleteIngredient($gino, $foreignIngredient->getId()),
            'Pizza non trovata.' => fn () => $this->catalog->updateMenuPizza($gino, $foreignPizza->getId(), 'X', '', []),
            'Pizza non trovata. ' => fn () => $this->catalog->deleteMenuPizza($gino, $foreignPizza->getId()),
        ] as $message => $operation) {
            try {
                $operation();
                self::fail('Attesa CatalogRuleException');
            } catch (CatalogRuleException $e) {
                self::assertSame(trim($message), $e->getMessage());
            }
        }
        self::assertSame('Calabrese', $foreignPizza->getName());
        self::assertCount(0, $gino->getMenuPizzas());
    }
}
