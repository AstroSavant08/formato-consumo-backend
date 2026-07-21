<?php

namespace App\Support;

class TextNormalizer
{
    public static function normalize(string $value): string
    {
        $value = trim($value);
        $value = mb_strtoupper($value, 'UTF-8');
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        if ($transliterated !== false) {
            $value = $transliterated;
        }

        return preg_replace('/\s+/', ' ', $value) ?? $value;
    }

    public static function normalizeUnit(?string $unit): ?string
    {
        if ($unit === null || trim($unit) === '') {
            return null;
        }

        $normalized = self::normalize($unit);

        return match ($normalized) {
            'UNIDAD' => 'UND',
            default => $normalized,
        };
    }

    public static function fixEncoding(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $replacements = [
            "CAF\u{00C3}\u{0089}" => 'CAFÉ',
            "BA\u{00C3}\u{0091}O" => 'BAÑO',
            "PEQUE\u{00C3}\u{0091}O" => 'PEQUEÑO',
            "CU\u{00C3}\u{0091}ETE" => 'CUÑETE',
        ];

        return trim(str_replace(array_keys($replacements), array_values($replacements), $value));
    }
}
