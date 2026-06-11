<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\MerchantResetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role', 'phone', 'address', 'address_number', 'district', 'address_complement', 'current_store_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    public const ROLE_CUSTOMER = 'customer';
    public const ROLE_STORE_OWNER = 'store_owner';
    public const ROLE_STORE_STAFF = 'store_staff';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_SUPER_ADMIN = 'super_admin';

    public const ROLES = [
        self::ROLE_CUSTOMER,
        self::ROLE_STORE_OWNER,
        self::ROLE_STORE_STAFF,
        self::ROLE_ADMIN,
        self::ROLE_SUPER_ADMIN,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function store(): HasOne
    {
        return $this->hasOne(Store::class)->where(function ($query) {
            $query->where('store_type', Store::TYPE_MATRIZ)
                ->orWhereNull('store_type');
        })->whereNull('parent_store_id');
    }

    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }

    public function preferences(): HasOne
    {
        return $this->hasOne(UserPreference::class);
    }

    public function currentStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'current_store_id');
    }

    public function storeMemberships(): HasMany
    {
        return $this->hasMany(StoreMember::class);
    }

    public function otps()
    {
        return $this->hasMany(CustomerOtp::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'user_id');
    }

    public function hasRole(string|array $roles): bool
    {
        return in_array($this->role, (array) $roles, true);
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(self::ROLE_SUPER_ADMIN);
    }

    public function isStoreOwner(): bool
    {
        return $this->hasRole(self::ROLE_STORE_OWNER);
    }

    public function isStoreStaff(): bool
    {
        return $this->hasRole(self::ROLE_STORE_STAFF);
    }

    public function isMerchantUser(): bool
    {
        return $this->isStoreOwner() || $this->isStoreStaff();
    }

    public function ownsStore(Store|int $store): bool
    {
        $storeId = $store instanceof Store ? $store->id : $store;

        return Store::query()
            ->whereKey($storeId)
            ->where('user_id', $this->id)
            ->exists();
    }

    public function needsStoreOnboarding(): bool
    {
        if (! $this->isStoreOwner()) {
            return false;
        }

        return ! Store::query()->where('user_id', $this->id)->exists();
    }

    public function canAccessStore(Store|int $store): bool
    {
        $storeId = $store instanceof Store ? $store->id : $store;

        if ($this->ownsStore($storeId)) {
            return true;
        }

        return $this->storeMemberships()->where('store_id', $storeId)->exists();
    }

    public function canManageStoreTeam(Store|int $store): bool
    {
        return $this->isStoreOwner() && $this->ownsStore($store);
    }

    public function resolveMerchantStore(): ?Store
    {
        return app(\App\Services\MerchantStoreResolver::class)->resolve($this);
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new MerchantResetPasswordNotification($token));
    }
}
