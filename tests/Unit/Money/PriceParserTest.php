<?php

namespace App\Tests\Unit\Money;

use App\Money\InvalidPriceException;
use App\Money\PriceParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PriceParserTest extends TestCase
{
    #[DataProvider('validPrices')]
    public function testAccettaVirgolaEPunto(string $input, int $cents): void
    {
        self::assertSame($cents, PriceParser::parseCents($input));
    }

    public static function validPrices(): iterable
    {
        yield 'intero' => ['7', 700];
        yield 'virgola un decimale' => ['7,5', 750];
        yield 'punto due decimali' => ['7.50', 750];
        yield 'spazi e simbolo euro' => [' 12,30 € ', 1230];
        yield 'euro attaccato' => ['8€', 800];
        yield 'minimo' => ['0,01', 1];
        yield 'massimo' => ['100', 10000];
        yield 'massimo con decimali' => ['100,00', 10000];
    }

    #[DataProvider('invalidPrices')]
    public function testRifiutaValoriNonValidi(string $input, string $message): void
    {
        $this->expectExceptionObject(new InvalidPriceException($message));
        PriceParser::parseCents($input);
    }

    public static function invalidPrices(): iterable
    {
        $format = 'Inserisci un prezzo valido, per esempio 7,50.';
        $range = 'Il prezzo deve essere tra 0,01 € e 100,00 €.';
        yield 'vuoto' => ['', $format];
        yield 'testo' => ['abc', $format];
        yield 'tre decimali' => ['7,555', $format];
        yield 'separatore migliaia' => ['1.000', $format];
        yield 'negativo' => ['-5', $format];
        yield 'zero' => ['0', $range];
        yield 'zero con decimali' => ['0,00', $range];
        yield 'oltre il massimo' => ['100,01', $range];
        yield 'molto oltre' => ['250', $range];
    }
}
