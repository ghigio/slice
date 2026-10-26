<?php

namespace App\Twig;

use App\Money\MoneyFormatter;
use Twig\Attribute\AsTwigFilter;

final class MoneyExtension
{
    /** Centesimi nel formato «12,50 €». */
    #[AsTwigFilter('money')]
    public function money(int $cents): string
    {
        return MoneyFormatter::format($cents);
    }
}
