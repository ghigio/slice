<?php

namespace App\Tests\Unit\Text;

use App\Text\SimilarNames;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SimilarNamesTest extends TestCase
{
    public static function similar(): iterable
    {
        yield 'parola in più' => ['Salamino piccante', ['Margherita', 'Salamino'], 'Salamino'];
        yield 'parola in meno' => ['salamino', ['Salamino piccante'], 'Salamino piccante'];
        yield 'errore di battitura' => ['Magherita', ['Diavola', 'Margherita'], 'Margherita'];
        yield 'due errori su nome lungo' => ['Capriciosa', ['Capricciosa'], 'Capricciosa'];
        yield 'accenti e maiuscole' => ['WURSTEL e patatine', ['Würstel'], 'Würstel'];
        yield 'nome breve, un errore' => ['Diavolo', ['Diavola'], 'Diavola'];
        yield 'vince il più vicino' => ['Diavola piccant', ['Diavola', 'Diavola piccante'], 'Diavola piccante'];
    }

    /** @param list<string> $existing */
    #[DataProvider('similar')]
    public function testSuggerisceIlNomeSimile(string $typed, array $existing, string $expected): void
    {
        self::assertSame($expected, SimilarNames::find($typed, $existing));
    }

    public static function notSimilar(): iterable
    {
        yield 'stesso nome normalizzato' => [' margherita ', ['Margherita']];
        yield 'nomi diversi' => ['Marinara', ['Margherita']];
        yield 'quattro stagioni e quattro formaggi' => ['4 stagioni', ['4 formaggi']];
        yield 'nome breve, due errori' => ['Bufala', ['Bufalo e', 'Fufalo']];
        yield 'inizio di parola' => ['sal', ['Salamino']];
        yield 'vuoto' => ['  ', ['Margherita']];
        yield 'nessun nome' => ['Margherita', []];
    }

    /** @param list<string> $existing */
    #[DataProvider('notSimilar')]
    public function testNessunSuggerimento(string $typed, array $existing): void
    {
        self::assertNull(SimilarNames::find($typed, $existing));
    }

    public function testCoppieSimili(): void
    {
        self::assertSame(
            [['Margherita', 'Magherita'], ['Salamino', 'Salamino piccante']],
            SimilarNames::pairs(['Margherita', 'Salamino', 'Diavola', 'Magherita', 'Salamino piccante']),
        );
    }
}
