<?php

namespace App\Tests\Integration;

use App\Entity\Eater;
use App\Entity\Evening;
use App\Entity\Pizza;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class EntityValidationTest extends KernelTestCase
{
    #[DataProvider('priceCases')]
    public function testPrezzoTraUnCentesimoECentoEuro(int $priceCents, bool $valid): void
    {
        $pizza = new Pizza($this->eater(), 'Anna', 'Margherita', $priceCents);

        self::assertSame($valid, 0 === $this->validator()->validateProperty($pizza, 'priceCents')->count());
    }

    public static function priceCases(): iterable
    {
        yield 'zero' => [0, false];
        yield 'negativo' => [-100, false];
        yield 'minimo' => [1, true];
        yield 'massimo' => [10000, true];
        yield 'oltre il massimo' => [10001, false];
    }

    public function testCampiObbligatoriDellaPizza(): void
    {
        $pizza = new Pizza($this->eater(), '   ', '  ', 700);
        $violations = $this->validator()->validate($pizza);

        $fields = array_map(fn ($v) => $v->getPropertyPath(), iterator_to_array($violations));
        self::assertContains('person', $fields);
        self::assertContains('pizzaName', $fields);
    }

    public function testLunghezzeMassimeDellaPizza(): void
    {
        $pizza = new Pizza($this->eater(), str_repeat('a', 61), str_repeat('b', 81), 700);
        $violations = $this->validator()->validate($pizza);

        $fields = array_map(fn ($v) => $v->getPropertyPath(), iterator_to_array($violations));
        self::assertContains('person', $fields);
        self::assertContains('pizzaName', $fields);
    }

    public function testCampiObbligatoriDellaSerataEDelGruppo(): void
    {
        $evening = new Evening(' ', new \DateTimeImmutable('2026-10-19'), '');
        $eater = new Eater($evening, '  ');

        $eveningFields = array_map(fn ($v) => $v->getPropertyPath(), iterator_to_array($this->validator()->validate($evening)));
        self::assertContains('name', $eveningFields);
        self::assertContains('organizerName', $eveningFields);
        self::assertCount(1, $this->validator()->validateProperty($eater, 'name'));
    }

    private function eater(): Eater
    {
        return new Eater(new Evening('Serata', new \DateTimeImmutable('2026-10-19'), 'Org'), 'Famiglia Rossi');
    }

    private function validator(): ValidatorInterface
    {
        self::bootKernel();

        return self::getContainer()->get(ValidatorInterface::class);
    }
}
