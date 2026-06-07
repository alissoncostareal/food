<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Store;
use App\Models\OrderItem;
use App\Models\DeliveryArea;

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
    ];

    protected $with = ['coupon'];
    protected $appends = ['status_label', 'coupon_code'];

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function deliveryArea(): BelongsTo
    {
        return $this->belongsTo(DeliveryArea::class, 'delivery_area_id');
    }

    public function getStatusLabelAttribute()
    {
        $labels = [
            'pending' => 'Pendente',
            'preparing' => 'Na Cozinha',
            'ready' => 'Pronto',
            'shipped' => 'Em Entrega',
            'delivered' => 'Entregue',
            'canceled' => 'Cancelado',
        ];

        return $labels[$this->status] ?? $this->status;
    }
    public function getCouponCodeAttribute()
    {
        return $this->coupon ? $this->coupon->code : null;
    }
    public function coupon()
    {
        return $this->belongsTo(Coupon::class);
    }
    public function couponUsage()
    {
        return $this->hasOne(CouponUsage::class);
    }
}
