<?php

namespace App\Tools;

class PlateTools
{
    /** حروف پلاک سواری ایران (غیر از طرح‌های خاص) */
    public const LETTERS = [
        'الف', 'ب', 'پ', 'ت', 'ث', 'ج', 'د', 'س', 'ص', 'ط',
        'ع', 'ق', 'ک', 'گ', 'ل', 'م', 'ن', 'و', 'ه', 'ی',
    ];

    private const LATIN_LETTER_MAP = [
        'A' => 'الف',
        'B' => 'ب',
        'P' => 'پ',
        'T' => 'ت',
        'J' => 'ج',
        'D' => 'د',
        'S' => 'س',
        'C' => 'ص',
        'L' => 'ل',
        'M' => 'م',
        'N' => 'ن',
        'V' => 'و',
        'W' => 'و',
        'H' => 'ه',
        'Y' => 'ی',
        'Q' => 'ق',
        'G' => 'گ',
        'K' => 'ک',
        'E' => 'ع',
        'U' => 'ع',
    ];

    public static function toEnglishDigits(string $value): string
    {
        $fa = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $ar = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $en = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

        return str_replace($ar, $en, str_replace($fa, $en, $value));
    }

    public static function normalizeLetter(string $letter): string
    {
        $letter = trim($letter);
        $letter = str_replace(['ي', 'ى', 'ئ'], 'ی', $letter);
        $letter = str_replace(['ك'], 'ک', $letter);
        if ($letter === '') {
            return '';
        }
        if (isset(self::LATIN_LETTER_MAP[strtoupper($letter)])) {
            return self::LATIN_LETTER_MAP[strtoupper($letter)];
        }

        return $letter;
    }

    /**
     * @return array{serial: string, letter: string, middle: string, province: string, compact: string, display: string}|null
     */
    public static function parse($input): ?array
    {
        if (is_array($input)) {
            $serial = self::toEnglishDigits((string) ($input['serial'] ?? $input['two'] ?? ''));
            $letter = self::normalizeLetter((string) ($input['letter'] ?? ''));
            $middle = self::toEnglishDigits((string) ($input['middle'] ?? $input['three'] ?? ''));
            $province = self::toEnglishDigits((string) ($input['province'] ?? $input['region'] ?? ''));

            return self::fromParts($serial, $letter, $middle, $province);
        }

        $raw = self::toEnglishDigits(trim((string) $input));
        if ($raw === '') {
            return null;
        }

        $raw = str_replace(['ي', 'ى', 'ئ'], 'ی', $raw);
        $raw = str_replace(['ك'], 'ک', $raw);
        $compact = preg_replace('/[\s\-_|٫،.]+/u', '', $raw) ?? $raw;

        if (preg_match('/^(\d{2})(.{1,4}?)(\d{3})(\d{2})$/u', $compact, $m)) {
            return self::fromParts($m[1], self::normalizeLetter($m[2]), $m[3], $m[4]);
        }

        if (preg_match('/(\d{2})\s*([A-Za-zآ-یالف]{1,4})\s*(\d{3})\s*(\d{2})/u', $raw, $m)) {
            return self::fromParts($m[1], self::normalizeLetter($m[2]), $m[3], $m[4]);
        }

        return null;
    }

    /**
     * @return array{serial: string, letter: string, middle: string, province: string, compact: string, display: string}|null
     */
    public static function fromParts(string $serial, string $letter, string $middle, string $province): ?array
    {
        $serial = preg_replace('/\D/', '', $serial) ?? '';
        $middle = preg_replace('/\D/', '', $middle) ?? '';
        $province = preg_replace('/\D/', '', $province) ?? '';
        $letter = self::normalizeLetter($letter);

        if (strlen($serial) !== 2 || strlen($middle) !== 3 || strlen($province) !== 2 || $letter === '') {
            return null;
        }
        if (! in_array($letter, self::LETTERS, true)) {
            return null;
        }

        $compact = $serial.$letter.$middle.$province;

        return [
            'serial' => $serial,
            'letter' => $letter,
            'middle' => $middle,
            'province' => $province,
            'compact' => $compact,
            'display' => $serial.' '.$letter.' '.$middle.' | '.$province,
        ];
    }
}
