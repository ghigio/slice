<?php

namespace App\Tests\Unit;

use App\Text\NameNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NameNormalizerTest extends TestCase
{
    #[DataProvider('cleanCases')]
    public function testCleanRimuoveSpaziInEccesso(string $input, string $expected): void
    {
        self::assertSame($expected, NameNormalizer::clean($input));
    }

    public static function cleanCases(): iterable
    {
        yield 'spazi esterni' => ['  Margherita  ', 'Margherita'];
        yield 'spazi multipli interni' => ['Quattro   Stagioni', 'Quattro Stagioni'];
        yield 'tab e a capo' => ["Diavola\t \nPiccante", 'Diavola Piccante'];
        yield 'spazio non separabile' => ["Capricciosa\u{00A0}Speciale", 'Capricciosa Speciale'];
        yield 'maiuscole e accenti conservati' => ['Würstel e Patatine', 'Würstel e Patatine'];
    }

    #[DataProvider('normalizeCases')]
    public function testNormalizeProduceChiaveDiAggregazione(string $input, string $expected): void
    {
        self::assertSame($expected, NameNormalizer::normalize($input));
    }

    public static function normalizeCases(): iterable
    {
        yield 'forma base' => ['Margherita', 'margherita'];
        yield 'minuscolo con spazi' => [' margherita ', 'margherita'];
        yield 'maiuscolo con spazi finali' => ['MARGHERITA  ', 'margherita'];
        yield 'spazi interni' => ['Quattro   Stagioni', 'quattro stagioni'];
        yield 'umlaut' => ['Würstel e patatine', 'wurstel e patatine'];
        yield 'accenti italiani' => ['Città Così', 'citta cosi'];
    }
}
