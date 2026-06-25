<?php

namespace App\Support;

class StoreSlug
{
    public static function normalize(?string $rawSlug): string
    {
        if ($rawSlug === null || $rawSlug === '') {
            return '';
        }

        $slug = strtolower(trim(urldecode($rawSlug)));

        for ($i = 0; $i < 3; $i++) {
            $decoded = urldecode($slug);

            if ($decoded === $slug) {
                break;
            }

            $slug = $decoded;
        }

        $slug = (string) preg_replace('/[?&#].*$/', '', $slug);
        $slug = trim($slug, '/');

        return $slug;
    }
}
