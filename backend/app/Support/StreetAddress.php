<?php

namespace App\Support;

class StreetAddress
{
    public static function merge(?string $address, ?string $number): string
    {
        $street = trim((string) $address);
        $number = trim((string) $number);

        if ($street === '') {
            return $number;
        }

        if ($number === '') {
            return $street;
        }

        if (preg_match('/[,\s]+'.preg_quote($number, '/').'$/iu', $street)) {
            return $street;
        }

        return "{$street}, {$number}";
    }

    /**
     * @return array{street: string, number: ?string, line: string}
     */
    public static function normalize(?string $address, ?string $number = null): array
    {
        $street = trim((string) $address);
        $number = trim((string) $number);

        if ($number === '' && $street !== '') {
            return self::split($street);
        }

        if ($number !== '') {
            $line = self::merge($street, $number);

            return self::split($line);
        }

        return [
            'street' => $street,
            'number' => null,
            'line' => $street,
        ];
    }

    /**
     * @return array{street: string, number: ?string, line: string}
     */
    public static function split(string $line): array
    {
        $line = trim($line);

        if ($line === '') {
            return [
                'street' => '',
                'number' => null,
                'line' => '',
            ];
        }

        if (preg_match('/^(.*?)[,\s]+((?:\d[\w\-\/]*|s\/n|sn))$/iu', $line, $matches)) {
            $street = trim($matches[1], " \t\n\r\0\x0B,");
            $number = strtoupper(trim($matches[2]));

            return [
                'street' => $street !== '' ? $street : $line,
                'number' => $number,
                'line' => self::merge($street !== '' ? $street : $line, $number),
            ];
        }

        return [
            'street' => $line,
            'number' => null,
            'line' => $line,
        ];
    }
}
