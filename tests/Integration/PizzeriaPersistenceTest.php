<?php

namespace App\Tests\Integration;

use App\Entity\Evening;
use App\Entity\Ingredient;
use App\Entity\MenuPizza;
use App\Entity\Pizzeria;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PizzeriaPersistenceTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testPersisteMenuEIngredienti(): void
    {
        $pizzeria = new Pizzeria('  Da  Gino ');
        $tomato = new Ingredient($pizzeria, 'Pomodoro');
        $mozzarella = new Ingredient($pizzeria, 'Mozzarella');
        $salami = new Ingredient($pizzeria, 'Salamino piccante');
        $margherita = new MenuPizza($pizzeria, 'Margherita', 700);
        $margherita->setIngredients([$tomato, $mozzarella]);
        $diavola = new MenuPizza($pizzeria, 'Diavola', null);
        $diavola->setIngredients([$tomato, $mozzarella, $salami]);
        $this->em->persist($pizzeria);
        $this->em->flush();
        $this->em->clear();

        $loaded = $this->em->find(Pizzeria::class, $pizzeria->getId());
        self::assertSame('Da Gino', $loaded->getName());
        self::assertSame('da gino', $loaded->getNormalizedName());
        self::assertSame(['Mozzarella', 'Pomodoro', 'Salamino piccante'], $loaded->getIngredients()->map(fn (Ingredient $i) => $i->getName())->getValues());
        self::assertSame(['Diavola', 'Margherita'], $loaded->getMenuPizzas()->map(fn (MenuPizza $p) => $p->getName())->getValues());

        $loadedMargherita = $loaded->getMenuPizzas()->last();
        self::assertSame(700, $loadedMargherita->getPriceCents());
        self::assertSame('margherita', $loadedMargherita->getNormalizedName());
        self::assertSame(['Mozzarella', 'Pomodoro'], $loadedMargherita->getIngredients()->map(fn (Ingredient $i) => $i->getName())->getValues());
        self::assertNull($loaded->getMenuPizzas()->first()->getPriceCents());
        self::assertNotNull($loaded->getCreatedAt());
    }

    public function testCancellareLaPizzeriaCancellaMenuEIngredientiEAzzeraLaSerata(): void
    {
        $pizzeria = new Pizzeria('Da Gino');
        $tomato = new Ingredient($pizzeria, 'Pomodoro');
        (new MenuPizza($pizzeria, 'Marinara', 500))->setIngredients([$tomato]);
        $evening = new Evening('Serata', new \DateTimeImmutable('2026-10-19'), 'Org');
        $evening->setKnownPizzeria($pizzeria);
        $this->em->persist($pizzeria);
        $this->em->persist($evening);
        $this->em->flush();

        self::assertSame('Da Gino', $evening->getPizzeria(), 'La serata copia il nome della pizzeria');

        $connection = $this->em->getConnection();
        $connection->executeStatement('DELETE FROM pizzerias WHERE id = ?', [$pizzeria->getId()]);

        foreach (['ingredients', 'menu_pizzas', 'menu_pizza_ingredients'] as $table) {
            self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM $table"), $table);
        }
        self::assertNull($connection->fetchOne('SELECT pizzeria_id FROM evenings WHERE id = ?', [$evening->getId()]));
        self::assertSame('Da Gino', $connection->fetchOne('SELECT pizzeria FROM evenings WHERE id = ?', [$evening->getId()]));
    }

    public function testCancellareUnIngredienteLoTogliePizze(): void
    {
        $pizzeria = new Pizzeria('Da Gino');
        $tomato = new Ingredient($pizzeria, 'Pomodoro');
        $basil = new Ingredient($pizzeria, 'Basilico');
        $pizza = new MenuPizza($pizzeria, 'Marinara', 500);
        $pizza->setIngredients([$tomato, $basil]);
        $this->em->persist($pizzeria);
        $this->em->flush();

        $this->em->getConnection()->executeStatement('DELETE FROM ingredients WHERE id = ?', [$basil->getId()]);

        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM menu_pizza_ingredients'));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM menu_pizzas'));
    }

    public function testNomiUniciSenzaMaiuscoleNeAccenti(): void
    {
        $this->em->persist(new Pizzeria('Da Gino'));
        $this->em->persist(new Pizzeria('DA GINÒ'));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testStessaPizzaAmmessaInPizzerieDiverse(): void
    {
        $gino = new Pizzeria('Da Gino');
        $totò = new Pizzeria('Da Totò');
        new MenuPizza($gino, 'Margherita', 700);
        new MenuPizza($totò, 'Margherita', 650);
        new Ingredient($gino, 'Pomodoro');
        new Ingredient($totò, 'Pomodoro');
        $this->em->persist($gino);
        $this->em->persist($totò);
        $this->em->flush();

        self::assertSame(2, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM menu_pizzas WHERE normalized_name = 'margherita'"));
    }

    public function testPizzaDelMenuUnicaNellaPizzeria(): void
    {
        $pizzeria = new Pizzeria('Da Gino');
        new MenuPizza($pizzeria, 'Margherita', 700);
        new MenuPizza($pizzeria, 'margherita ', null);
        $this->em->persist($pizzeria);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }
}
