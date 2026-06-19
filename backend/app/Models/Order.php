<?php

namespace App\Models;

use App\Services\OrderDisplayNumberService;
use App\Services\OrderPixPaymentService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    public const ACTIONABLE_PENDING_HOURS = 24;

    protected $fillable = [
        'user_id',
        'store_id',
        'display_number',
        'order_source',
        'ifood_order_id',
        'ifood_display_id',
        'ifood_confirmed_at',
        'ifood_order_type',
        'ifood_delivered_by',
        'ifood_delivery_localizer',
        'customer_name',
        'customer_phone',
        'address',
        'address_number',
        'address_complement',
        'district',
        'latitude',
        'longitude',
        'payment_method',
        'payment_provider',
        'payment_external_order_id',
        'payment_external_charge_id',
        'payment_status',
        'payment_channel',
        'pagarme_order_id',
        'pagarme_charge_id',
        'pix_qr_code',
        'pix_qr_code_url',
        'payment_expires_at',
        'payment_paid_at',
        'payment_refunded_at',
        'change_for',
        'total_amount',
        'delivery_fee',
        'discount_amount',
        'status',
        'stock_restored_at',
        'type',
        'fulfillment_type',
        'observation',
        'delivery_area_id',
        'delivery_driver_id',
        'coupon_id',
        'coupon_code',
        'coupon_description',
        'whatsapp_url',
        'sent_to_whatsapp_at',
    ];

    protected $casts = [
        'ifood_confirmed_at' => 'datetime',
        'payment_expires_at' => 'datetime',
        'payment_paid_at' => 'datetime',
        'payment_refunded_at' => 'datetime',
        'stock_restored_at' => 'datetime',
    ];

    protected $with = ['coupon'];

    protected $appends = [
        'status_label',
        'display_code',
        'coupon_display_code',
        'coupon_display_description',
        'needs_attention',
        'is_finished',
    ];

    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            if (filled($order->display_number)) {
                return;
            }

            if ($order->order_source === 'ifood' && filled($order->ifood_display_id)) {
                $order->display_number = app(OrderDisplayNumberService::class)
                    ->fromIfoodDisplayId($order->ifood_display_id);

                return;
            }

            $order->display_number = app(OrderDisplayNumberService::class)->assignNext($order);
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function deliveryArea(): BelongsTo
    {
        return $this->belongsTo(DeliveryArea::class, 'delivery_area_id');
    }

    public function deliveryDriver(): BelongsTo
    {
        return $this->belongsTo(DeliveryDriver::class, 'delivery_driver_id');
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function couponUsage()
    {
        return $this->hasOne(CouponUsage::class);
    }

    public function scopeActionablePending($query)
    {
        $hours = (int) config('orders.actionable_pending_hours', self::ACTIONABLE_PENDING_HOURS);

        return $query
            ->where('status', 'pending')
            ->where('created_at', '>=', now()->subHours($hours))
            ->where(function ($query) {
                $query->whereNull('payment_status')
                    ->orWhereIn('payment_status', [
                        OrderPixPaymentService::STATUS_NOT_REQUIRED,
                        OrderPixPaymentService::STATUS_PAID,
                    ]);
            });
    }

    public function scopeForMerchantStatus($query, ?string $status)
    {
        if (blank($status) || $status === 'all') {
            return $query;
        }

        if ($status === 'canceled') {
            return $query->whereIn('status', ['canceled', 'cancelled']);
        }

        return $query->where('status', $status);
    }

    public function getNeedsAttentionAttribute(): bool
    {
        if (($this->attributes['status'] ?? null) !== 'pending' || blank($this->created_at)) {
            return false;
        }

        $hours = (int) config('orders.actionable_pending_hours', self::ACTIONABLE_PENDING_HOURS);

        return $this->created_at->gte(now()->subHours($hours));
    }

    public function getIsFinishedAttribute(): bool
    {
        return in_array($this->attributes['status'] ?? null, ['delivered', 'canceled', 'cancelled'], true);
    }

    public function getDisplayCodeAttribute(): string
    {
        if ($this->order_source === 'ifood' && filled($this->ifood_display_id)) {
            return (string) $this->ifood_display_id;
        }

        $number = $this->attributes['display_number'] ?? null;

        if (filled($number)) {
            return str_pad((string) $number, 4, '0', STR_PAD_LEFT);
        }

        return str_pad((string) $this->id, 4, '0', STR_PAD_LEFT);
    }

    public function getStatusLabelAttribute(): string
    {
        $labels = [
            'pending' => 'Pedido recebido',
            'preparing' => 'Em preparo',
            'ready' => 'Pronto para entrega',
            'shipped' => 'Saiu para entrega',
            'delivered' => 'Pedido entregue',
            'canceled' => 'Pedido cancelado',
        ];

        return $labels[$this->attributes['status'] ?? ''] ?? 'Status desconhecido';
    }

    public function getCouponDisplayCodeAttribute(): ?string
    {
        return $this->coupon?->code ?? $this->attributes['coupon_code'] ?? null;
    }

    public function getCouponDisplayDescriptionAttribute(): ?string
    {
        return $this->coupon?->description ?? $this->attributes['coupon_description'] ?? null;
    }
}
