<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = [
        'user_id',
        'store_id',
        'customer_name',
        'customer_phone',
        'address',
        'address_number',
        'address_complement',
        'district',
        'latitude',
        'longitude',
        'payment_method',
        'change_for',
        'total_amount',
        'delivery_fee',
        'discount_amount',
        'status',
        'type',
        'fulfillment_type',
        'observation',
        'delivery_area_id',
        'coupon_id',
        'coupon_code',
        'coupon_description',
    ];

    protected $with = ['coupon'];

    protected $appends = [
        'status_label',
        'coupon_display_code',
        'coupon_display_description',
    ];

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

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function couponUsage()
    {
        return $this->hasOne(CouponUsage::class);
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

        return $labels[$this->status] ?? 'Status desconhecido';
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
