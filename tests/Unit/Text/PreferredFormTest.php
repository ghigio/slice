<?php

namespace App\Tests\Unit\Text;

use App\Text\PreferredForm;
use PHPUnit\Framework\TestCase;

final class PreferredFormTest extends TestCase
{
    public function testVinceLaFormaPiuFrequente(): void
    {
        self::assertSame('Margherita', PreferredForm::pick(['margherita', 'Margherita', 'MARGHERITA', 'Margherita']));
    }

    public function testAParitaVinceLaPiuRecente(): void
    {
        self::assertSame('diavola', PreferredForm::pick(['Diavola', 'diavola', 'DIAVOLA', 'Diavola', 'diavola']));
    }

    public function testFormeNumericheRestanoStringhe(): void
    {
        self::assertSame('4', PreferredForm::pick(['4', '4']));
    }
}
