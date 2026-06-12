<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class PlatformSetting extends Model
{
    protected $fillable = [
        'key',
        'value',
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = Cache::rememberForever("platform_setting:{$key}", function () use ($key) {
            return static::query()->where('key', $key)->value('value');
        });

        return $value ?? $default;
    }

    public static function getInt(string $key, int $default): int
    {
        return max(0, (int) static::get($key, $default));
    }

    public static function getFloat(string $key, float $default): float
    {
        return max(0, (float) static::get($key, $default));
    }

    public static function set(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => (string) $value]
        );

        Cache::forget("platform_setting:{$key}");
    }

    public static function paymentGraceDays(): int
    {
        return static::getInt('payment_grace_days', 7);
    }

    public static function extraBranchMonthlyPrice(): float
    {
        return static::getFloat('extra_branch_monthly_price', 0);
    }

    public static function editableSettings(): array
    {
        return [
            'payment_grace_days' => [
                'label' => 'Dias de tolerância para pagamento',
                'type' => 'integer',
                'default' => 7,
                'min' => 0,
                'max' => 90,
                'hint' => 'Após vencer, a loja permanece ativa por este período antes do bloqueio.',
            ],
            'extra_branch_monthly_price' => [
                'label' => 'Preço mensal por filial extra',
                'type' => 'decimal',
                'default' => 49.90,
                'min' => 0,
                'hint' => 'Valor cobrado além do plano quando o lojista ultrapassa o limite de lojas inclusas.',
            ],
        ];
    }

    public static function publicValues(): array
    {
        $values = [];

        foreach (array_keys(static::editableSettings()) as $key) {
            $meta = static::editableSettings()[$key];
            $values[$key] = match ($meta['type']) {
                'integer' => static::getInt($key, (int) $meta['default']),
                'decimal' => static::getFloat($key, (float) $meta['default']),
                default => static::get($key, $meta['default']),
            };
        }

        return $values;
    }
}
