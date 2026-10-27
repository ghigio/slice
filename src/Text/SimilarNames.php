<?php

namespace App\Text;

/**
 * Nomi di pizza simili, per suggerire un nome già inserito ed evitare doppioni come «Salamino» e «Salamino piccante».
 * Due nomi normalizzati diversi sono simili se uno contiene tutte le parole dell'altro, oppure se differiscono
 * per al massimo 1 carattere (nomi fino a 6 caratteri) o 2 (nomi più lunghi).
 */
final class SimilarNames
{
    /** Le parole in più contano meno di un errore di battitura: a parità vince il nome più vicino. */
    private const int WORDS_SCORE = 3;

    /**
     * Nome esistente più simile a quello digitato; null se non ce n'è o se il nome digitato esiste già.
     *
     * @param list<string> $existing a parità di somiglianza vince il primo
     */
    public static function find(string $typed, array $existing): ?string
    {
        $typedKey = NameNormalizer::normalize($typed);
        if ('' === $typedKey) {
            return null;
        }

        $best = null;
        $bestScore = PHP_INT_MAX;
        foreach ($existing as $name) {
            $key = NameNormalizer::normalize($name);
            if ($key === $typedKey) {
                return null;
            }
            $score = self::score($typedKey, $key);
            if (null !== $score && $score < $bestScore) {
                $best = $name;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * Coppie di nomi simili, nell'ordine in cui compaiono.
     *
     * @param list<string> $names
     *
     * @return list<array{string, string}>
     */
    public static function pairs(array $names): array
    {
        $pairs = [];
        foreach ($names as $i => $a) {
            foreach (\array_slice($names, $i + 1) as $b) {
                $keyA = NameNormalizer::normalize($a);
                $keyB = NameNormalizer::normalize($b);
                if ($keyA !== $keyB && null !== self::score($keyA, $keyB)) {
                    $pairs[] = [$a, $b];
                }
            }
        }

        return $pairs;
    }

    /** Distanza tra due nomi normalizzati diversi; null se non sono simili. */
    private static function score(string $a, string $b): ?int
    {
        $distance = levenshtein($a, $b);
        if ($distance <= (min(mb_strlen($a), mb_strlen($b)) <= 6 ? 1 : 2)) {
            return $distance;
        }

        [$shorter, $longer] = \count(explode(' ', $a)) <= \count(explode(' ', $b)) ? [$a, $b] : [$b, $a];
        if ([] === array_diff(explode(' ', $shorter), explode(' ', $longer))) {
            return self::WORDS_SCORE;
        }

        return null;
    }
}
