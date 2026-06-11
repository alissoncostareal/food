<?php

namespace App\Models;

use App\Models\DeliveryArea;
use App\Models\OperatingHour;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Store extends Model
{
    use HasFactory;

    public const TYPE_MATRIZ = 'matriz';
    public const TYPE_FILIAL = 'filial';

    public const PAYMENT_PIX = 'pix';
    public const PAYMENT_CASH = 'cash';
    public const PAYMENT_DEBIT_CARD = 'debit_card';
    public const PAYMENT_CREDIT_CARD = 'credit_card';
    public const PAYMENT_PIX_ONLINE = 'pix_online';
    public const PAYMENT_CREDIT_CARD_ONLINE = 'credit_card_online';

    public const PAYMENT_METHODS = [
        self::PAYMENT_PIX,
        self::PAYMENT_CASH,
        self::PAYMENT_DEBIT_CARD,
        self::PAYMENT_CREDIT_CARD,
        self::PAYMENT_PIX_ONLINE,
        self::PAYMENT_CREDIT_CARD_ONLINE,
    ];

    public const PAYMENT_METHOD_LABELS = [
        self::PAYMENT_PIX => 'Pix na entrega',
        self::PAYMENT_CASH => 'Dinheiro',
        self::PAYMENT_DEBIT_CARD => 'Cartão de débito',
        self::PAYMENT_CREDIT_CARD => 'Cartão de crédito',
        self::PAYMENT_PIX_ONLINE => 'Pix online',
        self::PAYMENT_CREDIT_CARD_ONLINE => 'Cartão online',
    ];

    protected $fillable = [
        'user_id',
        'store_type',
        'parent_store_id',
        'name',
        'description',
        'instagram_link',
        'whatsapp_number',
        'evolution_instance_name',
        'evolution_status',
        'evolution_connected_at',
        'evolution_last_error',
        'whatsapp_order_messages',
        'whatsapp_bot_enabled',
        'whatsapp_ai_enabled',
        'whatsapp_bot_welcome',
        'whatsapp_ai_faq',
        'primary_color',
        'secondary_color',
        'address',
        'latitude',
        'longitude',
        'is_open',
        'delivery_fee',
        'accepted_payment_methods',
        'online_payments_enabled',
        'logo_url',
        'banner_url',
        'business_hours',
        'slug',
        'plan_id',
        'plan_type',
        'subscription_status',
        'subscription_ends_at',
        'subscription_grace_ends_at',
        'complimentary_until',
        'complimentary_reason',
        'billing_email',
        'pagarme_customer_id',
        'pagarme_subscription_id',
        'pagarme_subscription_status',
        'pagarme_last_charge_id',
        'pagarme_last_charge_at',
        'pagarme_recipient_id',
        'payment_pix_provider_id',
        'ifood_merchant_id',
        'ifood_access_token',
        'ifood_refresh_token',
        'ifood_authorization_code_verifier',
        'ifood_token_expires_at',
        'ifood_integration_status',
        'ifood_last_error',
        'ifood_connected_at',
        'ifood_auto_confirm',
    ];

    protected $casts = [
        'business_hours' => 'array',
        'accepted_payment_methods' => 'array',
        'online_payments_enabled' => 'boolean',
        'whatsapp_order_messages' => 'array',
        'whatsapp_bot_enabled' => 'boolean',
        'whatsapp_ai_enabled' => 'boolean',
        'is_open' => 'boolean',
        'latitude' => 'float',
        'longitude' => 'float',
        'subscription_ends_at' => 'datetime',
        'subscription_grace_ends_at' => 'datetime',
        'complimentary_until' => 'datetime',
        'pagarme_last_charge_at' => 'datetime',
        'ifood_access_token' => 'encrypted',
        'ifood_refresh_token' => 'encrypted',
        'ifood_authorization_code_verifier' => 'encrypted',
        'ifood_token_expires_at' => 'datetime',
        'ifood_connected_at' => 'datetime',
        'ifood_auto_confirm' => 'boolean',
        'evolution_connected_at' => 'datetime',
    ];

    protected $hidden = [
        'ifood_access_token',
        'ifood_refresh_token',
        'ifood_authorization_code_verifier',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parentStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'parent_store_id');
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Store::class, 'parent_store_id')
            ->where('store_type', self::TYPE_FILIAL);
    }

    public function isMatriz(): bool
    {
        return $this->store_type === self::TYPE_MATRIZ
            || (blank($this->store_type) && blank($this->parent_store_id));
    }

    public function isFilial(): bool
    {
        return $this->store_type === self::TYPE_FILIAL;
    }

    public function maxStoresAllowed(): int
    {
        if (! $this->relationLoaded('plan')) {
            $this->load('plan');
        }

        return (int) ($this->plan?->max_stores ?? 1);
    }

    public function acceptedPaymentMethods(): array
    {
        $methods = $this->accepted_payment_methods;

        if (! is_array($methods) || $methods === []) {
            $methods = self::PAYMENT_METHODS;
        }

        $methods = array_values(array_intersect($methods, self::PAYMENT_METHODS));

        if (! $this->online_payments_enabled) {
            $methods = array_values(array_diff($methods, config('payments.online_methods', [])));
        }

        return $methods;
    }

    public function acceptsPaymentMethod(string $method): bool
    {
        return in_array($method, $this->acceptedPaymentMethods(), true);
    }

    public function onlineCardAvailable(): bool
    {
        if (! $this->online_payments_enabled) {
            return false;
        }

        if (! $this->acceptsPaymentMethod(self::PAYMENT_CREDIT_CARD_ONLINE)) {
            return false;
        }

        $provider = $this->relationLoaded('paymentPixProvider')
            ? $this->paymentPixProvider
            : $this->paymentPixProvider()->first();

        return $provider?->isConnected() && $provider->provider === 'pagarme';
    }

    public function members(): HasMany
    {
        return $this->hasMany(StoreMember::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(StoreInvitation::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function operatingHours(): HasMany
    {
        return $this->hasMany(OperatingHour::class);
    }

    public function productCategories(): HasMany
    {
        return $this->hasMany(ProductCategory::class)->orderBy('position', 'asc');
    }

    public function paymentPixProvider(): BelongsTo
    {
        return $this->belongsTo(StorePaymentProvider::class, 'payment_pix_provider_id');
    }

    public function paymentProviders(): HasMany
    {
        return $this->hasMany(StorePaymentProvider::class);
    }

    public function deliveryAreas(): HasMany
    {
        return $this->hasMany(DeliveryArea::class);
    }

    public function hasActiveSubscription(): bool
    {
        $this->ensureSubscriptionStateIsCurrent();

        if (in_array($this->subscription_status, ['suspended', 'canceled'], true)) {
            return false;
        }

        if ($this->subscription_status === 'complimentary') {
            return is_null($this->complimentary_until) || now()->lte($this->complimentary_until);
        }

        if (in_array($this->subscription_status, ['active', 'trial', 'past_due'], true)) {
            if (is_null($this->subscription_ends_at) || now()->lte($this->subscription_ends_at)) {
                return true;
            }

            return $this->isWithinPaymentGrace();
        }

        return false;
    }

    public function ensureSubscriptionStateIsCurrent(): void
    {
        if ($this->subscription_status !== 'complimentary') {
            return;
        }

        if (is_null($this->complimentary_until) || now()->lte($this->complimentary_until)) {
            return;
        }

        $this->forceFill([
            'subscription_status' => 'past_due',
            'subscription_ends_at' => $this->complimentary_until,
            'subscription_grace_ends_at' => null,
        ])->save();

        $this->syncBranchesSubscriptionFromMatriz();
    }

    /**
     * @return array{has_panel_access: bool, blocked_reason: ?string, blocked_label: ?string}
     */
    public function panelAccessState(): array
    {
        if ($this->hasActiveSubscription()) {
            return [
                'has_panel_access' => true,
                'blocked_reason' => null,
                'blocked_label' => null,
            ];
        }

        if ($this->subscription_status === 'suspended') {
            return [
                'has_panel_access' => false,
                'blocked_reason' => 'blocked_by_admin',
                'blocked_label' => 'Bloqueado manualmente',
            ];
        }

        if (
            $this->subscription_status === 'past_due'
            && filled($this->complimentary_reason)
        ) {
            return [
                'has_panel_access' => false,
                'blocked_reason' => 'complimentary_expired',
                'blocked_label' => 'Cortesia encerrada — pagamento necessário',
            ];
        }

        if ($this->subscription_status === 'canceled') {
            return [
                'has_panel_access' => false,
                'blocked_reason' => 'canceled',
                'blocked_label' => 'Assinatura cancelada',
            ];
        }

        if ($this->subscription_status === 'expired_trial') {
            return [
                'has_panel_access' => false,
                'blocked_reason' => 'expired_trial',
                'blocked_label' => 'Período de teste expirado',
            ];
        }

        if (
            in_array($this->subscription_status, ['active', 'trial', 'past_due'], true)
            && $this->subscription_ends_at
            && now()->gt($this->subscription_ends_at)
            && ! $this->isWithinPaymentGrace()
        ) {
            return [
                'has_panel_access' => false,
                'blocked_reason' => 'subscription_expired',
                'blocked_label' => 'Assinatura expirada',
            ];
        }

        return [
            'has_panel_access' => false,
            'blocked_reason' => 'other',
            'blocked_label' => 'Sem acesso ao painel',
        ];
    }

    public function isWithinPaymentGrace(): bool
    {
        if ($this->subscription_grace_ends_at && now()->lte($this->subscription_grace_ends_at)) {
            return true;
        }

        if (! $this->subscription_ends_at) {
            return false;
        }

        return now()->lte($this->subscription_ends_at->copy()->addDays(PlatformSetting::paymentGraceDays()));
    }

    public function paymentGraceEndsAt(): ?\Illuminate\Support\Carbon
    {
        if ($this->subscription_grace_ends_at) {
            return $this->subscription_grace_ends_at;
        }

        if ($this->subscription_ends_at) {
            return $this->subscription_ends_at->copy()->addDays(PlatformSetting::paymentGraceDays());
        }

        return null;
    }

    public function maxTeamMembersAllowed(): ?int
    {
        if (! $this->relationLoaded('plan')) {
            $this->load('plan');
        }

        return $this->plan?->max_team_members;
    }

    public function teamLimitReached(): bool
    {
        $limit = $this->maxTeamMembersAllowed();

        if (is_null($limit)) {
            return false;
        }

        return $this->members()->count() >= $limit;
    }

    public function matrizStore(): ?Store
    {
        if ($this->isMatriz()) {
            return $this;
        }

        return $this->parentStore;
    }

    public function syncBranchesSubscriptionFromMatriz(): void
    {
        if (! $this->isMatriz()) {
            return;
        }

        $payload = [
            'plan_id' => $this->plan_id,
            'plan_type' => $this->plan_type,
            'subscription_status' => $this->subscription_status,
            'subscription_ends_at' => $this->subscription_ends_at,
            'subscription_grace_ends_at' => $this->subscription_grace_ends_at,
            'complimentary_until' => $this->complimentary_until,
            'complimentary_reason' => $this->complimentary_reason,
        ];

        $this->branches()->update($payload);
    }

    public function hasFeature(string $feature): bool
    {
        if (!$this->relationLoaded('plan')) {
            $this->load('plan');
        }

        return $this->plan?->hasFeature($feature) ?? false;
    }

    public function canUseFeature(string $feature): bool
    {
        return $this->hasActiveSubscription() && $this->hasFeature($feature);
    }

    public function maxProductsAllowed(): ?int
    {
        if (!$this->relationLoaded('plan')) {
            $this->load('plan');
        }

        return $this->plan?->max_products;
    }

    public function productsLimitReached(): bool
    {
        $limit = $this->maxProductsAllowed();

        if (is_null($limit)) {
            return false;
        }

        return $this->products()->count() >= $limit;
    }

    public function isIfoodConnected(): bool
    {
        return $this->ifood_integration_status === 'connected'
            && filled($this->ifood_merchant_id);
    }

    public function whatsappConnectionPayload(): array
    {
        return [
            'instance_name' => $this->evolution_instance_name ?: $this->slug,
            'status' => $this->evolution_status ?: 'pending',
            'connected_at' => $this->evolution_connected_at?->toIso8601String(),
            'last_error' => $this->evolution_last_error,
            'whatsapp_number' => $this->whatsapp_number,
        ];
    }

    public function menuUrl(): string
    {
        return rtrim((string) config('app.menu_url'), '/').'/'.ltrim((string) $this->slug, '/');
    }

    public function whatsappAiFaqFilled(): bool
    {
        return mb_strlen(trim((string) $this->whatsapp_ai_faq)) >= (int) config('whatsapp.ai_faq_min_chars', 20);
    }

    public function whatsappAiEligible(): bool
    {
        return $this->canUseFeature('whatsapp_ai') && $this->whatsappAiFaqFilled();
    }

    public function whatsappAiActive(): bool
    {
        return $this->whatsappAiEligible()
            && (bool) $this->whatsapp_ai_enabled;
    }

    public function ifoodConnectionPayload(): array
    {
        return [
            'merchant_id' => $this->ifood_merchant_id,
            'status' => $this->ifood_integration_status ?: 'disconnected',
            'connected_at' => $this->ifood_connected_at?->toIso8601String(),
            'last_error' => $this->ifood_last_error,
            'has_token' => filled($this->ifood_access_token),
            'awaiting_authorization' => filled($this->ifood_authorization_code_verifier) && blank($this->ifood_access_token),
            'auto_confirm' => (bool) $this->ifood_auto_confirm,
        ];
    }

    public function getIsOpenNowAttribute(): bool
    {
        return $this->opening_status['is_open'] ?? false;
    }

    public function getOpeningStatusAttribute(): array
    {
        if (! $this->is_open) {
            $next = $this->findNextOpening();

            return [
                'is_open' => false,
                'message' => 'Loja fechada manualmente.',
                'next_opening' => $next,
                'accepts_orders_until' => null,
                'hours_hint' => $next ? 'Abre '.mb_strtolower((string) ($next['label'] ?? '')) : 'Fechada manualmente',
            ];
        }

        $now = $this->storeNow();
        $schedule = $this->getScheduleForDay($now->dayOfWeek);

        if (! $schedule) {
            $next = $this->findNextOpening($now);

            return [
                'is_open' => false,
                'message' => 'Fechado hoje',
                'next_opening' => $next,
                'accepts_orders_until' => null,
                'hours_hint' => $next ? 'Abre '.mb_strtolower((string) ($next['label'] ?? '')) : 'Fechado hoje',
            ];
        }

        $openAt = $now->copy()->startOfDay()->setTimeFromTimeString($schedule['opening_time']);
        $closeAt = $now->copy()->startOfDay()->setTimeFromTimeString($schedule['closing_time']);
        $closesLabel = $this->formatTimeLabel($schedule['closing_time']);

        if ($now->greaterThanOrEqualTo($openAt) && $now->lessThanOrEqualTo($closeAt)) {
            return [
                'is_open' => true,
                'message' => 'Aberto agora',
                'next_opening' => null,
                'accepts_orders_until' => $closesLabel,
                'hours_hint' => 'Aberto até '.$closesLabel,
                'opens_at' => $this->formatTimeLabel($schedule['opening_time']),
                'closes_at' => $closesLabel,
            ];
        }

        $next = $this->findNextOpening($now);

        return [
            'is_open' => false,
            'message' => 'Fechado no momento',
            'next_opening' => $next,
            'accepts_orders_until' => null,
            'hours_hint' => $next ? 'Abre '.mb_strtolower((string) ($next['label'] ?? '')) : 'Fechado no momento',
        ];
    }

    private function storeTimezone(): string
    {
        return (string) config('app.timezone', 'America/Fortaleza');
    }

    private function storeNow(): \Illuminate\Support\Carbon
    {
        return now($this->storeTimezone());
    }

    private function normalizeTimeValue(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('H:i:s');
        }

        $time = trim((string) $value);

        if ($time === '') {
            return '00:00:00';
        }

        if (preg_match('/^\d{2}:\d{2}$/', $time)) {
            return $time.':00';
        }

        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) {
            return $time;
        }

        return '00:00:00';
    }

    private function formatTimeLabel(string $time): string
    {
        return substr($this->normalizeTimeValue($time), 0, 5);
    }

    private function getScheduleForDay(int $dayOfWeek): ?array
    {
        $row = $this->operatingHours()
            ->where('day_of_week', $dayOfWeek)
            ->first();

        if ($row) {
            if ($row->is_closed) {
                return null;
            }

            return [
                'opening_time' => $this->normalizeTimeValue($row->opening_time),
                'closing_time' => $this->normalizeTimeValue($row->closing_time),
            ];
        }

        return $this->resolveBusinessHoursSchedule($dayOfWeek);
    }

    private function findNextOpening(?\Illuminate\Support\Carbon $from = null): ?array
    {
        $from = $from ?: $this->storeNow();

        for ($offset = 0; $offset < 7; $offset++) {
            $date = $from->copy()->startOfDay()->addDays($offset);
            $schedule = $this->getScheduleForDay($date->dayOfWeek);

            if (! $schedule) {
                continue;
            }

            $openAt = $date->copy()->setTimeFromTimeString($schedule['opening_time']);
            $closeAt = $date->copy()->setTimeFromTimeString($schedule['closing_time']);

            if ($offset === 0) {
                if ($from->lt($openAt)) {
                    return $this->formatNextOpeningPayload($openAt, $schedule, 'later_today');
                }

                if ($from->lte($closeAt)) {
                    continue;
                }

                continue;
            }

            return $this->formatNextOpeningPayload($openAt, $schedule, $offset === 1 ? 'tomorrow' : 'weekday');
        }

        return null;
    }

    private function formatNextOpeningPayload(
        \Illuminate\Support\Carbon $openAt,
        array $schedule,
        string $kind
    ): array {
        $dayNames = ['domingo', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado'];

        $label = match ($kind) {
            'later_today' => 'Hoje às '.$openAt->format('H:i'),
            'tomorrow' => 'Amanhã às '.$openAt->format('H:i'),
            default => ucfirst($dayNames[$openAt->dayOfWeek]).' às '.$openAt->format('H:i'),
        };

        return [
            'at' => $openAt->toIso8601String(),
            'opens_at' => $openAt->format('H:i'),
            'closes_at' => $this->formatTimeLabel($schedule['closing_time']),
            'label' => $label,
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    private function resolveBusinessHoursSchedule(int $dayOfWeek): ?array
    {
        $hours = $this->business_hours;

        if (! is_array($hours)) {
            return null;
        }

        $dayKeys = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
        $key = $dayKeys[$dayOfWeek] ?? null;
        $day = $key ? ($hours[$key] ?? null) : null;

        if (! is_array($day) || ! empty($day['closed'])) {
            return null;
        }

        $open = strlen((string) ($day['open'] ?? '')) === 5
            ? ($day['open'] . ':00')
            : (string) ($day['open'] ?? '08:00:00');
        $close = strlen((string) ($day['close'] ?? '')) === 5
            ? ($day['close'] . ':00')
            : (string) ($day['close'] ?? '22:00:00');

        return [
            'opening_time' => $open,
            'closing_time' => $close,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function ($store) {
            if (empty($store->slug)) {
                $store->slug = Str::slug($store->name);
            } else {
                $store->slug = Str::slug($store->slug);
            }
        });

        static::updating(function ($store) {
            if ($store->isDirty('slug')) {
                $store->slug = strtolower(trim($store->slug));
            } elseif ($store->isDirty('name') && empty($store->slug)) {
                $store->slug = Str::slug($store->name, '-');
            }
        });
    }
}
