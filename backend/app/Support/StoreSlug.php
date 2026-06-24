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
        $slug = (string) preg_replace('/[?&#].*$/', '', $slug);
        $slug = trim($slug, '/');

        return $slug;
    }
}
