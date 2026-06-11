<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreMember extends Model
{
    public const ROLE_MANAGER = 'manager';
    public const ROLE_STAFF = 'staff';

    protected $fillable = [
        'store_id',
        'user_id',
        'role',
        'invited_by_user_id',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }
}
