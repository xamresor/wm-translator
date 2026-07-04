<?php
declare(strict_types=1);

namespace Translator;

/** Реестр поддерживаемых языков. */
final class Lang
{
    public const PIVOT = 'en';

    /** code => [native UI label, English name for Gemini prompts, flag] */
    public const LANGS = [
        'en'      => ['English',     'English',                                              '🇬🇧'],
        'ru'      => ['Русский',     'Russian (in Cyrillic script)',                         '🇷🇺'],
        'lv'      => ['Latviešu',    'Latvian',                                              '🇱🇻'],
        'ru-latn' => ['Rus (latin)', 'Russian transliterated into Latin letters (e.g. "privet, kak dela")', '🔤'],
        'lt'      => ['Lietuvių',    'Lithuanian',                                           '🇱🇹'],
        'et'      => ['Eesti',       'Estonian',                                             '🇪🇪'],
        'pl'      => ['Polski',      'Polish',                                               '🇵🇱'],
        'de'      => ['Deutsch',     'German',                                               '🇩🇪'],
        'uk'      => ['Українська',  'Ukrainian (in Cyrillic script)',                       '🇺🇦'],
        'es'      => ['Español',     'Spanish',                                              '🇪🇸'],
    ];

    public static function exists(string $code): bool
    {
        return isset(self::LANGS[$code]);
    }

    public static function normalize(string $code): string
    {
        return self::exists($code) ? $code : 'ru';
    }

    /** Английское имя языка для промптов Gemini. */
    public static function promptName(string $code): string
    {
        return self::LANGS[$code][1] ?? 'English';
    }

    /** Список для фронта: [{code, label, flag}]. */
    public static function list(): array
    {
        $out = [];
        foreach (self::LANGS as $code => [$label, , $flag]) {
            $out[] = ['code' => $code, 'label' => $label, 'flag' => $flag];
        }
        return $out;
    }
}
