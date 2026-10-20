<?php

namespace App\Text;

/**
 * Pulizia dei nomi digitati dagli utenti e chiave di aggregazione per le pizze.
 */
final class NameNormalizer
{
    private static ?\Transliterator $transliterator = null;

    /** Toglie spazi iniziali/finali e compatta gli spazi interni, conservando maiuscole e accenti. */
    public static function clean(string $value): string
    {
        return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $value));
    }

    /** Forma pulita, in minuscolo e senza accenti: «  Würstel  » → «wurstel». */
    public static function normalize(string $value): string
    {
        self::$transliterator ??= \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC; Lower()');

        return self::$transliterator->transliterate(self::clean($value));
    }
}
