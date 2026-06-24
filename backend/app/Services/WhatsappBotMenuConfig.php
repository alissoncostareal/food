<?php

namespace App\Services;

use App\Models\Store;
use InvalidArgumentException;

class WhatsappBotMenuConfig
{
    public const ACTION_MENU = 'menu';

    public const ACTION_HOURS = 'hours';

    public const ACTION_ORDER = 'order';

    public const ACTION_HUMAN = 'human';

    public static function actionLabels(): array
    {
        return [
            self::ACTION_MENU => 'Enviar link do cardápio',
            self::ACTION_HOURS => 'Horário de funcionamento',
            self::ACTION_ORDER => 'Status do pedido',
            self::ACTION_HUMAN => 'Falar com atendente',
        ];
    }

    public static function defaults(): array
    {
        return [
            [
                'action' => self::ACTION_MENU,
                'digit' => '1',
                'label' => 'Ver cardápio',
                'enabled' => true,
            ],
            [
                'action' => self::ACTION_HOURS,
                'digit' => '2',
                'label' => 'Horário de funcionamento',
                'enabled' => true,
            ],
            [
                'action' => self::ACTION_ORDER,
                'digit' => '3',
                'label' => 'Status do meu pedido',
                'enabled' => true,
            ],
            [
                'action' => self::ACTION_HUMAN,
                'digit' => '4',
                'label' => 'Falar com atendente',
                'enabled' => true,
            ],
        ];
    }

    public static function forStore(Store $store): array
    {
        $custom = is_array($store->whatsapp_bot_menu) ? $store->whatsapp_bot_menu : null;

        if (is_array($custom) && $custom !== []) {
            return static::normalizeOptions($custom);
        }

        return static::fromLegacyMessages($store);
    }

    public static function enabledOptions(Store $store): array
    {
        return array_values(array_filter(
            static::forStore($store),
            fn (array $option) => (bool) ($option['enabled'] ?? true)
        ));
    }

    public static function actionForDigit(Store $store, string $digit): ?string
    {
        $digit = static::normalizeDigit($digit);

        if ($digit === '') {
            return null;
        }

        foreach (static::enabledOptions($store) as $option) {
            if ($option['digit'] === $digit) {
                return $option['action'];
            }
        }

        return null;
    }

    public static function menuLines(Store $store): array
    {
        return array_map(
            fn (array $option) => static::formatLine($option),
            static::enabledOptions($store)
        );
    }

    public static function formatLine(array $option): string
    {
        $digit = static::normalizeDigit((string) ($option['digit'] ?? ''));
        $label = trim((string) ($option['label'] ?? ''));

        if ($digit === '' || $label === '') {
            return $label;
        }

        return "{$digit} - {$label}";
    }

    public static function sanitizeInput(array $options): array
    {
        $defaults = collect(static::defaults())->keyBy('action');
        $allowedActions = array_keys(static::actionLabels());
        $normalized = [];

        foreach ($options as $option) {
            if (! is_array($option)) {
                continue;
            }

            $action = (string) ($option['action'] ?? '');

            if (! in_array($action, $allowedActions, true)) {
                continue;
            }

            $fallback = $defaults->get($action, []);
            $digit = static::normalizeDigit((string) ($option['digit'] ?? $fallback['digit'] ?? ''));
            $label = trim((string) ($option['label'] ?? $fallback['label'] ?? ''));

            if ($digit === '' || $label === '') {
                throw new InvalidArgumentException('Cada opção precisa de um número (1–9) e um texto.');
            }

            $normalized[] = [
                'action' => $action,
                'digit' => $digit,
                'label' => $label,
                'enabled' => array_key_exists('enabled', $option)
                    ? (bool) $option['enabled']
                    : (bool) ($fallback['enabled'] ?? true),
            ];
        }

        if ($normalized === []) {
            return static::defaults();
        }

        $enabledDigits = collect($normalized)
            ->filter(fn (array $option) => $option['enabled'])
            ->pluck('digit');

        if ($enabledDigits->duplicates()->isNotEmpty()) {
            throw new InvalidArgumentException('Cada número do menu deve ser único.');
        }

        $actions = collect($normalized)->pluck('action');

        if ($actions->duplicates()->isNotEmpty()) {
            throw new InvalidArgumentException('Cada ação do menu deve aparecer apenas uma vez.');
        }

        return static::normalizeOptions($normalized);
    }

    public static function differsFromDefaults(array $options): bool
    {
        return json_encode(static::normalizeOptions($options)) !== json_encode(static::defaults());
    }

    private static function normalizeOptions(array $options): array
    {
        $defaultsByAction = collect(static::defaults())->keyBy('action');
        $incomingByAction = collect($options)->keyBy('action');
        $normalized = [];

        foreach (array_keys(static::actionLabels()) as $action) {
            $fallback = $defaultsByAction->get($action, []);
            $incoming = $incomingByAction->get($action, $fallback);

            if (! is_array($incoming)) {
                $incoming = $fallback;
            }

            $digit = static::normalizeDigit((string) ($incoming['digit'] ?? $fallback['digit'] ?? ''));
            $label = trim((string) ($incoming['label'] ?? $fallback['label'] ?? ''));

            $normalized[] = [
                'action' => $action,
                'digit' => $digit !== '' ? $digit : (string) ($fallback['digit'] ?? '1'),
                'label' => $label !== '' ? $label : (string) ($fallback['label'] ?? ''),
                'enabled' => array_key_exists('enabled', $incoming)
                    ? (bool) $incoming['enabled']
                    : (bool) ($fallback['enabled'] ?? true),
            ];
        }

        return $normalized;
    }

    private static function fromLegacyMessages(Store $store): array
    {
        $messages = is_array($store->whatsapp_bot_messages) ? $store->whatsapp_bot_messages : [];
        $defaults = static::defaults();

        $map = [
            self::ACTION_MENU => 'option_menu',
            self::ACTION_HOURS => 'option_hours',
            self::ACTION_ORDER => 'option_order',
            self::ACTION_HUMAN => 'option_human',
        ];

        foreach ($defaults as $index => $option) {
            $legacy = trim((string) ($messages[$map[$option['action']]] ?? ''));

            if ($legacy === '') {
                continue;
            }

            if (preg_match('/^(\d)\s*-\s*(.+)$/u', $legacy, $matches)) {
                $defaults[$index]['digit'] = $matches[1];
                $defaults[$index]['label'] = trim($matches[2]);
            } else {
                $defaults[$index]['label'] = preg_replace('/^\d+\s*-\s*/', '', $legacy) ?? $legacy;
            }
        }

        return $defaults;
    }

    private static function normalizeDigit(string $digit): string
    {
        $digit = preg_replace('/\D+/', '', $digit) ?? '';

        if ($digit === '' || (int) $digit < 1 || (int) $digit > 9) {
            return '';
        }

        return (string) (int) $digit;
    }
}
