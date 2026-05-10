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
    protected $fillable =
    [
        'user_id',
        'store_id',
        'total_amount',
        'delivery_fee',
        'status',
        'type',
        'address',
        'payment_method',
        'payment_status',
        'delivery_area_id'
    ];

    protected $appends = ['status_label'];

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function deliveryArea(): BelongsTo
    {
        return $this->belongsTo(DeliveryArea::class);
    }

    public function getStatusLabelAttribute()
    {
        $labels = [
            'pending' => 'Pendente',
            'preparing' => 'Preparando',
            'ready' => 'Pronto',
            'shipped' => 'Enviado',
            'delivered' => 'Entregue',
            'canceled' => 'Cancelado'
        ];

        return $labels[$this->status] ?? $this->status;
    }
}
