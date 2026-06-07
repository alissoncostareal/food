<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerOtp extends Model
{
    protected $fillable = [
        'user_id',
        'phone',
        'code',
        'expires_at',
        'verified_at'
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'verified_at' => 'datetime'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
