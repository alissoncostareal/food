<?php

namespace App\Models;

use App\Support\IntegrationErrorReporter;
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
        'open_outside_hours',
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
        'pre_courtesy_plan_id',
        'pre_courtesy_subscription_status',
        'pre_courtesy_subscription_ends_at',
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
        'open_outside_hours' => 'boolean',
        'latitude' => 'float',
        'longitude' => 'float',
        'subscription_ends_at' => 'datetime',
        'subscription_grace_ends_at' => 'datetime',
        'complimentary_until' => 'datetime',
        'pre_courtesy_subscription_ends_at' => 'datetime',
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
        return $this->hasMany(ProductCategory::class)->orderBy('position', 'asc')->orderBy('id', 'asc');
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

    public function deliveryDrivers(): HasMany
    {
        return $this->hasMany(DeliveryDriver::class);
    }

    public function hasActiveSubscription(): bool
    {
        $this->ensureSubscriptionStateIsCurrent();

        if (in_array($this->subscription_status, ['suspended', 'canceled'], true)) {
            return false;
        }

        if (
            $this->subscription_status === 'past_due'
            && (filled($this->complimentary_reason) || filled($this->complimentary_until))
        ) {
            return false;
        }

        if ($this->subscription_status === 'past_due' && blank($this->pagarme_subscription_id)) {
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

        $this->finalizeCourtesy();
    }

    public function shouldRestoreTrialAfterCourtesy(): bool
    {
        if (filled($this->pagarme_subscription_id)) {
            return false;
        }

        if ($this->pre_courtesy_subscription_status === 'trial') {
            return true;
        }

        if ($this->pre_courtesy_plan_id) {
            $trialPlanId = Plan::query()->where('slug', 'trial')->value('id');

            return $trialPlanId && (int) $this->pre_courtesy_plan_id === (int) $trialPlanId;
        }

        return filled($this->complimentary_reason)
            || filled($this->complimentary_until)
            || $this->subscription_status === 'past_due';
    }

    public function finalizeCourtesy(): void
    {
        if ($this->shouldRestoreTrialAfterCourtesy()) {
            $this->restoreTrialAfterCourtesy();

            return;
        }

        $this->forceFill([
            'subscription_status' => 'past_due',
            'subscription_ends_at' => now()->subSecond(),
            'subscription_grace_ends_at' => null,
        ])->save();

        $this->syncBranchesSubscriptionFromMatriz();
    }

    public function restoreTrialAfterCourtesy(): void
    {
        $trialPlan = Plan::query()
            ->where('slug', 'trial')
            ->where('is_active', true)
            ->first();

        $endsAt = $this->pre_courtesy_subscription_ends_at;

        if (! $endsAt || now()->gte($endsAt)) {
            $endsAt = now()->addDays(7);
        }

        $this->forceFill([
            'plan_id' => $this->pre_courtesy_plan_id ?? $trialPlan?->id ?? $this->plan_id,
            'plan_type' => $trialPlan?->slug ?? 'trial',
            'subscription_status' => 'trial',
            'subscription_ends_at' => $endsAt,
            'subscription_grace_ends_at' => null,
            'complimentary_until' => null,
            'complimentary_reason' => null,
            'pre_courtesy_plan_id' => null,
            'pre_courtesy_subscription_status' => null,
            'pre_courtesy_subscription_ends_at' => null,
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
            && (filled($this->complimentary_reason) || filled($this->complimentary_until))
        ) {
            return [
                'has_panel_access' => false,
                'blocked_reason' => 'complimentary_expired',
                'blocked_label' => 'Cortesia encerrada — pagamento necessário',
            ];
        }

        if ($this->subscription_status === 'past_due' && blank($this->pagarme_subscription_id)) {
            return [
                'has_panel_access' => false,
                'blocked_reason' => 'payment_required',
                'blocked_label' => 'Pagamento necessário',
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
        if (! $this->subscription_ends_at) {
            return false;
        }

        if (now()->lte($this->subscription_ends_at)) {
            return false;
        }

        $graceEnds = $this->subscription_grace_ends_at
            ?? $this->subscription_ends_at->copy()->addDays(PlatformSetting::paymentGraceDays());

        return now()->lte($graceEnds);
    }

    public function paymentGraceEndsAt(): ?\Illuminate\Support\Carbon
    {
        if (! $this->isWithinPaymentGrace()) {
            return null;
        }

        return $this->subscription_grace_ends_at
            ?? $this->subscription_ends_at?->copy()->addDays(PlatformSetting::paymentGraceDays());
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
        $error = IntegrationErrorReporter::parseStored($this->evolution_last_error);

        return [
            'instance_name' => $this->evolution_instance_name ?: $this->slug,
            'status' => $this->evolution_status ?: 'pending',
            'connected_at' => $this->evolution_connected_at?->toIso8601String(),
            'last_error' => $error['message'],
            'error_ref' => $error['error_ref'],
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
        $accessToken = $this->readEncryptedAttribute('ifood_access_token');
        $authorizationVerifier = $this->readEncryptedAttribute('ifood_authorization_code_verifier');
        $error = IntegrationErrorReporter::parseStored($this->ifood_last_error);

        return [
            'merchant_id' => $this->ifood_merchant_id,
            'status' => $this->ifood_integration_status ?: 'disconnected',
            'connected_at' => $this->ifood_connected_at?->toIso8601String(),
            'last_error' => $error['message'],
            'error_ref' => $error['error_ref'],
            'has_token' => filled($accessToken),
            'awaiting_authorization' => filled($authorizationVerifier) && blank($accessToken),
            'auto_confirm' => (bool) $this->ifood_auto_confirm,
        ];
    }

    private function readEncryptedAttribute(string $key): ?string
    {
        if (blank($this->getRawOriginal($key))) {
            return null;
        }

        try {
            $value = $this->getAttribute($key);

            return filled($value) ? (string) $value : null;
        } catch (\Illuminate\Contracts\Encryption\DecryptException) {
            return null;
        }
    }

    public function getIsOpenNowAttribute(): bool
    {
        if (! $this->is_open) {
            return false;
        }

        if ($this->isWithinScheduledHours()) {
            return true;
        }

        return (bool) $this->open_outside_hours;
    }

    public function isWithinScheduledHours(?\Illuminate\Support\Carbon $now = null): bool
    {
        $now = $now ?: $this->storeNow();

        foreach ([0, -1] as $dayOffset) {
            $day = $now->copy()->startOfDay()->addDays($dayOffset);
            $schedule = $this->getScheduleForDay($day->dayOfWeek);

            if (! $schedule) {
                continue;
            }

            [$openAt, $closeAt] = $this->scheduleWindow($schedule, $day);

            if ($now->greaterThanOrEqualTo($openAt) && $now->lessThanOrEqualTo($closeAt)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: \Illuminate\Support\Carbon, 1: \Illuminate\Support\Carbon}
     */
    private function scheduleWindow(array $schedule, \Illuminate\Support\Carbon $day): array
    {
        $openAt = $day->copy()->setTimeFromTimeString($schedule['opening_time']);
        $closeAt = $day->copy()->setTimeFromTimeString($schedule['closing_time']);

        if ($closeAt->lessThanOrEqualTo($openAt)) {
            $closeAt->addDay();
        }

        return [$openAt, $closeAt];
    }

    public function getOpeningStatusAttribute(): array
    {
        if (! $this->is_open) {
            $next = $this->findNextOpening();

            return [
                'is_open' => false,
                'within_scheduled_hours' => false,
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
            $acceptsOrders = (bool) $this->open_outside_hours;

            return [
                'is_open' => $acceptsOrders,
                'within_scheduled_hours' => false,
                'message' => $acceptsOrders ? 'Aberto agora' : 'Fechado hoje',
                'next_opening' => $next,
                'accepts_orders_until' => null,
                'hours_hint' => $acceptsOrders
                    ? 'Aberto fora do horário cadastrado'
                    : ($next
                        ? 'Fechado hoje · abre '.mb_strtolower((string) ($next['label'] ?? ''))
                        : 'Fechado hoje'),
            ];
        }

        $closesLabel = $this->formatTimeLabel($schedule['closing_time']);
        $opensLabel = $this->formatTimeLabel($schedule['opening_time']);

        if ($this->isWithinScheduledHours($now)) {
            return [
                'is_open' => true,
                'within_scheduled_hours' => true,
                'message' => 'Aberto agora',
                'next_opening' => null,
                'accepts_orders_until' => $closesLabel,
                'hours_hint' => 'Aberto até '.$closesLabel,
                'opens_at' => $opensLabel,
                'closes_at' => $closesLabel,
            ];
        }

        $next = $this->findNextOpening($now);
        $acceptsOrders = (bool) $this->open_outside_hours;

        return [
            'is_open' => $acceptsOrders,
            'within_scheduled_hours' => false,
            'message' => $acceptsOrders ? 'Aberto agora' : 'Fechado no momento',
            'next_opening' => $next,
            'accepts_orders_until' => null,
            'hours_hint' => $acceptsOrders
                ? 'Aberto fora do horário cadastrado'
                : ($next
                    ? 'Fora do horário · abre '.mb_strtolower((string) ($next['label'] ?? ''))
                    : 'Fechado no momento'),
            'opens_at' => $opensLabel,
            'closes_at' => $closesLabel,
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
        if (is_array($this->business_hours) && $this->business_hours !== []) {
            return $this->resolveBusinessHoursSchedule($dayOfWeek);
        }

        $row = $this->operatingHours()
            ->where('day_of_week', $dayOfWeek)
            ->first();

        if (! $row || $row->is_closed) {
            return null;
        }

        return [
            'opening_time' => $this->normalizeTimeValue($row->opening_time),
            'closing_time' => $this->normalizeTimeValue($row->closing_time),
        ];
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

        if (! empty($day['all_day'])) {
            return [
                'opening_time' => '00:00:00',
                'closing_time' => '23:59:00',
            ];
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

    /**
     * @return array<string, array{open: string, close: string, closed: bool, all_day: bool}>
     */
    public static function defaultBusinessHours(): array
    {
        $weekday = static fn () => [
            'open' => '08:00',
            'close' => '22:00',
            'closed' => false,
            'all_day' => false,
        ];

        return [
            'monday' => $weekday(),
            'tuesday' => $weekday(),
            'wednesday' => $weekday(),
            'thursday' => $weekday(),
            'friday' => $weekday(),
            'saturday' => $weekday(),
            'sunday' => [
                'open' => '08:00',
                'close' => '18:00',
                'closed' => true,
                'all_day' => false,
            ],
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>|null  $businessHours
     */
    public function syncOperatingHoursFromBusinessHours(?array $businessHours = null): void
    {
        $businessHours ??= is_array($this->business_hours) ? $this->business_hours : [];

        if ($businessHours === []) {
            return;
        }

        $dayMap = [
            'sunday' => 0,
            'monday' => 1,
            'tuesday' => 2,
            'wednesday' => 3,
            'thursday' => 4,
            'friday' => 5,
            'saturday' => 6,
        ];

        foreach ($dayMap as $key => $dayOfWeek) {
            $hours = $businessHours[$key] ?? null;

            if (! is_array($hours)) {
                continue;
            }

            $allDay = (bool) ($hours['all_day'] ?? false);

            $this->operatingHours()->updateOrCreate(
                ['day_of_week' => $dayOfWeek],
                [
                    'opening_time' => $allDay ? '00:00' : ($hours['open'] ?? '08:00'),
                    'closing_time' => $allDay ? '23:59' : ($hours['close'] ?? '22:00'),
                    'is_closed' => (bool) ($hours['closed'] ?? false),
                ]
            );
        }
    }

    protected static function booted(): void
    {
        static::creating(function ($store) {
            if (empty($store->slug)) {
                $store->slug = Str::slug($store->name);
            } else {
                $store->slug = Str::slug($store->slug);
            }

            if ($store->is_open === null) {
                $store->is_open = true;
            }

            if (blank($store->business_hours)) {
                $store->business_hours = self::defaultBusinessHours();
            }
        });

        static::created(function (Store $store) {
            if (is_array($store->business_hours) && $store->business_hours !== []) {
                $store->syncOperatingHoursFromBusinessHours();
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
