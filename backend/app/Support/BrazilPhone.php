<?php

namespace App\Support;

class BrazilPhone
{
    public static function digits(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value) ?? '';
    }

    public static function normalize(?string $value): string
    {
        $digits = self::digits($value);

        if ($digits === '') {
            return '';
        }

        if (! str_starts_with($digits, '55')) {
            $digits = '55'.$digits;
        }

        return $digits;
    }

    public static function matches(?string $left, ?string $right): bool
    {
        $a = self::normalize($left);
        $b = self::normalize($right);

        if ($a === '' || $b === '') {
            return false;
        }

        if ($a === $b) {
            return true;
        }

        return strlen($a) >= 11
            && strlen($b) >= 11
            && substr($a, -11) === substr($b, -11);
    }
}
