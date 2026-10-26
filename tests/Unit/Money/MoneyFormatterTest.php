<?php

namespace App\Tests\Unit\Money;

use App\Money\MoneyFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyFormatterTest extends TestCase
{
    #[DataProvider('amounts')]
    public function testFormatoItaliano(int $cents, string $expected): void
    {
        self::assertSame($expected, MoneyFormatter::format($cents));
    }

    public static function amounts(): iterable
    {
        yield 'zero' => [0, '0,00 €'];
        yield 'centesimi' => [5, '0,05 €'];
        yield 'normale' => [1250, '12,50 €'];
        yield 'migliaia' => [123456, '1.234,56 €'];
        yield 'negativo' => [-1, '-0,01 €'];
        yield 'negativo grande' => [-123456, '-1.234,56 €'];
    }

    public function testValorePerCampoDiInput(): void
    {
        self::assertSame('7,50', MoneyFormatter::formatInput(750));
        self::assertSame('100,00', MoneyFormatter::formatInput(10000));
    }
}
