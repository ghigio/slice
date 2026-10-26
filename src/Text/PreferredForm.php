<?php

namespace App\Text;

/**
 * Sceglie la forma da mostrare tra le varianti digitate di uno stesso nome:
 * la più frequente e, a parità, la più recente.
 */
final class PreferredForm
{
    /** @param list<string> $forms forme digitate in ordine di inserimento */
    public static function pick(array $forms): string
    {
        $count = [];
        $lastSeen = [];
        foreach ($forms as $position => $form) {
            $count[$form] = ($count[$form] ?? 0) + 1;
            $lastSeen[$form] = $position;
        }

        $candidates = array_map('strval', array_keys($count));
        usort($candidates, fn ($a, $b) => [$count[$b], $lastSeen[$b]] <=> [$count[$a], $lastSeen[$a]]);

        return $candidates[0];
    }
}
