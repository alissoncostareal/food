<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LandingLead extends Model
{
    protected $fillable = [
        'name',
        'email',
        'phone',
        'store_name',
        'message',
    ];
}
