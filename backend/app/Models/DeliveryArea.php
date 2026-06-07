<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryArea extends Model
{
    protected $fillable = [
        'store_id',
        'district_name',
        'fee',
        'estimated_time',
        'is_active'
    ];

    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}
