<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Order;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'product_id',
        'quantity',
        'price',
        'subtotal',
        'options'
    ];

    protected $casts = [
        'options' => 'array', // Converte o JSON do banco em array automaticamente
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
